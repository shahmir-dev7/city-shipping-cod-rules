// Runs against a FRESH site (PHP 8.3, WooCommerce from wordpress.org) with the release zip installed.
// Rules are added through the admin screen; carts and orders go through WooCommerce's real Store API.
const { test, expect, request: pwRequest } = require( '@playwright/test' );
const { execSync } = require( 'child_process' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );

const root = path.resolve( __dirname, '../../../..' );
const shots = path.resolve( __dirname, '../../assets' );
// Loading WooCommerce on every WP-CLI call is slow here, so skip plugins unless the command needs them.
const wp = ( args, needsPlugins = false ) =>
	execSync( `npx wp-env run cli wp ${ args }${ needsPlugins ? '' : ' --skip-plugins --skip-themes' }`, { cwd: path.join( root, 'pkgtest' ), encoding: 'utf8' } );

test.describe.configure( { mode: 'serial' } );

let productId;

test.beforeAll( () => {
	wp( 'plugin install /tmp/city-shipping-cod-rules.zip --force --activate', true );
	const cli = execSync( 'docker ps --format "{{.Names}}"', { encoding: 'utf8' } ).split( /\r?\n/ ).find( ( n ) => /pkgtest.*-cli-1$/.test( n ) && ! /tests/.test( n ) );
	execSync( `docker cp "${ path.join( __dirname, 'fixtures', 'setup.php' ) }" ${ cli }:/tmp/cityrules-setup.php` );
	const out = wp( 'eval-file /tmp/cityrules-setup.php', true );
	productId = Number( /PRODUCT_ID=(\d+)/.exec( out )[ 1 ] );
	wp( 'db query "DELETE FROM wp_cityrules_rules"' );
	wp( 'option delete cityrules_default_cod_fee' );
} );

const tableCount = () => Number( wp( 'db query "SELECT COUNT(*) FROM wp_cityrules_rules" --skip-column-names' ).trim() );

/** A guest cart through the Store API. */
async function newCart( baseURL ) {
	const ctx = await pwRequest.newContext( { baseURL } );
	const first = await ctx.get( '/wp-json/wc/store/v1/cart' );
	const state = { ctx, nonce: first.headers().nonce, token: first.headers()[ 'cart-token' ] };
	const headers = () => ( { Nonce: state.nonce, 'Cart-Token': state.token } );
	const keep = ( r ) => { state.nonce = r.headers().nonce || state.nonce; return r; };
	state.api = async ( method, url, data ) => {
		const r = keep( await ctx[ method ]( `/wp-json/wc/store/v1${ url }`, { headers: headers(), data } ) );
		return { status: r.status(), body: await r.json().catch( () => ( {} ) ) };
	};
	return state;
}

const address = ( city, extra = {} ) => ( { first_name: 'Ali', last_name: 'Khan', address_1: '1 Test Road', city, state: 'PB', postcode: '54000', country: 'PK', email: 'buyer@example.com', phone: '03001234567', ...extra } );

/** Add the product, set the destination city; return the cart response. */
async function cartFor( baseURL, city, quantity = 1 ) {
	const cart = await newCart( baseURL );
	const added = await cart.api( 'post', '/cart/add-item', { id: productId, quantity } );
	expect( [ 200, 201 ], 'add to cart: ' + JSON.stringify( added.body ).slice( 0, 200 ) ).toContain( added.status );
	const updated = await cart.api( 'post', '/cart/update-customer', { billing_address: address( city ), shipping_address: address( city ) } );
	expect( [ 200, 201 ], 'update customer: ' + JSON.stringify( updated.body ).slice( 0, 200 ) ).toContain( updated.status );
	return { cart, body: updated.body };
}

const rateCost = ( body ) => {
	const rates = ( body.shipping_rates || [] ).flatMap( ( pkg ) => pkg.shipping_rates || [] );
	return rates.length ? Number( rates[ 0 ].price ) / 100 : null;
};

test( 'rules, shipping, COD gate, COD fee, order, uninstall, clean database', async ( { page, baseURL } ) => {
	// The site can be slow: wait for the form to settle, check each value landed, then wait for the dashboard.
	await page.goto( '/wp-login.php', { waitUntil: 'networkidle' } );
	await page.locator( '#user_login' ).fill( 'admin' );
	await page.locator( '#user_pass' ).fill( 'password' );
	await expect( page.locator( '#user_login' ) ).toHaveValue( 'admin' );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/, { timeout: 60000 } );
	await expect( page.locator( '#wpadminbar' ) ).toBeVisible();

	// Add two rules through the admin screen.
	const addRule = async ( fields ) => {
		await page.goto( '/wp-admin/admin.php?page=cityrules' );
		for ( const [ name, value ] of Object.entries( fields ) ) {
			if ( name === 'cod' ) {
				await page.selectOption( '#cityrules-cod', value );
			} else {
				await page.fill( `#cityrules-${ name }`, value );
			}
		}
		await page.getByRole( 'button', { name: 'Save rule' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Rule saved.' );
	};
	await addRule( { city: 'Lahore', country: 'PK', shipping_cost: '200', free_over: '3000', cod: 'yes', cod_fee: '50' } );
	await addRule( { city: 'Remote Village', country: 'PK', shipping_cost: '400', cod: 'no' } );

	// Import a third city by CSV, and a store-wide default COD fee.
	const csv = path.join( os.tmpdir(), 'cityrules.csv' );
	fs.writeFileSync( csv, 'country,city,shipping_cost,free_over,cod,cod_fee\nPK,Karachi,350,,default,\nPK,,10,,,\n' );
	await page.goto( '/wp-admin/admin.php?page=cityrules' );
	await page.setInputFiles( '#cityrules-csv', csv );
	await page.getByRole( 'button', { name: 'Import' } ).click();
	await expect( page.locator( '.notice-success' ) ).toContainText( 'Imported 1 rules, skipped 1' );
	await page.fill( '#cityrules_default_cod_fee', '25' );
	await page.getByRole( 'button', { name: 'Save', exact: true } ).click();
	await expect( page.locator( '#cityrules_default_cod_fee' ) ).toHaveValue( '25' );
	await expect( page.locator( 'tr', { hasText: 'Karachi' } ) ).toBeVisible();
	await page.waitForTimeout( 800 );
	await page.screenshot( { path: path.join( shots, 'screenshot-1.png' ), fullPage: true } );

	// Export returns the rules as CSV.
	const href = await page.getByRole( 'link', { name: 'Export CSV' } ).getAttribute( 'href' );
	const exported = await ( await page.request.get( href ) ).text();
	expect( exported ).toContain( 'Lahore' );
	expect( exported ).toContain( 'Remote Village' );

	// Lahore: shipping 200, COD offered.
	let { cart, body } = await cartFor( baseURL, 'lahore' ); // Typed in lower case on purpose.
	expect( rateCost( body ), 'Lahore shipping' ).toBe( 200 );
	expect( body.payment_methods ).toContain( 'cod' );

	// Choose COD: the fee for Lahore (50, not the store default 25) is added to the cart.
	const patched = await cart.api( 'patch', '/checkout', { payment_method: 'cod' } );
	expect( [ 200, 201 ], 'PATCH checkout: ' + JSON.stringify( patched.body ).slice( 0, 200 ) ).toContain( patched.status );
	const afterChoice = await cart.api( 'get', '/cart' );
	expect( Number( afterChoice.body.totals.total_fees ) / 100, 'COD fee on the cart' ).toBe( 50 );
	expect( Number( afterChoice.body.totals.total_price ) / 100 ).toBe( 750 ); // 500 + 200 + 50.

	// Place the order and check the stored total.
	const placed = await cart.api( 'post', '/checkout', { billing_address: address( 'lahore' ), shipping_address: address( 'lahore' ), payment_method: 'cod', payment_data: [] } );
	expect( [ 200, 201 ], 'checkout: ' + JSON.stringify( placed.body ).slice( 0, 300 ) ).toContain( placed.status );
	const orderId = placed.body.order_id;
	expect( wp( `wc shop_order get ${ orderId } --field=total --user=1`, true ).trim() ).toBe( '750.00' );

	// The admin order screen lists the shipping and the COD fee.
	const hpos = wp( 'option get woocommerce_custom_orders_table_enabled' ).trim() === 'yes';
	await page.goto( hpos ? `/wp-admin/admin.php?page=wc-orders&action=edit&id=${ orderId }` : `/wp-admin/post.php?post=${ orderId }&action=edit` );
	await expect( page.locator( '#woocommerce-order-items' ) ).toContainText( 'Cash on delivery fee' );
	await expect( page.locator( '#woocommerce-order-items' ) ).toContainText( 'Delivery' );
	await page.waitForTimeout( 500 );
	await page.screenshot( { path: path.join( shots, 'screenshot-2.png' ), fullPage: true } );

	// Free shipping over the city's threshold: 7 x 500 = 3500 >= 3000.
	( { body } = await cartFor( baseURL, 'Lahore', 7 ) );
	expect( rateCost( body ), 'free over 3000' ).toBe( 0 );

	// Karachi (imported): shipping 350, and with no city fee the store-wide fee of 25 applies.
	( { cart, body } = await cartFor( baseURL, 'Karachi' ) );
	expect( rateCost( body ) ).toBe( 350 );
	await cart.api( 'patch', '/checkout', { payment_method: 'cod' } );
	expect( Number( ( await cart.api( 'get', '/cart' ) ).body.totals.total_fees ) / 100 ).toBe( 25 );

	// Remote Village: COD is not offered, and placing a COD order anyway is refused by WooCommerce.
	( { cart, body } = await cartFor( baseURL, 'Remote Village' ) );
	expect( rateCost( body ) ).toBe( 400 );
	expect( body.payment_methods ).not.toContain( 'cod' );
	const refused = await cart.api( 'post', '/checkout', { billing_address: address( 'Remote Village' ), shipping_address: address( 'Remote Village' ), payment_method: 'cod', payment_data: [] } );
	expect( refused.status, 'COD bypass must be refused' ).toBeGreaterThanOrEqual( 400 );

	// A city with no rule and no default cost: no shipping is offered.
	( { body } = await cartFor( baseURL, 'Nowhereville' ) );
	expect( rateCost( body ) ).toBeNull();

	// Positive control for the clean-database check, then deactivate and delete through the UI.
	expect( tableCount() ).toBeGreaterThan( 0 );
	await page.goto( '/wp-admin/plugins.php' );
	await page.click( '#deactivate-city-shipping-cod-rules' );
	await page.locator( '#delete-city-shipping-cod-rules' ).waitFor( { timeout: 60000 } );
	await page.waitForLoadState( 'networkidle' );
	page.once( 'dialog', ( d ) => d.accept() );
	await page.click( '#delete-city-shipping-cod-rules' );
	await expect.poll( () => wp( 'plugin list --field=name --skip-plugins --skip-themes' ).split( /\s+/ ).includes( 'city-shipping-cod-rules' ), { timeout: 90000, intervals: [ 3000 ] } ).toBe( false );

	// Clean database: table, options and the zone's method rows are gone.
	await expect.poll( () => wp( 'db query "SHOW TABLES LIKE \'wp_cityrules_rules\'" --skip-column-names' ).trim(), { timeout: 60000, intervals: [ 3000 ] } ).toBe( '' );
	expect( wp( "option list --search='cityrules*' --format=count" ).trim() ).toBe( '0' );
	expect( wp( "option list --search='woocommerce_cityrules*' --format=count" ).trim() ).toBe( '0' );
	expect( wp( 'db query "SELECT COUNT(*) FROM wp_woocommerce_shipping_zone_methods WHERE method_id=\'cityrules\'" --skip-column-names' ).trim() ).toBe( '0' );
} );
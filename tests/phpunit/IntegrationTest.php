<?php
/**
 * Integration tests with WooCommerce: rule storage, the shipping method in a real zone, COD availability
 * and fee on a real cart, admin handlers, CLI, uninstall.
 *
 * @package CityRules
 */

use CityRules\Admin;
use CityRules\Cli;
use CityRules\Cod;
use CityRules\Installer;
use CityRules\Plugin;
use CityRules\Rules;

require_once __DIR__ . '/cli-stubs.php';

/**
 * Thrown by the wp_redirect trap so handlers that call exit can be observed.
 */
class CityRules_Redirect_Caught extends RuntimeException {
}

/**
 * Integration tests.
 */
class IntegrationTest extends WP_UnitTestCase {

	/**
	 * The most recently created shipping zone.
	 *
	 * @var WC_Shipping_Zone
	 */
	private $last_zone;

	/**
	 * Fresh table and a trap for redirects.
	 */
	public function set_up() {
		parent::set_up();
		Installer::install();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Installer::table() ); // phpcs:ignore
		Rules::flush();
		// Shipping zones survive between tests in this database, and WooCommerce uses the first matching zone.
		foreach ( WC_Shipping_Zones::get_zones() as $zone_data ) {
			WC_Shipping_Zones::get_zone( (int) $zone_data['id'] )->delete();
		}
		WC_Cache_Helper::get_transient_version( 'shipping', true );
		delete_option( 'cityrules_default_cod_fee' );
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new CityRules_Redirect_Caught( $location );
			}
		);
		$_POST    = array();
		$_REQUEST = array();
	}

	private function rule( $city, array $more = array(), $country = '' ) {
		$result = Rules::save( array( 'city' => $city, 'country' => $country ) + $more );
		$this->assertTrue( $result, 'rule saved' );
	}

	/**
	 * A shipping zone for Pakistan holding the "City rate" method.
	 *
	 * @param string $default_cost Cost for unlisted cities ('' = no rate).
	 * @return int Instance ID.
	 */
	private function zone( $default_cost = '' ) {
		$zone             = new WC_Shipping_Zone();
		$this->last_zone = $zone;
		$zone->set_zone_name( 'Pakistan' );
		$zone->add_location( 'PK', 'country' );
		$zone->save();
		$instance = $zone->add_shipping_method( 'cityrules' );
		update_option( 'woocommerce_cityrules_' . $instance . '_settings', array( 'title' => 'Delivery', 'default_cost' => $default_cost, 'tax_status' => 'none' ) );
		WC_Cache_Helper::get_transient_version( 'shipping', true );
		return $instance;
	}

	/**
	 * Rates WooCommerce offers for a package to a city.
	 *
	 * @param string $city          Destination city.
	 * @param float  $contents_cost Cart value.
	 * @return array<string,WC_Shipping_Rate>
	 */
	private function rates( $city, $contents_cost = 500.0 ) {
		$package = array(
			'contents'        => array(),
			'contents_cost'   => $contents_cost,
			'applied_coupons' => array(),
			'user'            => array( 'ID' => 0 ),
			'destination'     => array( 'country' => 'PK', 'state' => '', 'postcode' => '', 'city' => $city, 'address' => '', 'address_1' => '', 'address_2' => '' ),
		);
		$result  = WC()->shipping()->calculate_shipping_for_package( $package );
		return $result['rates'];
	}

	private function as_user( $role ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
	}

	private function redirect_of( $fn ) {
		try {
			$fn();
		} catch ( CityRules_Redirect_Caught $e ) {
			return $e->getMessage();
		}
		$this->fail( 'Expected a redirect.' );
	}

	// ---- storage -------------------------------------------------------------------------------

	public function test_install_creates_the_table_with_an_autoloaded_version() {
		global $wpdb;
		$this->assertSame( Installer::table(), $wpdb->get_var( "SHOW TABLES LIKE '" . Installer::table() . "'" ) ); // phpcs:ignore
		delete_option( 'cityrules_db_version' );
		Installer::maybe_upgrade();
		$this->assertSame( Installer::DB_VERSION, get_option( 'cityrules_db_version' ) );
		$autoload = $wpdb->get_var( "SELECT autoload FROM {$wpdb->options} WHERE option_name = 'cityrules_db_version'" ); // phpcs:ignore
		$this->assertContains( $autoload, array( 'on', 'yes', 'auto' ), 'Read on every request, so it must be autoloaded.' );
		Installer::activate( false );
		Installer::new_site( (object) array( 'blog_id' => 1 ) );
	}

	public function test_save_and_find_ignore_case_spaces_and_punctuation() {
		$this->rule( 'Lahore', array( 'shipping_cost' => '200' ) );
		foreach ( array( 'lahore', ' LAHORE ', 'La-hore', 'Lahore.' ) as $typed ) {
			$rule = Rules::find( 'PK', $typed );
			$this->assertNotNull( $rule, $typed );
			$this->assertSame( 200.0, $rule['shipping_cost'] );
		}
		$this->assertNull( Rules::find( 'PK', 'Karachi' ) );
		$this->assertNull( Rules::find( 'PK', '' ) );
	}

	public function test_country_specific_rule_beats_any_country_and_other_countries_do_not_match() {
		$this->rule( 'Springfield', array( 'shipping_cost' => '10' ) );
		$this->rule( 'Springfield', array( 'shipping_cost' => '99' ), 'PK' );
		$this->assertSame( 99.0, Rules::find( 'PK', 'Springfield' )['shipping_cost'] );
		$this->assertSame( 10.0, Rules::find( 'US', 'Springfield' )['shipping_cost'], 'Falls back to the any-country rule.' );
		Rules::delete( Rules::find( 'US', 'Springfield' )['id'] );
		$this->assertSame( 99.0, Rules::find( 'PK', 'Springfield' )['shipping_cost'] );
		$this->assertNull( Rules::find( 'US', 'Springfield' ), 'A PK-only rule never applies elsewhere.' );
	}

	public function test_saving_again_updates_instead_of_duplicating() {
		$this->rule( 'Lahore', array( 'shipping_cost' => '200', 'cod' => 'no' ) );
		$this->rule( 'LAHORE', array( 'shipping_cost' => '250' ) );
		$this->assertCount( 1, Rules::all() );
		$rule = Rules::find( '', 'lahore' );
		$this->assertSame( 250.0, $rule['shipping_cost'] );
		$this->assertSame( 'default', $rule['cod'], 'The whole rule is replaced.' );
		$this->assertSame( 'LAHORE', $rule['city_label'] );
	}

	public function test_invalid_input_is_rejected_and_nothing_is_stored() {
		$this->assertWPError( Rules::save( array( 'city' => '', 'shipping_cost' => '10' ) ) );
		$this->assertWPError( Rules::save( array( 'city' => '---', 'shipping_cost' => '10' ) ) );
		$this->assertWPError( Rules::save( array( 'city' => 'Lahore', 'shipping_cost' => 'abc' ) ) );
		$this->assertWPError( Rules::save( array( 'city' => 'Lahore', 'cod_fee' => '-1' ) ) );
		$this->assertCount( 0, Rules::all() );
	}

	public function test_optional_fields_can_be_empty_and_zero_is_kept() {
		$this->rule( 'Lahore', array( 'shipping_cost' => '0', 'cod_fee' => '0' ) );
		$rule = Rules::find( '', 'Lahore' );
		$this->assertSame( 0.0, $rule['shipping_cost'] );
		$this->assertSame( 0.0, $rule['cod_fee'] );
		$this->assertNull( $rule['free_over'] );
	}

	public function test_the_rule_filter_and_lookup_cache() {
		$this->rule( 'Lahore', array( 'shipping_cost' => '200' ) );
		$this->assertNotNull( Rules::find( 'PK', 'Lahore' ) );
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Installer::table() ); // phpcs:ignore
		$this->assertNotNull( Rules::find( 'PK', 'Lahore' ), 'Served from the per-request cache.' );
		Rules::flush();
		$this->assertNull( Rules::find( 'PK', 'Lahore' ) );
		add_filter( 'cityrules_rule', static fn() => array( 'shipping_cost' => 1.0, 'free_over' => null, 'cod' => 'no', 'cod_fee' => null ) );
		$this->assertSame( 'no', Rules::find( 'PK', 'Anywhere' )['cod'], 'The filter can supply a rule.' );
	}

	public function test_csv_export_import_roundtrip_and_formula_safety() {
		$this->rule( 'Lahore', array( 'shipping_cost' => '200', 'free_over' => '3000', 'cod' => 'yes', 'cod_fee' => '50' ), 'PK' );
		$this->rule( '=SUM(A1)', array( 'shipping_cost' => '5' ) );
		$csv = Rules::export_csv();
		$this->assertStringStartsWith( "country,city,shipping_cost,free_over,cod,cod_fee\n", $csv );
		$this->assertStringContainsString( "'=SUM(A1)", $csv );

		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Installer::table() ); // phpcs:ignore
		Rules::flush();
		$lines  = array_values( array_filter( explode( "\n", $csv ) ) );
		$result = Rules::import_lines( array_merge( $lines, array( ',,5,,,', 'PK,Quetta,abc,,,', 'PK,Multan' ) ) );
		$this->assertSame( array( 'imported' => 2 + 1, 'skipped' => 2 ), $result, 'A short row imports with defaults; an empty city and a bad amount are skipped.' );
		$lahore = Rules::find( 'PK', 'Lahore' );
		$this->assertSame( array( 200.0, 3000.0, 'yes', 50.0 ), array( $lahore['shipping_cost'], $lahore['free_over'], $lahore['cod'], $lahore['cod_fee'] ) );
		$this->assertNotNull( Rules::find( '', '=SUM(A1)' ), 'The formula guard is removed on import.' );
	}

	// ---- shipping method -------------------------------------------------------------------------

	public function test_shipping_method_is_registered_and_priced_by_city() {
		$this->assertArrayHasKey( 'cityrules', Plugin::register_shipping_method( array() ) );
		$this->zone();
		$this->rule( 'Lahore', array( 'shipping_cost' => '200' ) );
		$this->rule( 'Karachi', array( 'shipping_cost' => '350' ) );

		$lahore = array_values( $this->rates( 'lahore' ) );
		$this->assertCount( 1, $lahore );
		$this->assertSame( 200.0, (float) $lahore[0]->get_cost() );
		$this->assertSame( 'Delivery', $lahore[0]->get_label() );
		$this->assertSame( 350.0, (float) array_values( $this->rates( ' KARACHI ' ) )[0]->get_cost() );
	}

	public function test_unlisted_city_gets_the_default_cost_or_no_rate() {
		$this->zone( '' );
		$this->assertCount( 0, $this->rates( 'Nowhereville' ), 'No default: no shipping offered.' );

		// WooCommerce caches a method's settings, and two zones for one country would never both be reached, so replace the zone.
		$this->last_zone->delete();
		$this->zone( '500' );
		$this->assertSame( 500.0, (float) array_values( $this->rates( 'Nowhereville' ) )[0]->get_cost() );
	}

	public function test_free_shipping_over_the_city_threshold() {
		$this->zone();
		$this->rule( 'Lahore', array( 'shipping_cost' => '200', 'free_over' => '3000' ) );
		$this->assertSame( 200.0, (float) array_values( $this->rates( 'Lahore', 2999.0 ) )[0]->get_cost() );
		$this->assertSame( 0.0, (float) array_values( $this->rates( 'Lahore', 3000.0 ) )[0]->get_cost() );
	}

	// ---- cash on delivery ----------------------------------------------------------------------

	private function customer_in( $city, $country = 'PK' ) {
		wc_load_cart();
		WC()->customer->set_shipping_city( $city );
		WC()->customer->set_shipping_country( $country );
		WC()->customer->set_billing_city( '' );
	}

	public function test_cod_is_removed_only_where_a_rule_says_no() {
		$this->rule( 'Remote Village', array( 'cod' => 'no' ) );
		$this->rule( 'Lahore', array( 'cod' => 'yes' ) );
		$gateways = array( 'cod' => new stdClass(), 'bacs' => new stdClass() );

		$this->customer_in( 'remote village' );
		$this->assertSame( array( 'bacs' ), array_keys( Cod::filter_gateways( $gateways ) ), 'Other gateways stay.' );
		$this->customer_in( 'Lahore' );
		$this->assertSame( array( 'cod', 'bacs' ), array_keys( Cod::filter_gateways( $gateways ) ) );
		$this->customer_in( 'Unlisted' );
		$this->assertSame( array( 'cod', 'bacs' ), array_keys( Cod::filter_gateways( $gateways ) ) );
		$this->assertSame( 'not an array', Cod::filter_gateways( 'not an array' ) );
		$this->assertSame( array( 'bacs' => 1 ), Cod::filter_gateways( array( 'bacs' => 1 ) ) );
	}

	public function test_cod_filter_is_wired_into_woocommerce() {
		$this->assertNotFalse( has_filter( 'woocommerce_available_payment_gateways', array( Cod::class, 'filter_gateways' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_cart_calculate_fees', array( Cod::class, 'add_fee' ) ) );
	}

	public function test_billing_city_is_used_when_there_is_no_shipping_city() {
		$this->rule( 'Remote Village', array( 'cod' => 'no' ) );
		wc_load_cart();
		WC()->customer->set_shipping_city( '' );
		WC()->customer->set_billing_city( 'Remote Village' );
		WC()->customer->set_billing_country( 'PK' );
		$this->assertSame( array( 'bacs' ), array_keys( Cod::filter_gateways( array( 'cod' => 1, 'bacs' => 1 ) ) ) );
	}

	/**
	 * Put one product in a real cart for a customer in a city, with a chosen payment method.
	 *
	 * @param string $city   City.
	 * @param string $method Chosen payment method.
	 * @return array<string,object> Fees on the cart after totals are calculated.
	 */
	private function cart_fees( $city, $method ) {
		$this->customer_in( $city );
		WC()->cart->empty_cart();
		$product = new WC_Product_Simple();
		$product->set_name( 'Test item' );
		$product->set_regular_price( '100' );
		$product->set_virtual( true );
		$product->save();
		WC()->cart->add_to_cart( $product->get_id() );
		WC()->session->set( 'chosen_payment_method', $method );
		WC()->cart->calculate_totals();
		return WC()->cart->get_fees();
	}

	public function test_cod_fee_uses_the_city_fee_then_the_store_default() {
		update_option( 'cityrules_default_cod_fee', '40' );
		$this->rule( 'Lahore', array( 'cod_fee' => '75' ) );
		$this->rule( 'Free Town', array( 'cod_fee' => '0' ) );

		$fees = array_values( $this->cart_fees( 'Lahore', 'cod' ) );
		$this->assertCount( 1, $fees );
		$this->assertSame( 75.0, (float) $fees[0]->amount );
		$this->assertSame( 'Cash on delivery fee', $fees[0]->name );

		$this->assertSame( 40.0, (float) array_values( $this->cart_fees( 'Unlisted', 'cod' ) )[0]->amount, 'The store-wide fee applies.' );
		$this->assertCount( 0, $this->cart_fees( 'Free Town', 'cod' ), 'An explicit 0 on the city means no fee.' );
	}

	public function test_no_cod_fee_for_other_payment_methods() {
		update_option( 'cityrules_default_cod_fee', '40' );
		$this->assertCount( 0, $this->cart_fees( 'Lahore', 'bacs' ) );
		$this->assertCount( 0, $this->cart_fees( 'Lahore', '' ) );
	}

	public function test_no_fee_when_none_is_configured() {
		$this->assertCount( 0, $this->cart_fees( 'Lahore', 'cod' ) );
	}

	// ---- admin ---------------------------------------------------------------------------------

	private function as_admin( $action ) {
		$this->as_user( 'administrator' );
		$_REQUEST['_wpnonce'] = wp_create_nonce( $action );
	}

	public function test_save_handler_creates_a_rule_and_reports_bad_input() {
		$this->as_admin( 'cityrules_save' );
		$_POST = array( 'city' => 'Lahore', 'shipping_cost' => '200', 'country' => 'pk', 'cod' => 'no', 'cod_fee' => '', 'free_over' => '' );
		$this->assertStringContainsString( 'cityrules_msg=saved', $this->redirect_of( array( Admin::class, 'handle_save' ) ) );
		$rule = Rules::find( 'PK', 'Lahore' );
		$this->assertSame( 'no', $rule['cod'] );

		$_POST['shipping_cost'] = 'oops';
		$this->assertStringContainsString( 'cityrules_msg=invalid', $this->redirect_of( array( Admin::class, 'handle_save' ) ) );
	}

	public function test_delete_handler() {
		$this->rule( 'Lahore', array( 'shipping_cost' => '1' ) );
		$this->as_admin( 'cityrules_delete' );
		$_POST['id'] = Rules::find( '', 'Lahore' )['id'];
		$this->redirect_of( array( Admin::class, 'handle_delete' ) );
		$this->assertCount( 0, Rules::all() );
	}

	public function test_handlers_reject_missing_capability_and_bad_nonces() {
		add_filter( 'wp_die_handler', static fn() => static function ( $message ) {
			throw new RuntimeException( is_string( $message ) ? $message : 'die' );
		} );
		$_POST = array( 'city' => 'Lahore', 'shipping_cost' => '5' );

		$this->as_user( 'customer' );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'cityrules_save' );
		try {
			Admin::handle_save();
			$this->fail( 'Should have died.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'permission', $e->getMessage() );
		}

		$this->as_user( 'shop_manager' );
		$_REQUEST['_wpnonce'] = 'bad';
		try {
			Admin::handle_save();
			$this->fail( 'Should have died.' );
		} catch ( RuntimeException $e ) {
			$this->assertCount( 0, Rules::all(), 'A bad nonce stores nothing.' );
		}
		foreach ( array( 'handle_delete', 'handle_import', 'handle_export' ) as $handler ) {
			try {
				Admin::$handler();
				$this->fail( "$handler should have died." );
			} catch ( RuntimeException $e ) {
				$this->assertTrue( true );
			}
		}
	}

	public function test_import_handler_rejects_a_missing_upload() {
		$this->as_admin( 'cityrules_import' );
		$this->assertStringContainsString( 'cityrules_msg=invalid', $this->redirect_of( array( Admin::class, 'handle_import' ) ) );
	}

	public function test_screen_lists_rules_escapes_output_and_pre_fills_the_edit_form() {
		$this->as_user( 'shop_manager' );
		$this->rule( '<script>alert(1)</script> City', array( 'shipping_cost' => '12.5', 'cod' => 'no' ) );
		$this->rule( 'Lahore', array( 'shipping_cost' => '200', 'free_over' => '3000', 'cod_fee' => '50' ), 'PK' );

		ob_start();
		Admin::render();
		$html = ob_get_clean();
		$this->assertStringNotContainsString( '<script>alert', $html );
		$this->assertStringContainsString( 'Lahore', $html );
		$this->assertStringContainsString( '12.50', $html );

		$_GET['edit'] = Rules::find( 'PK', 'Lahore' )['id'];
		ob_start();
		Admin::render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'value="Lahore"', $html, 'The form is pre-filled.' );
		$this->assertStringContainsString( 'Edit rule', $html );

		foreach ( array( 'saved', 'deleted', 'invalid', 'imported-3-1' ) as $code ) {
			$_GET['cityrules_msg'] = $code;
			ob_start();
			Admin::render();
			$this->assertStringContainsString( 'notice', ob_get_clean(), $code );
		}
	}

	public function test_screen_denies_customers() {
		$this->as_user( 'customer' );
		add_filter( 'wp_die_handler', static fn() => static function () {
			throw new RuntimeException( 'denied' );
		} );
		$this->expectException( RuntimeException::class );
		Admin::render();
	}

	public function test_default_fee_setting_is_sanitised_and_shop_managers_may_save_it() {
		Admin::init();
		Admin::menu();
		Admin::register_settings();
		update_option( 'cityrules_default_cod_fee', '1,500.50' );
		$this->assertSame( '1500.5', get_option( 'cityrules_default_cod_fee' ) );
		update_option( 'cityrules_default_cod_fee', 'free' );
		$this->assertSame( '0', get_option( 'cityrules_default_cod_fee' ) );
		update_option( 'cityrules_default_cod_fee', '' );
		$this->assertSame( '0', get_option( 'cityrules_default_cod_fee' ) );
		$this->assertSame( 'manage_woocommerce', apply_filters( 'option_page_capability_cityrules', 'manage_options' ) );
	}

	// ---- CLI and uninstall ---------------------------------------------------------------------

	public function test_cli_commands() {
		$cli = new Cli();
		$this->rule( 'Lahore', array( 'shipping_cost' => '200' ), 'PK' );
		$cli->list_();
		$this->assertSame( 'Lahore', $GLOBALS['cityrules_cli_items'][0]['city'] );

		$cli->export();
		$this->assertStringContainsString( 'Lahore', end( WP_CLI::$log )[1] );

		$file = wp_tempnam( 'cityrules' );
		file_put_contents( $file, "country,city,shipping_cost,free_over,cod,cod_fee\nPK,Multan,150,,no,\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
		$cli->import( array( $file ) );
		$this->assertSame( 'no', Rules::find( 'PK', 'Multan' )['cod'] );
		unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		$this->expectException( RuntimeException::class );
		$cli->import( array( '/no/such/file.csv' ) );
	}

	public function test_uninstall_drops_the_table_and_removes_options() {
		global $wpdb;
		$this->zone( '10' );
		$zone_methods = $wpdb->prefix . 'woocommerce_shipping_zone_methods';
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE method_id = %s', $zone_methods, 'cityrules' ) ), 'Precondition: the zone holds our method.' ); // phpcs:ignore
		update_option( 'cityrules_default_cod_fee', '40' );
		// The suite turns DROP TABLE into DROP TEMPORARY TABLE; uninstall must drop the real table.
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		define( 'WP_UNINSTALL_PLUGIN', 'city-shipping-cod-rules/city-shipping-cod-rules.php' );
		include dirname( __DIR__, 2 ) . '/uninstall.php';
		$this->assertNull( $wpdb->get_var( "SHOW TABLES LIKE '" . Installer::table() . "'" ) ); // phpcs:ignore
		$this->assertFalse( get_option( 'cityrules_db_version' ) );
		$this->assertFalse( get_option( 'cityrules_default_cod_fee' ) );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE method_id = %s', $zone_methods, 'cityrules' ) ), 'The zone rows are removed too.' ); // phpcs:ignore
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'woocommerce\\_cityrules\\_%'" ) ); // phpcs:ignore
	}
}

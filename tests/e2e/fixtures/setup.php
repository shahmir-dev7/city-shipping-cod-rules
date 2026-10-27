<?php
/**
 * Test-only: prepare a fresh WooCommerce site (run with `wp eval-file`). Never shipped.
 * Creates a 500.00 physical product, a Pakistan shipping zone holding the City rate method (no default cost),
 * and enables Cash on Delivery. Prints the product ID.
 */

foreach ( WC_Shipping_Zones::get_zones() as $zone_data ) {
	WC_Shipping_Zones::get_zone( (int) $zone_data['id'] )->delete();
}
foreach ( wc_get_orders( array( 'limit' => -1, 'return' => 'ids', 'status' => array_keys( wc_get_order_statuses() ) ) ) as $order_id ) {
	wc_get_order( $order_id )->delete( true );
}

$zone = new WC_Shipping_Zone();
$zone->set_zone_name( 'Pakistan' );
$zone->add_location( 'PK', 'country' );
$zone->save();
$instance = $zone->add_shipping_method( 'cityrules' );
update_option( 'woocommerce_cityrules_' . $instance . '_settings', array( 'title' => 'Delivery', 'default_cost' => '', 'tax_status' => 'none' ) );

update_option( 'woocommerce_cod_settings', array( 'enabled' => 'yes', 'enable_for_methods' => array(), 'enable_for_virtual' => 'yes' ) );
update_option( 'woocommerce_ship_to_countries', '' );
update_option( 'woocommerce_calc_taxes', 'no' );
update_option( 'woocommerce_default_country', 'PK' );
update_option( 'permalink_structure', '/%postname%/' );

$product = new WC_Product_Simple();
$product->set_name( 'City Test Item' );
$product->set_regular_price( '500' );
$product->set_status( 'publish' );
$product->save();
WC_Cache_Helper::get_transient_version( 'shipping', true );
echo 'PRODUCT_ID=' . $product->get_id() . ' INSTANCE=' . $instance . "\n";
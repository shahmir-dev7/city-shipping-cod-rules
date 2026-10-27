<?php
/**
 * Remove all plugin data (per site on multisite).
 *
 * @package CityRules
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site.
 */
function cityrules_uninstall_site(): void {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'cityrules_rules' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	delete_option( 'cityrules_db_version' );
	delete_option( 'cityrules_default_cod_fee' );
	// The shipping method's per-zone settings live in WooCommerce's own options: woocommerce_cityrules_<id>_settings,
	// and each zone keeps a row for the method.
	$zone_methods = $wpdb->prefix . 'woocommerce_shipping_zone_methods';
	if ( $zone_methods === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $zone_methods ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $zone_methods, array( 'method_id' => 'cityrules' ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'woocommerce_cityrules_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $cityrules_blog_id ) {
		switch_to_blog( (int) $cityrules_blog_id );
		cityrules_uninstall_site();
		restore_current_blog();
	}
} else {
	cityrules_uninstall_site();
}

<?php
/**
 * Bootstrap for WordPress + WooCommerce integration tests (run inside wp-env tests-cli).
 *
 * @package CityRules
 */

$cityrules_tests_dir = getenv( 'WP_TESTS_DIR' ) ? getenv( 'WP_TESTS_DIR' ) : '/wordpress-phpunit';

require_once $cityrules_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		// wp-env names the folder after the zip ("woocommerce.latest-stable"), a plain install uses "woocommerce".
		$found = glob( WP_PLUGIN_DIR . '/woocommerce*/woocommerce.php' );
		if ( ! $found ) {
			echo "WooCommerce is not installed in the test site.\n";
			exit( 1 );
		}
		require $found[0];
		require dirname( __DIR__, 2 ) . '/city-shipping-cod-rules.php';
	}
);

// Create WooCommerce tables once, before tests start (the suite makes later CREATE TABLEs temporary).
tests_add_filter(
	'setup_theme',
	static function () {
		\WC_Install::install();
		\WC_Install::create_roles(); // Be explicit: administrator and shop_manager must hold the WooCommerce capabilities.
		update_option( 'woocommerce_cod_settings', array( 'enabled' => 'yes' ) );
		if ( getenv( 'CITYRULES_HPOS' ) ) {
			// Run the whole suite on High-Performance Order Storage instead of legacy post storage.
			update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );
			update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
		}
	}
);

require $cityrules_tests_dir . '/includes/bootstrap.php';
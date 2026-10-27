<?php
/**
 * Plugin Name:          City Shipping & COD Rules
 * Description:          Charge shipping by city, allow or block cash on delivery per city, and add a COD fee, for WooCommerce stores where couriers price by city. CSV import and export.
 * Version:              1.0.0
 * Requires at least:    6.5
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * Author:               shahmir-dev7
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          city-shipping-cod-rules
 * Domain Path:          /languages
 * WC requires at least: 11.0
 *
 * @package CityRules
 */

defined( 'ABSPATH' ) || exit;

define( 'CITYRULES_VERSION', '1.0.0' );
define( 'CITYRULES_FILE', __FILE__ );

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'CityRules\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$file = __DIR__ . '/includes/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'CityRules\\Installer', 'activate' ) );

// Declare compatibility with High-Performance Order Storage and the checkout block.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', CITYRULES_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', CITYRULES_FILE, true );
		}
	}
);

CityRules\Plugin::init();

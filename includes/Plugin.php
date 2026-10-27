<?php
/**
 * Hook wiring.
 *
 * @package CityRules
 */

namespace CityRules;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap. Nothing here queries the database on a normal page view.
 */
class Plugin {

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'plugins_loaded', array( Installer::class, 'maybe_upgrade' ) );
		add_action( 'wp_initialize_site', array( Installer::class, 'new_site' ), 20 );
		add_action( 'woocommerce_shipping_init', array( __CLASS__, 'load_shipping_method' ) );
		add_filter( 'woocommerce_shipping_methods', array( __CLASS__, 'register_shipping_method' ) );
		add_filter( 'woocommerce_available_payment_gateways', array( Cod::class, 'filter_gateways' ), 20 );
		add_action( 'woocommerce_cart_calculate_fees', array( Cod::class, 'add_fee' ) );
		if ( is_admin() ) {
			Admin::init();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'cityrules', Cli::class );
		}
	}

	/**
	 * Load the shipping method class once WooCommerce's base class exists.
	 */
	public static function load_shipping_method(): void {
		require_once __DIR__ . '/ShippingMethod.php';
	}

	/**
	 * Add the method to WooCommerce's list.
	 *
	 * @param array<string,string> $methods Method id => class.
	 * @return array<string,string>
	 */
	public static function register_shipping_method( $methods ) {
		$methods['cityrules'] = ShippingMethod::class;
		return $methods;
	}
}

<?php
/**
 * Cash on delivery: per-city availability and fee.
 *
 * @package CityRules
 */

namespace CityRules;

defined( 'ABSPATH' ) || exit;

/**
 * Cod.
 */
class Cod {

	/**
	 * Destination country and city of the current customer (shipping address, else billing).
	 *
	 * @return array{0:string,1:string}
	 */
	public static function destination() {
		$customer = function_exists( 'WC' ) ? WC()->customer : null;
		// The customer object does not exist yet in some contexts (for example before the cart is loaded).
		if ( ! is_callable( array( $customer, 'get_shipping_city' ) ) ) {
			return array( '', '' );
		}
		$city    = (string) $customer->get_shipping_city();
		$country = (string) $customer->get_shipping_country();
		if ( '' === trim( $city ) ) {
			$city    = (string) $customer->get_billing_city();
			$country = (string) $customer->get_billing_country();
		}
		return array( $country, $city );
	}

	/**
	 * Remove Cash on Delivery where a rule blocks it. WooCommerce re-checks the chosen method against this
	 * list when the order is placed, so a customer cannot bypass it.
	 *
	 * @param mixed $gateways Available gateways (an array when WooCommerce calls this).
	 * @return mixed
	 */
	public static function filter_gateways( $gateways ) {
		if ( ! is_array( $gateways ) || ! isset( $gateways['cod'] ) || ( is_admin() && ! wp_doing_ajax() ) ) {
			return $gateways;
		}
		list( $country, $city ) = self::destination();
		if ( ! Matcher::cod_allowed( Rules::find( $country, $city ) ) ) {
			unset( $gateways['cod'] );
		}
		return $gateways;
	}

	/**
	 * Add the COD fee to the cart while Cash on Delivery is the chosen payment method.
	 *
	 * @param mixed $cart Cart (a WC_Cart when WooCommerce calls this).
	 */
	public static function add_fee( $cart ): void {
		// The session does not exist in every context (for example some background requests).
		if ( ( is_admin() && ! wp_doing_ajax() ) || ! $cart instanceof \WC_Cart || ! is_callable( array( WC()->session, 'get' ) ) ) {
			return;
		}
		if ( 'cod' !== (string) WC()->session->get( 'chosen_payment_method' ) ) {
			return;
		}
		list( $country, $city ) = self::destination();
		$fee                    = Matcher::cod_fee( Rules::find( $country, $city ), (float) get_option( 'cityrules_default_cod_fee', 0 ) );
		if ( $fee > 0 ) {
			$cart->add_fee( __( 'Cash on delivery fee', 'city-shipping-cod-rules' ), $fee, false );
		}
	}
}

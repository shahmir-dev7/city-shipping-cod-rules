<?php
/**
 * The "City rate" shipping method, added to shipping zones.
 *
 * @package CityRules
 */

namespace CityRules;

defined( 'ABSPATH' ) || exit;

/**
 * Shipping method. Loaded on woocommerce_shipping_init, when WC_Shipping_Method exists.
 */
class ShippingMethod extends \WC_Shipping_Method {

	/**
	 * Constructor.
	 *
	 * @param int $instance_id Zone instance.
	 */
	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'cityrules';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'City rate', 'city-shipping-cod-rules' );
		$this->method_description = __( 'Shipping priced by the destination city, from the table under WooCommerce > City Rules.', 'city-shipping-cod-rules' );
		$this->supports           = array( 'shipping-zones', 'instance-settings', 'instance-settings-modal' );
		$this->init();
	}

	/**
	 * Settings.
	 */
	private function init(): void {
		$this->instance_form_fields = array(
			'title'        => array(
				'title'   => __( 'Title', 'city-shipping-cod-rules' ),
				'type'    => 'text',
				'default' => __( 'Delivery', 'city-shipping-cod-rules' ),
			),
			'default_cost' => array(
				'title'       => __( 'Cost for cities not in the table', 'city-shipping-cod-rules' ),
				'type'        => 'text',
				'description' => __( 'Leave empty to offer no shipping to cities that have no rule.', 'city-shipping-cod-rules' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'tax_status'   => array(
				'title'   => __( 'Tax status', 'city-shipping-cod-rules' ),
				'type'    => 'select',
				'default' => 'taxable',
				'options' => array(
					'taxable' => __( 'Taxable', 'city-shipping-cod-rules' ),
					'none'    => _x( 'None', 'Tax status', 'city-shipping-cod-rules' ),
				),
			),
		);
		$this->title                = $this->get_option( 'title' );
		$this->tax_status           = $this->get_option( 'tax_status' );
		add_action(
			'woocommerce_update_options_shipping_' . $this->id . '_' . $this->instance_id,
			function (): void {
				$this->process_admin_options();
			}
		);
	}

	/**
	 * Offer a rate for the package.
	 *
	 * @param array<string,mixed> $package WooCommerce shipping package.
	 */
	public function calculate_shipping( $package = array() ): void {
		$destination = isset( $package['destination'] ) ? (array) $package['destination'] : array();
		$rule        = Rules::find( isset( $destination['country'] ) ? $destination['country'] : '', isset( $destination['city'] ) ? $destination['city'] : '' );
		$default     = Normalizer::money( $this->get_option( 'default_cost' ) );
		$cost        = Matcher::shipping_cost( $rule, isset( $package['contents_cost'] ) ? (float) $package['contents_cost'] : 0.0, false === $default ? null : $default );
		if ( null === $cost ) {
			return;
		}
		$this->add_rate(
			array(
				'id'      => $this->get_rate_id(),
				'label'   => $this->title,
				'cost'    => $cost,
				'package' => $package,
			)
		);
	}
}

<?php
/**
 * Admin screen and form handlers.
 *
 * @package CityRules
 */

namespace CityRules;

defined( 'ABSPATH' ) || exit;

/**
 * Admin.
 */
class Admin {

	const CAP = 'manage_woocommerce';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_cityrules_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_cityrules_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_cityrules_import', array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_post_cityrules_export', array( __CLASS__, 'handle_export' ) );
		// Shop managers hold manage_woocommerce, not manage_options, which options.php requires by default.
		add_filter( 'option_page_capability_cityrules', array( __CLASS__, 'option_capability' ) );
	}

	/**
	 * Capability for saving the settings.
	 *
	 * @return string
	 */
	public static function option_capability() {
		return self::CAP;
	}

	/**
	 * WooCommerce > City Rules.
	 */
	public static function menu(): void {
		add_submenu_page( 'woocommerce', __( 'City Rules', 'city-shipping-cod-rules' ), __( 'City Rules', 'city-shipping-cod-rules' ), self::CAP, 'cityrules', array( __CLASS__, 'render' ) );
	}

	/**
	 * Default COD fee setting.
	 */
	public static function register_settings(): void {
		register_setting(
			'cityrules',
			'cityrules_default_cod_fee',
			array(
				'type'              => 'string',
				'default'           => '0',
				'sanitize_callback' => static function ( $value ) {
					$amount = Normalizer::money( $value );
					return ( false === $amount || null === $amount ) ? '0' : (string) $amount;
				},
			)
		);
	}

	/**
	 * Capability gate; dies with 403.
	 *
	 * @param string $action Nonce action.
	 */
	private static function guard( $action ): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'city-shipping-cod-rules' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Redirect back with a notice code.
	 *
	 * @param string $code Notice code.
	 */
	private static function back( $code ): void {
		wp_safe_redirect( add_query_arg( 'cityrules_msg', $code, admin_url( 'admin.php?page=cityrules' ) ) );
		exit;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- every handler calls guard() (check_admin_referer) first.

	/**
	 * Save one rule.
	 */
	public static function handle_save(): void {
		self::guard( 'cityrules_save' );
		$data = array();
		foreach ( array( 'country', 'city', 'shipping_cost', 'free_over', 'cod', 'cod_fee' ) as $field ) {
			$data[ $field ] = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
		}
		self::back( is_wp_error( Rules::save( $data ) ) ? 'invalid' : 'saved' );
	}

	/**
	 * Delete one rule.
	 */
	public static function handle_delete(): void {
		self::guard( 'cityrules_delete' );
		Rules::delete( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 );
		self::back( 'deleted' );
	}

	/**
	 * Import a CSV file (max 1 MB).
	 */
	public static function handle_import(): void {
		self::guard( 'cityrules_import' );
		$tmp  = isset( $_FILES['csv']['tmp_name'] ) ? $_FILES['csv']['tmp_name'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- path from PHP, checked by is_uploaded_file().
		$size = isset( $_FILES['csv']['size'] ) ? (int) $_FILES['csv']['size'] : 0;
		if ( ! is_string( $tmp ) || '' === $tmp || ! is_uploaded_file( $tmp ) || $size > MB_IN_BYTES ) {
			self::back( 'invalid' );
		}
		$result = Rules::import_lines( (array) file( $tmp, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) );
		self::back( 'imported-' . $result['imported'] . '-' . $result['skipped'] );
	}

	/**
	 * Download all rules as CSV.
	 */
	public static function handle_export(): void {
		self::guard( 'cityrules_export' );
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="city-rules.csv"' );
		echo Rules::export_csv(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download, cells neutralised by Csv::safe().
		exit;
	}

	// phpcs:enable WordPress.Security.NonceVerification.Missing

	/**
	 * Render the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'city-shipping-cod-rules' ), '', array( 'response' => 403 ) );
		}
		$post_url = esc_url( admin_url( 'admin-post.php' ) );
		$msg      = isset( $_GET['cityrules_msg'] ) ? sanitize_key( wp_unslash( $_GET['cityrules_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$edit_id  = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- selects a row to show.
		$rules    = Rules::all();
		$edit     = array(
			'country'       => '',
			'city_label'    => '',
			'shipping_cost' => null,
			'free_over'     => null,
			'cod'           => 'default',
			'cod_fee'       => null,
		);
		foreach ( $rules as $rule ) {
			if ( (int) $rule['id'] === $edit_id ) {
				$edit = $rule;
			}
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'City Shipping & COD Rules', 'city-shipping-cod-rules' ) . '</h1>';
		self::notice( $msg );
		echo '<p>' . esc_html__( 'Add a "City rate" method to a shipping zone (WooCommerce > Settings > Shipping), then list the cities here. Cities are matched on the name the customer types, ignoring case, spaces and punctuation.', 'city-shipping-cod-rules' ) . '</p>';

		echo '<h2>' . esc_html( $edit_id && $edit['city_label'] ? __( 'Edit rule', 'city-shipping-cod-rules' ) : __( 'Add or update a rule', 'city-shipping-cod-rules' ) ) . '</h2>';
		echo '<form method="post" action="' . $post_url . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		wp_nonce_field( 'cityrules_save' );
		echo '<input type="hidden" name="action" value="cityrules_save"><table class="form-table" role="presentation">';
		self::field( 'city', __( 'City', 'city-shipping-cod-rules' ), (string) $edit['city_label'], 'text', __( 'As customers write it, for example Lahore.', 'city-shipping-cod-rules' ) );
		self::field( 'country', __( 'Country code (optional)', 'city-shipping-cod-rules' ), (string) $edit['country'], 'text', __( 'Two letters, for example PK. Empty matches any country.', 'city-shipping-cod-rules' ) );
		self::field( 'shipping_cost', __( 'Shipping cost', 'city-shipping-cod-rules' ), null === $edit['shipping_cost'] ? '' : (string) $edit['shipping_cost'], 'text', __( 'Empty uses the shipping method\'s default for unlisted cities.', 'city-shipping-cod-rules' ) );
		self::field( 'free_over', __( 'Free shipping over (optional)', 'city-shipping-cod-rules' ), null === $edit['free_over'] ? '' : (string) $edit['free_over'], 'text', __( 'Cart value at or above which shipping to this city is free.', 'city-shipping-cod-rules' ) );
		echo '<tr><th scope="row"><label for="cityrules-cod">' . esc_html__( 'Cash on delivery', 'city-shipping-cod-rules' ) . '</label></th><td><select id="cityrules-cod" name="cod">';
		foreach ( array(
			'default' => __( 'Default (available)', 'city-shipping-cod-rules' ),
			'yes'     => __( 'Available', 'city-shipping-cod-rules' ),
			'no'      => __( 'Not available', 'city-shipping-cod-rules' ),
		) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $edit['cod'], $value, false ) . '>' . esc_html( $label ) . '</option>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- selected() returns a fixed attribute.
		}
		echo '</select></td></tr>';
		self::field( 'cod_fee', __( 'COD fee (optional)', 'city-shipping-cod-rules' ), null === $edit['cod_fee'] ? '' : (string) $edit['cod_fee'], 'text', __( 'Empty uses the store-wide fee below. 0 means no fee here.', 'city-shipping-cod-rules' ) );
		echo '</table><p><button class="button button-primary">' . esc_html__( 'Save rule', 'city-shipping-cod-rules' ) . '</button></p></form>';

		echo '<h2>' . esc_html__( 'Rules', 'city-shipping-cod-rules' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'City', 'city-shipping-cod-rules' ) . '</th><th>' . esc_html__( 'Country', 'city-shipping-cod-rules' ) . '</th><th>' . esc_html__( 'Shipping', 'city-shipping-cod-rules' ) . '</th><th>' . esc_html__( 'Free over', 'city-shipping-cod-rules' ) . '</th><th>' . esc_html__( 'COD', 'city-shipping-cod-rules' ) . '</th><th>' . esc_html__( 'COD fee', 'city-shipping-cod-rules' ) . '</th><th>' . esc_html__( 'Actions', 'city-shipping-cod-rules' ) . '</th></tr></thead><tbody>';
		if ( ! $rules ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No rules yet.', 'city-shipping-cod-rules' ) . '</td></tr>';
		}
		foreach ( $rules as $rule ) {
			$dash = '&ndash;';
			echo '<tr><td>' . esc_html( $rule['city_label'] ) . '</td><td>' . ( '' === $rule['country'] ? esc_html__( 'Any', 'city-shipping-cod-rules' ) : esc_html( $rule['country'] ) ) . '</td>';
			foreach ( array( 'shipping_cost', 'free_over' ) as $field ) {
				echo '<td>' . ( null === $rule[ $field ] ? $dash : esc_html( wc_format_decimal( $rule[ $field ], 2 ) ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed entity or escaped value.
			}
			echo '<td>' . esc_html( $rule['cod'] ) . '</td><td>' . ( null === $rule['cod_fee'] ? $dash : esc_html( wc_format_decimal( $rule['cod_fee'], 2 ) ) ) . '</td><td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed entity or escaped value.
			echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=cityrules&edit=' . (int) $rule['id'] ) ) . '">' . esc_html__( 'Edit', 'city-shipping-cod-rules' ) . '</a> ';
			echo '<form method="post" action="' . $post_url . '" style="display:inline">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			wp_nonce_field( 'cityrules_delete' );
			echo '<input type="hidden" name="action" value="cityrules_delete"><input type="hidden" name="id" value="' . (int) $rule['id'] . '"><button class="button">' . esc_html__( 'Delete', 'city-shipping-cod-rules' ) . '</button></form></td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Import / export', 'city-shipping-cod-rules' ) . '</h2>';
		echo '<p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cityrules_export' ), 'cityrules_export' ) ) . '">' . esc_html__( 'Export CSV', 'city-shipping-cod-rules' ) . '</a></p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . $post_url . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		wp_nonce_field( 'cityrules_import' );
		echo '<input type="hidden" name="action" value="cityrules_import"><label for="cityrules-csv">' . esc_html__( 'CSV file (columns: country, city, shipping_cost, free_over, cod, cod_fee)', 'city-shipping-cod-rules' ) . '</label> <input type="file" id="cityrules-csv" name="csv" accept=".csv"> <button class="button">' . esc_html__( 'Import', 'city-shipping-cod-rules' ) . '</button></form>';

		echo '<h2>' . esc_html__( 'Store-wide COD fee', 'city-shipping-cod-rules' ) . '</h2><form method="post" action="options.php">';
		settings_fields( 'cityrules' );
		echo '<label for="cityrules_default_cod_fee">' . esc_html__( 'Fee added when the customer chooses Cash on Delivery', 'city-shipping-cod-rules' ) . '</label> <input type="text" id="cityrules_default_cod_fee" name="cityrules_default_cod_fee" value="' . esc_attr( (string) get_option( 'cityrules_default_cod_fee', '0' ) ) . '" class="small-text"> ';
		submit_button( __( 'Save', 'city-shipping-cod-rules' ), 'secondary', 'submit', false );
		echo '</form></div>';
	}

	/**
	 * Notice for a message code.
	 *
	 * @param string $msg Code from the query string.
	 */
	private static function notice( $msg ): void {
		$messages = array(
			'saved'   => array( 'notice-success', __( 'Rule saved.', 'city-shipping-cod-rules' ) ),
			'deleted' => array( 'notice-success', __( 'Rule deleted.', 'city-shipping-cod-rules' ) ),
			'invalid' => array( 'notice-error', __( 'Invalid input. Check the city and the amounts.', 'city-shipping-cod-rules' ) ),
		);
		if ( preg_match( '/^imported-(\d+)-(\d+)$/', $msg, $m ) ) {
			/* translators: 1: rows imported, 2: rows skipped. */
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf( __( 'Imported %1$d rules, skipped %2$d.', 'city-shipping-cod-rules' ), (int) $m[1], (int) $m[2] ) ) . '</p></div>';
		} elseif ( isset( $messages[ $msg ] ) ) {
			echo '<div class="notice ' . esc_attr( $messages[ $msg ][0] ) . '"><p>' . esc_html( $messages[ $msg ][1] ) . '</p></div>';
		}
	}

	/**
	 * One text input row of the form.
	 *
	 * @param string $name        Field name.
	 * @param string $label       Label.
	 * @param string $value       Current value.
	 * @param string $type        Input type.
	 * @param string $description Help text.
	 */
	private static function field( $name, $label, $value, $type, $description ): void {
		echo '<tr><th scope="row"><label for="cityrules-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><input type="' . esc_attr( $type ) . '" id="cityrules-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" class="regular-text"><p class="description">' . esc_html( $description ) . '</p></td></tr>';
	}
}

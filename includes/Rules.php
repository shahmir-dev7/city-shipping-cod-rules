<?php
/**
 * Rule storage: lookup, upsert, delete, CSV.
 *
 * @package CityRules
 */

namespace CityRules;

defined( 'ABSPATH' ) || exit;

/**
 * Rules.
 */
class Rules {

	/**
	 * Per-request lookup cache, so a cart with several shipping and fee calculations queries once.
	 *
	 * @var array<string,array<string,mixed>|null>
	 */
	private static $cache = array();

	/**
	 * Forget cached lookups.
	 */
	public static function flush(): void {
		self::$cache = array();
	}

	/**
	 * Find the rule for a destination. A rule for the exact country beats one for "any country".
	 *
	 * @param string $country Destination country code.
	 * @param string $city    Destination city as typed by the customer.
	 * @return array<string,mixed>|null
	 */
	public static function find( $country, $city ) {
		$key = Normalizer::city_key( $city );
		if ( '' === $key ) {
			return null;
		}
		$country = Normalizer::country( $country );
		$cache   = $country . '|' . $key;
		if ( array_key_exists( $cache, self::$cache ) ) {
			return self::$cache[ $cache ];
		}
		global $wpdb;
		$table = Installer::table();
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT id, country, city_label, shipping_cost, free_over, cod, cod_fee FROM %i WHERE city_key = %s AND country IN (%s, %s) ORDER BY country DESC LIMIT 1', $table, $key, $country, '' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( is_array( $row ) ) {
			foreach ( array( 'shipping_cost', 'free_over', 'cod_fee' ) as $field ) {
				$row[ $field ] = null === $row[ $field ] ? null : (float) $row[ $field ];
			}
		} else {
			$row = null;
		}
		/**
		 * Filters the rule found for a destination (null when there is none).
		 *
		 * @param array<string,mixed>|null $row     Rule.
		 * @param string                   $country Destination country.
		 * @param string                   $city    Destination city.
		 */
		$row                   = apply_filters( 'cityrules_rule', $row, $country, $city );
		self::$cache[ $cache ] = is_array( $row ) ? $row : null;
		return self::$cache[ $cache ];
	}

	/**
	 * Create or update a rule (unique per country and city).
	 *
	 * @param array<string,mixed> $data country, city, shipping_cost, free_over, cod, cod_fee (raw strings are fine).
	 * @return true|\WP_Error
	 */
	public static function save( array $data ) {
		global $wpdb;
		$city  = isset( $data['city'] ) ? trim( sanitize_text_field( (string) $data['city'] ) ) : '';
		$key   = Normalizer::city_key( $city );
		$money = array();
		foreach ( array( 'shipping_cost', 'free_over', 'cod_fee' ) as $field ) {
			$value = Normalizer::money( isset( $data[ $field ] ) ? $data[ $field ] : '' );
			if ( false === $value ) {
				/* translators: %s: field name. */
				return new \WP_Error( 'cityrules_invalid', sprintf( __( 'Invalid amount in %s.', 'city-shipping-cod-rules' ), $field ) );
			}
			$money[ $field ] = $value;
		}
		if ( '' === $key ) {
			return new \WP_Error( 'cityrules_city', __( 'Enter a city name.', 'city-shipping-cod-rules' ) );
		}
		$country = Normalizer::country( isset( $data['country'] ) ? $data['country'] : '' );
		$table   = Installer::table();
		$ok      = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'INSERT INTO %i (country, city_key, city_label, shipping_cost, free_over, cod, cod_fee, updated) VALUES (%s, %s, %s, NULLIF(%s, ""), NULLIF(%s, ""), %s, NULLIF(%s, ""), %s)
				ON DUPLICATE KEY UPDATE city_label = VALUES(city_label), shipping_cost = VALUES(shipping_cost), free_over = VALUES(free_over), cod = VALUES(cod), cod_fee = VALUES(cod_fee), updated = VALUES(updated)',
				$table,
				$country,
				$key,
				mb_substr( $city, 0, 100, 'UTF-8' ),
				// Empty amounts go in as '' and NULLIF turns them into NULL: a null placeholder would be stored as 0.00.
				null === $money['shipping_cost'] ? '' : (string) $money['shipping_cost'],
				null === $money['free_over'] ? '' : (string) $money['free_over'],
				Normalizer::cod( isset( $data['cod'] ) ? $data['cod'] : '' ),
				null === $money['cod_fee'] ? '' : (string) $money['cod_fee'],
				current_time( 'mysql', true )
			)
		);
		self::flush();
		return false === $ok ? new \WP_Error( 'cityrules_db', __( 'Database error.', 'city-shipping-cod-rules' ) ) : true;
	}

	/**
	 * Delete a rule.
	 *
	 * @param int $id Rule ID.
	 */
	public static function delete( $id ): void {
		global $wpdb;
		$wpdb->delete( Installer::table(), array( 'id' => (int) $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::flush();
	}

	/**
	 * Rules, alphabetical.
	 *
	 * @param int $limit Max rows.
	 * @return array<int,array<string,mixed>>
	 */
	public static function all( $limit = 2000 ) {
		global $wpdb;
		$table = Installer::table();
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, country, city_label, shipping_cost, free_over, cod, cod_fee FROM %i ORDER BY city_label ASC LIMIT %d', $table, (int) $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * CSV text: header row, then one row per rule.
	 *
	 * @return string
	 */
	public static function export_csv() {
		$out = fopen( 'php://memory', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $out, array( 'country', 'city', 'shipping_cost', 'free_over', 'cod', 'cod_fee' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
		foreach ( self::all() as $row ) {
			fputcsv( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
				$out,
				array(
					Csv::safe( $row['country'] ),
					Csv::safe( $row['city_label'] ),
					null === $row['shipping_cost'] ? '' : $row['shipping_cost'],
					null === $row['free_over'] ? '' : $row['free_over'],
					$row['cod'],
					null === $row['cod_fee'] ? '' : $row['cod_fee'],
				)
			);
		}
		rewind( $out );
		$csv = (string) stream_get_contents( $out );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $csv;
	}

	/**
	 * Import CSV lines (first line is the header, columns as exported). Existing cities are updated.
	 *
	 * @param string[] $lines CSV lines.
	 * @return array{imported:int,skipped:int}
	 */
	public static function import_lines( array $lines ) {
		$rows     = array_map( 'str_getcsv', $lines );
		$imported = 0;
		$skipped  = 0;
		array_shift( $rows );
		foreach ( array_slice( $rows, 0, 5000 ) as $row ) {
			$row = array_pad( $row, 6, '' );
			$ok  = self::save(
				array(
					'country'       => Csv::unsafe( $row[0] ),
					'city'          => Csv::unsafe( $row[1] ),
					'shipping_cost' => $row[2],
					'free_over'     => $row[3],
					'cod'           => $row[4],
					'cod_fee'       => $row[5],
				)
			);
			true === $ok ? ++$imported : ++$skipped;
		}
		return array(
			'imported' => $imported,
			'skipped'  => $skipped,
		);
	}
}

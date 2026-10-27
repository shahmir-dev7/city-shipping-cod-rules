<?php
/**
 * City and money normalisation (pure, no WordPress calls).
 *
 * @package CityRules
 */

namespace CityRules;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizer.
 */
class Normalizer {

	/**
	 * Comparable key for a city name: lower case, letters and digits only. "Lahore ", "lahore" and
	 * "LA-HORE" all match. Accents are not folded, so "Zürich" and "Zurich" are different cities.
	 *
	 * @param string $city Raw city.
	 * @return string Empty when nothing usable is left. At most 100 characters.
	 */
	public static function city_key( $city ) {
		$key = (string) preg_replace( '/[^\p{L}\p{N}]+/u', '', mb_strtolower( trim( (string) $city ), 'UTF-8' ) );
		return mb_substr( $key, 0, 100, 'UTF-8' );
	}

	/**
	 * Two-letter upper-case country code, or '' for "any country".
	 *
	 * @param string $country Raw country.
	 * @return string
	 */
	public static function country( $country ) {
		$country = strtoupper( trim( (string) $country ) );
		return 1 === preg_match( '/^[A-Z]{2}$/', $country ) ? $country : '';
	}

	/**
	 * Parse an optional money amount. '' means "not set" (null). Accepts "1500", "1500.50", "1,500.50" and
	 * "1500,50"; rejects negatives and text.
	 *
	 * @param mixed $value Raw value.
	 * @return float|null|false Amount, null when empty, false when invalid.
	 */
	public static function money( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}
		if ( 1 === preg_match( '/^\d{1,3}(,\d{3})+(\.\d+)?$/', $value ) ) {
			$value = str_replace( ',', '', $value ); // Thousands separators.
		} elseif ( 1 === preg_match( '/^\d+,\d{1,2}$/', $value ) ) {
			$value = str_replace( ',', '.', $value ); // Decimal comma.
		}
		if ( 1 !== preg_match( '/^\d+(\.\d+)?$/', $value ) ) {
			return false;
		}
		return round( (float) $value, 2 );
	}

	/**
	 * COD setting: 'default', 'yes' or 'no'.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function cod( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return in_array( $value, array( 'yes', 'no' ), true ) ? $value : 'default';
	}
}

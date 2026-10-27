<?php
/**
 * CSV cell safety (pure).
 *
 * @package CityRules
 */

namespace CityRules;

defined( 'ABSPATH' ) || exit;

/**
 * Csv. Spreadsheets run cells that start with = + - or @ as formulas, so exports prefix them with a quote.
 */
class Csv {

	/**
	 * Neutralise a cell for export.
	 *
	 * @param string $value Cell value.
	 * @return string
	 */
	public static function safe( $value ) {
		$value = (string) $value;
		return ( '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ) ? "'" . $value : $value;
	}

	/**
	 * Undo safe() on import.
	 *
	 * @param string $value Cell value.
	 * @return string
	 */
	public static function unsafe( $value ) {
		$value = (string) $value;
		return ( strlen( $value ) > 1 && "'" === $value[0] && false !== strpos( "=+-@\t\r", $value[1] ) ) ? substr( $value, 1 ) : $value;
	}
}

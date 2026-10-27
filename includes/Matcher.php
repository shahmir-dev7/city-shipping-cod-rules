<?php
/**
 * What a rule means for shipping and COD (pure, no WordPress calls).
 *
 * @package CityRules
 */

namespace CityRules;

defined( 'ABSPATH' ) || exit;

/**
 * Matcher. A rule is an array with keys shipping_cost, free_over, cod, cod_fee (as stored); null when no rule matched.
 */
class Matcher {

	/**
	 * Shipping cost for a package.
	 *
	 * @param array<string,mixed>|null $rule          Matched rule.
	 * @param float                    $contents_cost Value of the items in the package.
	 * @param float|null               $default_cost  Method-level default for unmatched cities; null means no rate.
	 * @return float|null Cost, or null when no rate should be offered.
	 */
	public static function shipping_cost( $rule, $contents_cost, $default_cost ) {
		$cost = null === $rule ? null : ( $rule['shipping_cost'] ?? null );
		if ( null === $cost ) {
			return $default_cost;
		}
		$free_over = $rule['free_over'] ?? null;
		if ( null !== $free_over && $contents_cost >= (float) $free_over ) {
			return 0.0;
		}
		return (float) $cost;
	}

	/**
	 * May cash on delivery be offered for this city? Only an explicit 'no' blocks it.
	 *
	 * @param array<string,mixed>|null $rule Matched rule.
	 * @return bool
	 */
	public static function cod_allowed( $rule ) {
		return ! ( null !== $rule && 'no' === ( $rule['cod'] ?? '' ) );
	}

	/**
	 * COD fee for this city. A rule's fee wins, including an explicit 0.
	 *
	 * @param array<string,mixed>|null $rule        Matched rule.
	 * @param float                    $default_fee Store-wide default.
	 * @return float
	 */
	public static function cod_fee( $rule, $default_fee ) {
		$fee = null === $rule ? null : ( $rule['cod_fee'] ?? null );
		if ( null !== $fee ) {
			return max( 0.0, (float) $fee );
		}
		return max( 0.0, (float) $default_fee );
	}
}

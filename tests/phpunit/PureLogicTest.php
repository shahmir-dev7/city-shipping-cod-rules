<?php
/**
 * Pure-logic tests: normalisation, rule meaning, CSV safety.
 *
 * @package CityRules
 */

use CityRules\Csv;
use CityRules\Matcher;
use CityRules\Normalizer;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CityRules\Normalizer
 * @covers \CityRules\Matcher
 * @covers \CityRules\Csv
 */
class PureLogicTest extends TestCase {

	public function test_city_key_ignores_case_spaces_and_punctuation() {
		$this->assertSame( 'lahore', Normalizer::city_key( ' Lahore ' ) );
		$this->assertSame( 'lahore', Normalizer::city_key( 'LA-HORE' ) );
		$this->assertSame( 'newyork', Normalizer::city_key( 'New  York.' ) );
		$this->assertSame( 'karachi2', Normalizer::city_key( 'Karachi #2' ) );
		$this->assertSame( '', Normalizer::city_key( '  -- ' ) );
		$this->assertSame( '', Normalizer::city_key( '' ) );
	}

	public function test_city_key_handles_non_latin_and_is_capped() {
		$this->assertSame( 'لاہور', Normalizer::city_key( 'لاہور ' ) );
		$this->assertSame( 'zürich', Normalizer::city_key( 'Zürich' ), 'Accents are kept, not folded.' );
		$this->assertSame( 100, mb_strlen( Normalizer::city_key( str_repeat( 'a', 150 ) ), 'UTF-8' ) );
	}

	public function test_country() {
		$this->assertSame( 'PK', Normalizer::country( ' pk ' ) );
		$this->assertSame( '', Normalizer::country( '' ) );
		$this->assertSame( '', Normalizer::country( 'PAK' ) );
		$this->assertSame( '', Normalizer::country( '1A' ) );
	}

	public function test_money_parsing() {
		$this->assertNull( Normalizer::money( '' ) );
		$this->assertNull( Normalizer::money( '  ' ) );
		$this->assertSame( 0.0, Normalizer::money( '0' ) );
		$this->assertSame( 150.0, Normalizer::money( '150' ) );
		$this->assertSame( 150.5, Normalizer::money( '150.50' ) );
		$this->assertSame( 1500.5, Normalizer::money( '1,500.50' ) );
		$this->assertSame( 1500.0, Normalizer::money( '1,500' ) );
		$this->assertSame( 150.5, Normalizer::money( '150,50' ) );
		$this->assertFalse( Normalizer::money( '-5' ) );
		$this->assertFalse( Normalizer::money( 'abc' ) );
		$this->assertFalse( Normalizer::money( '12abc' ) );
		$this->assertFalse( Normalizer::money( '1.2.3' ) );
	}

	public function test_cod_setting() {
		$this->assertSame( 'no', Normalizer::cod( ' NO ' ) );
		$this->assertSame( 'yes', Normalizer::cod( 'Yes' ) );
		$this->assertSame( 'default', Normalizer::cod( '' ) );
		$this->assertSame( 'default', Normalizer::cod( 'maybe' ) );
	}

	public function test_shipping_cost_rules() {
		$this->assertSame( 200.0, Matcher::shipping_cost( array( 'shipping_cost' => 200.0, 'free_over' => null ), 100.0, 50.0 ) );
		$this->assertSame( 0.0, Matcher::shipping_cost( array( 'shipping_cost' => 200.0, 'free_over' => 1000.0 ), 1000.0, null ), 'At the threshold is free.' );
		$this->assertSame( 200.0, Matcher::shipping_cost( array( 'shipping_cost' => 200.0, 'free_over' => 1000.0 ), 999.99, null ) );
		$this->assertSame( 0.0, Matcher::shipping_cost( array( 'shipping_cost' => 0.0, 'free_over' => null ), 10.0, 50.0 ), 'An explicit 0 beats the default.' );
	}

	public function test_unmatched_or_costless_rules_fall_back_to_the_default() {
		$this->assertSame( 75.0, Matcher::shipping_cost( null, 100.0, 75.0 ) );
		$this->assertNull( Matcher::shipping_cost( null, 100.0, null ), 'No rule and no default: no rate.' );
		$this->assertSame( 75.0, Matcher::shipping_cost( array( 'shipping_cost' => null, 'free_over' => 10.0 ), 100.0, 75.0 ), 'A rule without a cost does not make shipping free.' );
	}

	public function test_cod_allowed_only_blocks_on_explicit_no() {
		$this->assertTrue( Matcher::cod_allowed( null ) );
		$this->assertTrue( Matcher::cod_allowed( array( 'cod' => 'default' ) ) );
		$this->assertTrue( Matcher::cod_allowed( array( 'cod' => 'yes' ) ) );
		$this->assertFalse( Matcher::cod_allowed( array( 'cod' => 'no' ) ) );
		$this->assertTrue( Matcher::cod_allowed( array() ) );
	}

	public function test_cod_fee() {
		$this->assertSame( 50.0, Matcher::cod_fee( null, 50.0 ) );
		$this->assertSame( 30.0, Matcher::cod_fee( array( 'cod_fee' => 30.0 ), 50.0 ) );
		$this->assertSame( 0.0, Matcher::cod_fee( array( 'cod_fee' => 0.0 ), 50.0 ), 'An explicit 0 means no fee here.' );
		$this->assertSame( 50.0, Matcher::cod_fee( array( 'cod_fee' => null ), 50.0 ) );
		$this->assertSame( 0.0, Matcher::cod_fee( null, -5.0 ), 'Never negative.' );
	}

	public function test_csv_cells_are_neutralised_and_restored() {
		$this->assertSame( "'=cmd()", Csv::safe( '=cmd()' ) );
		$this->assertSame( "'@x", Csv::safe( '@x' ) );
		$this->assertSame( 'Lahore', Csv::safe( 'Lahore' ) );
		$this->assertSame( '=cmd()', Csv::unsafe( "'=cmd()" ) );
		$this->assertSame( "'plain", Csv::unsafe( "'plain" ), 'Only an apostrophe in front of a formula character is removed.' );
		$this->assertSame( '', Csv::safe( '' ) );
	}
}
<?php
/**
 * WP-CLI commands.
 *
 * @package CityRules
 */

namespace CityRules;

defined( 'ABSPATH' ) || exit;

/**
 * Manage city rules.
 */
class Cli {

	/**
	 * List the rules.
	 *
	 * @subcommand list
	 */
	public function list_(): void {
		$rows = array();
		foreach ( Rules::all() as $r ) {
			$rows[] = array(
				'country' => $r['country'],
				'city'    => $r['city_label'],
				'ship'    => null === $r['shipping_cost'] ? '' : $r['shipping_cost'],
				'free'    => null === $r['free_over'] ? '' : $r['free_over'],
				'cod'     => $r['cod'],
				'cod_fee' => null === $r['cod_fee'] ? '' : $r['cod_fee'],
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'country', 'city', 'ship', 'free', 'cod', 'cod_fee' ) );
	}

	/**
	 * Import rules from a CSV file (columns: country, city, shipping_cost, free_over, cod, cod_fee).
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the CSV file.
	 *
	 * @param array<int,string> $args Positional args.
	 */
	public function import( $args ): void {
		if ( ! is_readable( $args[0] ) ) {
			\WP_CLI::error( 'Cannot read that file.' );
		}
		$lines  = (array) file( $args[0], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		$result = Rules::import_lines( $lines );
		\WP_CLI::success( sprintf( 'Imported %d, skipped %d.', $result['imported'], $result['skipped'] ) );
	}

	/**
	 * Print the rules as CSV.
	 */
	public function export(): void {
		\WP_CLI::log( rtrim( Rules::export_csv() ) );
	}
}

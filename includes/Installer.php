<?php
/**
 * Table, versioned migrations.
 *
 * @package CityRules
 */

namespace CityRules;

defined( 'ABSPATH' ) || exit;

/**
 * Installer.
 */
class Installer {

	const DB_VERSION = '1';

	/**
	 * Rules table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'cityrules_rules';
	}

	/**
	 * Activation hook, multisite aware.
	 *
	 * @param bool $network_wide Network activation.
	 */
	public static function activate( $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $blog_id ) {
				switch_to_blog( (int) $blog_id );
				self::install();
				restore_current_blog();
			}
			return;
		}
		self::install();
	}

	/**
	 * Create or upgrade the table for the current site.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			'CREATE TABLE ' . self::table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			country varchar(2) NOT NULL DEFAULT '',
			city_key varchar(100) NOT NULL,
			city_label varchar(100) NOT NULL,
			shipping_cost decimal(12,2) DEFAULT NULL,
			free_over decimal(12,2) DEFAULT NULL,
			cod varchar(7) NOT NULL DEFAULT 'default',
			cod_fee decimal(12,2) DEFAULT NULL,
			updated datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY country_city (country,city_key)
		) $charset;"
		);
		// Autoloaded on purpose: maybe_upgrade() reads it on every request.
		update_option( 'cityrules_db_version', self::DB_VERSION, true );
	}

	/**
	 * Run migrations when the stored version is behind.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( 'cityrules_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Install the table for sites created after network activation.
	 *
	 * @param \WP_Site $site New site.
	 */
	public static function new_site( $site ): void {
		if ( is_plugin_active_for_network( plugin_basename( CITYRULES_FILE ) ) ) {
			switch_to_blog( (int) $site->blog_id );
			self::install();
			restore_current_blog();
		}
	}
}

<?php
defined( 'ABSPATH' ) || exit;

class WPSB_Installer {

	public static function activate() {
		self::create_tables();
		self::seed_options();
	}

	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$prefix  = $wpdb->prefix . 'wpsb_';

		$sql = array();

		$sql[] = "CREATE TABLE {$prefix}object_map (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source_site VARCHAR(191) NOT NULL,
			target_site VARCHAR(191) NOT NULL,
			object_type VARCHAR(40) NOT NULL,
			source_id BIGINT UNSIGNED NOT NULL,
			target_id BIGINT UNSIGNED NOT NULL,
			source_key VARCHAR(191) DEFAULT '',
			target_key VARCHAR(191) DEFAULT '',
			checksum VARCHAR(64) DEFAULT '',
			last_status VARCHAR(40) DEFAULT '',
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY source_target_object (source_site, target_site, object_type, source_id),
			KEY target_lookup (target_site, object_type, target_id),
			KEY source_key (source_key)
		) {$charset};";

		$sql[] = "CREATE TABLE {$prefix}jobs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			job_uuid VARCHAR(64) NOT NULL,
			operation VARCHAR(40) NOT NULL,
			connection_id VARCHAR(64) NOT NULL,
			source_product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			target_product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(40) NOT NULL DEFAULT 'pending',
			message TEXT NULL,
			attempts INT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY job_uuid (job_uuid),
			KEY operation_status (operation, status),
			KEY connection_id (connection_id)
		) {$charset};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}

	private static function seed_options() {
		if ( false === get_option( 'wpsb_connections', false ) ) {
			add_option( 'wpsb_connections', array(), '', 'no' );
		}
		if ( false === get_option( 'wpsb_settings', false ) ) {
			add_option(
				'wpsb_settings',
				array(
					'batch_size' => 5,
					'timeout'    => 45,
					'retries'    => 3,
				),
				'',
				'no'
			);
		}
		if ( false === get_option( 'wpsb_local_secret', false ) ) {
			add_option( 'wpsb_local_secret', wp_generate_password( 48, false, false ), '', 'no' );
		}
		if ( false === get_option( 'wpsb_site_id', false ) ) {
			add_option( 'wpsb_site_id', wp_generate_uuid4(), '', 'no' );
		}
	}
}

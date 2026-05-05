<?php
defined( 'ABSPATH' ) || exit;

class WPSB_Mapping {

	public static function upsert( string $source_site, string $target_site, string $object_type, int $source_id, int $target_id, string $source_key = '', string $target_key = '', string $status = 'synced' ) {
		global $wpdb;

		$table = $wpdb->prefix . 'wpsb_object_map';
		$now   = current_time( 'mysql', true );
		$source_site = sanitize_text_field( $source_site );
		$target_site = sanitize_text_field( $target_site );
		$object_type = sanitize_key( $object_type );
		$source_key  = sanitize_text_field( $source_key );
		$target_key  = sanitize_text_field( $target_key );
		$status      = sanitize_key( $status );

		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE source_site = %s AND target_site = %s AND object_type = %s AND source_id = %d",
				$source_site,
				$target_site,
				$object_type,
				$source_id
			)
		);

		$data = array(
			'source_site' => $source_site,
			'target_site' => $target_site,
			'object_type' => $object_type,
			'source_id'   => $source_id,
			'target_id'   => $target_id,
			'source_key'  => $source_key,
			'target_key'  => $target_key,
			'last_status' => $status,
			'updated_at'  => $now,
		);

		if ( $existing_id ) {
			$wpdb->update(
				$table,
				$data,
				array( 'id' => absint( $existing_id ) ),
				array( '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
		} else {
			$wpdb->insert( $table, $data, array( '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' ) );
		}
	}
}

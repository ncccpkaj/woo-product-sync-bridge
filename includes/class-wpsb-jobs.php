<?php
defined( 'ABSPATH' ) || exit;

class WPSB_Jobs {

	const GROUP = 'woo-product-sync-bridge';

	public static function init() {
		add_action( 'wpsb_scheduled_transfer', array( __CLASS__, 'run_transfer' ), 10, 3 );
		add_action( 'wpsb_scheduled_update', array( __CLASS__, 'run_update' ), 10, 5 );
	}

	public static function schedule_transfer( string $connection_id, int $product_id, int $attempt = 0, int $delay = 0 ) {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			if ( $delay > 0 && function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + $delay, 'wpsb_scheduled_transfer', array( $connection_id, $product_id, $attempt ), self::GROUP );
			} else {
				as_enqueue_async_action( 'wpsb_scheduled_transfer', array( $connection_id, $product_id, $attempt ), self::GROUP );
			}
		} else {
			wp_schedule_single_event( time() + max( 30, $delay ), 'wpsb_scheduled_transfer', array( $connection_id, $product_id, $attempt ) );
		}
		self::record_job( 'transfer', $connection_id, $product_id, 0, 'pending', 'Transfer scheduled.' );
	}

	public static function schedule_update( string $connection_id, int $product_id, int $target_id, string $part, int $attempt = 0, int $delay = 0 ) {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			if ( $delay > 0 && function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + $delay, 'wpsb_scheduled_update', array( $connection_id, $product_id, $target_id, $part, $attempt ), self::GROUP );
			} else {
				as_enqueue_async_action( 'wpsb_scheduled_update', array( $connection_id, $product_id, $target_id, $part, $attempt ), self::GROUP );
			}
		} else {
			wp_schedule_single_event( time() + max( 30, $delay ), 'wpsb_scheduled_update', array( $connection_id, $product_id, $target_id, $part, $attempt ) );
		}
		self::record_job( 'update', $connection_id, $product_id, $target_id, 'pending', 'Update scheduled.' );
	}

	public static function run_transfer( string $connection_id, int $product_id, int $attempt = 0 ) {
		$connection = WPSB_Connections::get( $connection_id );
		if ( ! $connection ) {
			WPSB_Logger::write( 'Scheduled transfer skipped; connection missing.', array( 'connection_id' => $connection_id ) );
			return;
		}

		$payload = WPSB_Exporter::product( $product_id );
		if ( is_wp_error( $payload ) ) {
			WPSB_Logger::write( 'Scheduled transfer export failed.', array( 'product_id' => $product_id, 'error' => $payload->get_error_message() ) );
			return;
		}

		$result = WPSB_HTTP_Client::request( $connection, 'POST', 'products/import', array( 'product' => $payload ) );
		if ( is_wp_error( $result ) ) {
			WPSB_Logger::write( 'Scheduled transfer remote error.', array( 'product_id' => $product_id, 'error' => $result->get_error_message() ) );
			self::retry_transfer( $connection_id, $product_id, $attempt );
			return;
		}

		if ( empty( $result['success'] ) && 'sku_conflict' === ( $result['code'] ?? '' ) ) {
			WPSB_Logger::write( 'Scheduled transfer skipped due to SKU conflict.', array( 'product_id' => $product_id, 'conflict' => $result['conflict'] ?? array() ) );
			return;
		}

		WPSB_Logger::write( 'Scheduled transfer completed.', array( 'product_id' => $product_id, 'target' => $result['product']['id'] ?? 0 ) );
	}

	public static function run_update( string $connection_id, int $product_id, int $target_id, string $part, int $attempt = 0 ) {
		$connection = WPSB_Connections::get( $connection_id );
		if ( ! $connection ) {
			WPSB_Logger::write( 'Scheduled update skipped; connection missing.', array( 'connection_id' => $connection_id ) );
			return;
		}

		$payload = WPSB_Exporter::product( $product_id );
		if ( is_wp_error( $payload ) ) {
			WPSB_Logger::write( 'Scheduled update export failed.', array( 'product_id' => $product_id, 'error' => $payload->get_error_message() ) );
			return;
		}

		$result = WPSB_HTTP_Client::request(
			$connection,
			'POST',
			'products/update-partial',
			array(
				'product'           => $payload,
				'target_product_id' => $target_id,
				'part'              => $part,
			)
		);

		if ( is_wp_error( $result ) ) {
			WPSB_Logger::write( 'Scheduled update remote error.', array( 'product_id' => $product_id, 'target_id' => $target_id, 'error' => $result->get_error_message() ) );
			self::retry_update( $connection_id, $product_id, $target_id, $part, $attempt );
			return;
		}

		WPSB_Logger::write( 'Scheduled update completed.', array( 'product_id' => $product_id, 'target_id' => $target_id, 'part' => $part ) );
	}

	private static function record_job( string $operation, string $connection_id, int $source_id, int $target_id, string $status, string $message ) {
		global $wpdb;

		$table = $wpdb->prefix . 'wpsb_jobs';
		$now   = current_time( 'mysql', true );

		$wpdb->insert(
			$table,
			array(
				'job_uuid'          => wp_generate_uuid4(),
				'operation'         => $operation,
				'connection_id'     => $connection_id,
				'source_product_id' => $source_id,
				'target_product_id' => $target_id,
				'status'            => $status,
				'message'           => $message,
				'created_at'        => $now,
				'updated_at'        => $now,
			)
		);
	}

	private static function retry_transfer( string $connection_id, int $product_id, int $attempt ) {
		$max_attempts = absint( WPSB_Utils::settings()['retries'] );
		if ( $attempt >= $max_attempts ) {
			WPSB_Logger::write( 'Scheduled transfer retry limit reached.', array( 'product_id' => $product_id, 'attempt' => $attempt ) );
			return;
		}

		$next_attempt = $attempt + 1;
		$delay        = min( 900, 60 * $next_attempt );
		self::schedule_transfer( $connection_id, $product_id, $next_attempt, $delay );
		WPSB_Logger::write( 'Scheduled transfer retry queued.', array( 'product_id' => $product_id, 'attempt' => $next_attempt, 'delay' => $delay ) );
	}

	private static function retry_update( string $connection_id, int $product_id, int $target_id, string $part, int $attempt ) {
		$max_attempts = absint( WPSB_Utils::settings()['retries'] );
		if ( $attempt >= $max_attempts ) {
			WPSB_Logger::write( 'Scheduled update retry limit reached.', array( 'product_id' => $product_id, 'target_id' => $target_id, 'attempt' => $attempt ) );
			return;
		}

		$next_attempt = $attempt + 1;
		$delay        = min( 900, 60 * $next_attempt );
		self::schedule_update( $connection_id, $product_id, $target_id, $part, $next_attempt, $delay );
		WPSB_Logger::write( 'Scheduled update retry queued.', array( 'product_id' => $product_id, 'target_id' => $target_id, 'attempt' => $next_attempt, 'delay' => $delay ) );
	}
}

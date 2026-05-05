<?php
defined( 'ABSPATH' ) || exit;

class WPSB_Ajax {

	public static function init() {
		$actions = array(
			'wpsb_test_connection'  => 'test_connection',
			'wpsb_clear_log'        => 'clear_log',
			'wpsb_regenerate_secret'=> 'regenerate_secret',
			'wpsb_transfer_batch'   => 'transfer_batch',
			'wpsb_replace_products' => 'replace_products',
			'wpsb_search_products'  => 'search_products',
			'wpsb_update_product'   => 'update_product',
		);

		foreach ( $actions as $action => $method ) {
			add_action( 'wp_ajax_' . $action, array( __CLASS__, $method ) );
		}
	}

	private static function guard() {
		check_ajax_referer( 'wpsb_admin', 'nonce' );
		if ( ! WPSB_Utils::can_manage() ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
		}
		WPSB_Utils::no_cache_headers();
	}

	public static function test_connection() {
		self::guard();
		$connection = self::posted_connection();
		if ( ! $connection ) {
			wp_send_json_error( array( 'message' => 'Connection not found.' ) );
		}

		$result = WPSB_HTTP_Client::request( $connection, 'GET', 'site-info' );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	public static function regenerate_secret() {
		self::guard();
		$secret = wp_generate_password( 48, false, false );
		update_option( 'wpsb_local_secret', $secret, false );
		wp_send_json_success( array( 'secret' => $secret ) );
	}

	public static function clear_log() {
		self::guard();
		WPSB_Logger::clear();
		wp_send_json_success( array( 'message' => 'Logs cleared.' ) );
	}

	public static function transfer_batch() {
		self::guard();

		$connection = WPSB_Connections::get( sanitize_key( $_POST['connection_id'] ?? '' ) );
		$product_ids = array_map( 'absint', (array) ( $_POST['product_ids'] ?? array() ) );
		$method = sanitize_key( $_POST['method'] ?? 'instant' );

		if ( ! $connection || empty( $product_ids ) ) {
			wp_send_json_error( array( 'message' => 'Missing connection or products.' ) );
		}

		if ( 'scheduled' === $method ) {
			foreach ( $product_ids as $product_id ) {
				WPSB_Jobs::schedule_transfer( $connection['id'], $product_id );
			}
			wp_send_json_success( array( 'logs' => array( 'Scheduled ' . count( $product_ids ) . ' product transfer jobs.' ) ) );
		}

		$response = array(
			'logs'        => array(),
			'conflicts'   => array(),
			'completed'   => array(),
			'unsupported' => array(),
		);

		foreach ( $product_ids as $product_id ) {
			try {
				$product = wc_get_product( $product_id );
				$response['logs'][] = "Product {$product_id}: export started.";
				$payload = WPSB_Exporter::product( $product_id );
				if ( is_wp_error( $payload ) ) {
					$response['logs'][] = "Product {$product_id}: " . $payload->get_error_message();
					WPSB_Logger::write( 'Transfer export failed.', array( 'product_id' => $product_id, 'error' => $payload->get_error_message() ) );
					if ( 'wpsb_unsupported_type' === $payload->get_error_code() ) {
						$response['unsupported'][] = array(
							'id'    => $product_id,
							'title' => $product ? $product->get_name() : get_the_title( $product_id ),
							'type'  => $product ? $product->get_type() : '',
							'why'   => $payload->get_error_message(),
						);
					}
					continue;
				}

				$response['logs'][] = "Product {$product_id}: sending main data, images, taxonomy, attributes, variations and meta.";
				$result = WPSB_HTTP_Client::request( $connection, 'POST', 'products/import', array( 'product' => $payload ) );

				if ( is_wp_error( $result ) ) {
					$response['logs'][] = "Product {$product_id}: remote error - " . $result->get_error_message();
					WPSB_Logger::write( 'Transfer remote error.', array( 'product_id' => $product_id, 'error' => $result->get_error_message() ) );
					continue;
				}

				if ( empty( $result['success'] ) && 'sku_conflict' === ( $result['code'] ?? '' ) ) {
					$response['logs'][] = "Product {$product_id}: SKU conflict found.";
					$response['conflicts'][] = array(
						'source_id' => $product_id,
						'title'     => $product ? $product->get_name() : '',
						'sku'       => $product ? $product->get_sku() : '',
						'target'    => $result['conflict'] ?? array(),
					);
					continue;
				}

				if ( empty( $result['success'] ) ) {
					$response['logs'][] = "Product {$product_id}: transfer failed - " . ( $result['message'] ?? 'Unknown remote error.' );
					WPSB_Logger::write( 'Product transfer failed.', array( 'product_id' => $product_id, 'result' => $result ) );
					continue;
				}

				$response['logs'][] = "Product {$product_id}: transfer completed.";
				$response['completed'][] = $product_id;
				WPSB_Logger::write( 'Product transfer completed.', array( 'product_id' => $product_id, 'target' => $result['product']['id'] ?? 0 ) );
			} catch ( Throwable $e ) {
				$response['logs'][] = "Product {$product_id}: transfer exception - " . $e->getMessage();
				WPSB_Logger::write( 'Product transfer exception.', array( 'product_id' => $product_id, 'error' => $e->getMessage() ) );
			}
		}

		wp_send_json_success( $response );
	}

	public static function replace_products() {
		self::guard();

		$connection = WPSB_Connections::get( sanitize_key( $_POST['connection_id'] ?? '' ) );
		$items = self::posted_replace_items();
		if ( ! $connection || empty( $items ) ) {
			wp_send_json_error( array( 'message' => 'Missing connection or replacement items.' ) );
		}

		$logs      = array();
		$completed = array();
		$failed    = array();
		foreach ( $items as $item ) {
			$source_id = absint( $item['source_id'] ?? 0 );
			$target_id = absint( $item['target_id'] ?? 0 );
			if ( ! $source_id || ! $target_id ) {
				$failed[] = array(
					'source_id' => $source_id,
					'target_id' => $target_id,
					'message'   => 'Missing source or target ID.',
				);
				continue;
			}

			$payload = WPSB_Exporter::product( $source_id );
			if ( is_wp_error( $payload ) ) {
				$logs[] = "Product {$source_id}: " . $payload->get_error_message();
				$failed[] = array(
					'source_id' => $source_id,
					'target_id' => $target_id,
					'message'   => $payload->get_error_message(),
				);
				continue;
			}

			$result = WPSB_HTTP_Client::request( $connection, 'POST', 'products/replace', array( 'product' => $payload, 'target_product_id' => $target_id ) );
			if ( is_wp_error( $result ) ) {
				$logs[] = "Product {$source_id}: replace failed - " . $result->get_error_message();
				$failed[] = array(
					'source_id' => $source_id,
					'target_id' => $target_id,
					'message'   => $result->get_error_message(),
				);
			} elseif ( empty( $result['success'] ) ) {
				$message = $result['message'] ?? 'Unknown remote replace error.';
				$logs[] = "Product {$source_id}: replace failed - " . $message;
				$failed[] = array(
					'source_id' => $source_id,
					'target_id' => $target_id,
					'message'   => $message,
				);
			} else {
				$logs[] = "Product {$source_id}: target {$target_id} replaced.";
				$completed[] = array(
					'source_id' => $source_id,
					'target_id' => $target_id,
					'product'   => $result['product'] ?? array(),
				);
				WPSB_Logger::write( 'Product replace completed.', array( 'source_id' => $source_id, 'target_id' => $target_id ) );
			}
		}

		wp_send_json_success(
			array(
				'logs'      => $logs,
				'completed' => $completed,
				'failed'    => $failed,
			)
		);
	}

	public static function search_products() {
		self::guard();

		$connection = WPSB_Connections::get( sanitize_key( $_POST['connection_id'] ?? '' ) );
		$product_id = absint( $_POST['product_id'] ?? 0 );
		$mode = sanitize_key( $_POST['mode'] ?? 'sku' );
		$page = max( 1, absint( $_POST['page'] ?? 1 ) );
		$per_page = max( 1, min( 50, absint( $_POST['per_page'] ?? 20 ) ) );
		$product = wc_get_product( $product_id );

		if ( ! $connection || ! $product ) {
			wp_send_json_error( array( 'message' => 'Missing connection or product.' ) );
		}

		$term = 'sku' === $mode ? $product->get_sku() : $product->get_name();
		$result = WPSB_HTTP_Client::request(
			$connection,
			'POST',
			'products/search',
			array(
				'mode'     => $mode,
				'term'     => $term,
				'page'     => $page,
				'per_page' => $per_page,
			)
		);
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	public static function update_product() {
		self::guard();

		$connection = WPSB_Connections::get( sanitize_key( $_POST['connection_id'] ?? '' ) );
		$product_id = absint( $_POST['product_id'] ?? 0 );
		$target_id = absint( $_POST['target_product_id'] ?? 0 );
		$part = sanitize_key( $_POST['part'] ?? 'full' );
		$method = sanitize_key( $_POST['method'] ?? 'instant' );

		if ( ! $connection || ! $product_id || ! $target_id ) {
			wp_send_json_error( array( 'message' => 'Missing connection, source product, or target product.' ) );
		}

		if ( 'scheduled' === $method ) {
			WPSB_Jobs::schedule_update( $connection['id'], $product_id, $target_id, $part );
			wp_send_json_success( array( 'logs' => array( 'Scheduled product update job.' ) ) );
		}

		$payload = WPSB_Exporter::product( $product_id );
		if ( is_wp_error( $payload ) ) {
			wp_send_json_error( array( 'message' => $payload->get_error_message() ) );
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
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'logs' => array( $result['message'] ?? 'Product updated.' ), 'result' => $result ) );
	}

	private static function posted_connection() {
		$raw = isset( $_POST['connection'] ) ? wp_unslash( $_POST['connection'] ) : '';
		if ( $raw ) {
			$data = json_decode( $raw, true );
			if ( is_array( $data ) ) {
				$name   = sanitize_text_field( $data['name'] ?? 'Temporary connection' );
				$url    = WPSB_Utils::normalize_url( (string) ( $data['url'] ?? '' ) );
				$secret = sanitize_text_field( $data['secret'] ?? '' );
				if ( $url && $secret ) {
					return array(
						'id'      => sanitize_key( $data['id'] ?? 'temporary' ),
						'name'    => $name,
						'url'     => $url,
						'secret'  => $secret,
						'enabled' => 1,
					);
				}
			}
		}

		return WPSB_Connections::get( sanitize_key( $_POST['connection_id'] ?? '' ) );
	}

	private static function posted_replace_items(): array {
		$json = isset( $_POST['items_json'] ) ? wp_unslash( $_POST['items_json'] ) : '';
		if ( $json ) {
			$items = json_decode( $json, true );
			return is_array( $items ) ? $items : array();
		}

		return is_array( $_POST['items'] ?? null ) ? (array) $_POST['items'] : array();
	}
}

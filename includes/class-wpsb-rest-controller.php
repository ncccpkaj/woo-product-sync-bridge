<?php
defined( 'ABSPATH' ) || exit;

class WPSB_REST_Controller {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			WPSB_REST_NAMESPACE,
			'/site-info',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'site_info' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);

		register_rest_route(
			WPSB_REST_NAMESPACE,
			'/connection/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'connection_test' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);

		register_rest_route(
			WPSB_REST_NAMESPACE,
			'/products/search',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'products_search' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);

		register_rest_route(
			WPSB_REST_NAMESPACE,
			'/products/import',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'products_import' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);

		register_rest_route(
			WPSB_REST_NAMESPACE,
			'/products/replace',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'products_replace' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);

		register_rest_route(
			WPSB_REST_NAMESPACE,
			'/products/update-partial',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'products_update_partial' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
	}

	public static function permission( WP_REST_Request $request ) {
		WPSB_Utils::no_cache_headers();
		return WPSB_Auth::permission( $request );
	}

	public static function site_info(): WP_REST_Response {
		return rest_ensure_response(
			array(
				'success'      => true,
				'site_id'      => WPSB_Utils::site_id(),
				'site_name'    => get_bloginfo( 'name' ),
				'site_url'     => home_url(),
				'wp_version'   => get_bloginfo( 'version' ),
				'wc_version'   => defined( 'WC_VERSION' ) ? WC_VERSION : '',
				'plugin'       => WPSB_VERSION,
				'server_time'  => time(),
				'features'     => array( 'simple_products', 'variable_products', 'manual_transfer', 'manual_update' ),
			)
		);
	}

	public static function connection_test(): WP_REST_Response {
		return rest_ensure_response( array( 'success' => true, 'message' => 'Connection verified.' ) );
	}

	public static function products_search( WP_REST_Request $request ): WP_REST_Response {
		$mode = sanitize_key( $request->get_param( 'mode' ) );
		$term = substr( sanitize_text_field( $request->get_param( 'term' ) ), 0, 120 );
		$page = absint( $request->get_param( 'page' ) );
		$per_page = absint( $request->get_param( 'per_page' ) );
		$search = WPSB_Importer::search_products( 'sku' === $mode ? 'sku' : 'title', $term, $page ?: 1, $per_page ?: 20 );
		return rest_ensure_response(
			array(
				'success'  => true,
				'results'  => $search['items'],
				'page'     => $search['page'],
				'per_page' => $search['per_page'],
				'total'    => $search['total'],
				'has_more' => $search['has_more'],
			)
		);
	}

	public static function products_import( WP_REST_Request $request ): WP_REST_Response {
		try {
			$payload_error = self::validate_product_payload( $request );
			if ( is_wp_error( $payload_error ) ) {
				return rest_ensure_response( array( 'success' => false, 'message' => $payload_error->get_error_message() ) );
			}
			$payload = (array) $request->get_param( 'product' );
			return rest_ensure_response( WPSB_Importer::import_product( $payload, false, 0 ) );
		} catch ( Throwable $e ) {
			WPSB_Logger::write( 'Remote product import failed with exception.', array( 'error' => $e->getMessage() ) );
			return rest_ensure_response( array( 'success' => false, 'message' => 'Remote product import failed. Check the target site log for details.' ) );
		}
	}

	public static function products_replace( WP_REST_Request $request ): WP_REST_Response {
		try {
			$payload_error = self::validate_product_payload( $request );
			if ( is_wp_error( $payload_error ) ) {
				return rest_ensure_response( array( 'success' => false, 'message' => $payload_error->get_error_message() ) );
			}
			$payload   = (array) $request->get_param( 'product' );
			$target_id = absint( $request->get_param( 'target_product_id' ) );
			if ( ! $target_id ) {
				return rest_ensure_response( array( 'success' => false, 'message' => 'Target product ID is required for replace.' ) );
			}
			return rest_ensure_response( WPSB_Importer::import_product( $payload, true, $target_id ) );
		} catch ( Throwable $e ) {
			WPSB_Logger::write( 'Remote product replace failed with exception.', array( 'error' => $e->getMessage() ) );
			return rest_ensure_response( array( 'success' => false, 'message' => 'Remote product replace failed. Check the target site log for details.' ) );
		}
	}

	public static function products_update_partial( WP_REST_Request $request ): WP_REST_Response {
		try {
			$payload_error = self::validate_product_payload( $request );
			if ( is_wp_error( $payload_error ) ) {
				return rest_ensure_response( array( 'success' => false, 'message' => $payload_error->get_error_message() ) );
			}
			$payload   = (array) $request->get_param( 'product' );
			$target_id = absint( $request->get_param( 'target_product_id' ) );
			$part      = sanitize_key( $request->get_param( 'part' ) );
			if ( ! $target_id ) {
				return rest_ensure_response( array( 'success' => false, 'message' => 'Target product ID is required for update.' ) );
			}
			if ( ! in_array( $part, array( 'full', 'images', 'price', 'descriptions', 'meta' ), true ) ) {
				return rest_ensure_response( array( 'success' => false, 'message' => 'Unsupported update part.' ) );
			}
			return rest_ensure_response( WPSB_Importer::update_partial( $target_id, $payload, $part ) );
		} catch ( Throwable $e ) {
			WPSB_Logger::write( 'Remote partial update failed with exception.', array( 'error' => $e->getMessage() ) );
			return rest_ensure_response( array( 'success' => false, 'message' => 'Remote partial update failed. Check the target site log for details.' ) );
		}
	}

	private static function validate_product_payload( WP_REST_Request $request ) {
		$body_size = strlen( (string) $request->get_body() );
		if ( $body_size > WPSB_MAX_REST_BODY_BYTES ) {
			return new WP_Error( 'wpsb_payload_too_large', 'Product payload is too large for this endpoint.' );
		}

		$product = $request->get_param( 'product' );
		if ( ! is_array( $product ) || empty( $product['type'] ) || empty( $product['source_product_id'] ) ) {
			return new WP_Error( 'wpsb_invalid_payload', 'Product payload is missing required fields.' );
		}

		if ( ! in_array( sanitize_key( $product['type'] ), array( 'simple', 'variable' ), true ) ) {
			return new WP_Error( 'wpsb_unsupported_payload_type', 'Unsupported product type in payload.' );
		}

		return true;
	}
}

<?php
defined( 'ABSPATH' ) || exit;

class WPSB_Auth {

	public static function sign_headers( string $secret, string $method, string $path, string $body ): array {
		$timestamp = (string) time();
		$nonce     = wp_generate_password( 16, false, false );
		$payload   = strtoupper( $method ) . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . hash( 'sha256', $body );

		return array(
			'X-WPSB-Timestamp' => $timestamp,
			'X-WPSB-Nonce'     => $nonce,
			'X-WPSB-Signature' => hash_hmac( 'sha256', $payload, $secret ),
		);
	}

	public static function permission( WP_REST_Request $request ) {
		if ( self::is_rate_limited() ) {
			return new WP_Error( 'wpsb_rate_limited', 'Too many failed authentication attempts. Try again later.', array( 'status' => 429 ) );
		}

		if ( self::verify_request( $request ) ) {
			self::clear_failed_attempts();
			return true;
		}

		self::record_failed_attempt();
		return new WP_Error( 'wpsb_auth_failed', 'Woo Product Sync Bridge authentication failed.', array( 'status' => 401 ) );
	}

	public static function verify_request( WP_REST_Request $request ): bool {
		$timestamp = (int) $request->get_header( 'x-wpsb-timestamp' );
		$nonce     = (string) $request->get_header( 'x-wpsb-nonce' );
		$signature = (string) $request->get_header( 'x-wpsb-signature' );

		if ( ! $timestamp || '' === $nonce || '' === $signature || abs( time() - $timestamp ) > 300 ) {
			return false;
		}

		$nonce_key = 'wpsb_nonce_' . md5( $timestamp . '|' . $nonce . '|' . $signature );
		if ( get_transient( $nonce_key ) ) {
			return false;
		}

		$secret = (string) get_option( 'wpsb_local_secret', '' );
		if ( '' === $secret ) {
			return false;
		}

		$route   = $request->get_route();
		$path    = 0 === strpos( $route, '/' . WPSB_REST_NAMESPACE ) ? $route : '/' . WPSB_REST_NAMESPACE . $route;
		$body    = (string) $request->get_body();
		$payload = strtoupper( $request->get_method() ) . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . hash( 'sha256', $body );
		$expect  = hash_hmac( 'sha256', $payload, $secret );

		$valid = hash_equals( $expect, $signature );
		if ( $valid ) {
			set_transient( $nonce_key, 1, 5 * MINUTE_IN_SECONDS );
		}

		return $valid;
	}

	private static function client_key(): string {
		$ip = '';
		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		return 'wpsb_auth_fail_' . md5( $ip ?: 'unknown' );
	}

	private static function is_rate_limited(): bool {
		return absint( get_transient( self::client_key() ) ) >= 20;
	}

	private static function record_failed_attempt() {
		$key   = self::client_key();
		$count = absint( get_transient( $key ) );
		set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );
	}

	private static function clear_failed_attempts() {
		delete_transient( self::client_key() );
	}
}

<?php
defined( 'ABSPATH' ) || exit;

class WPSB_HTTP_Client {

	public static function request( array $connection, string $method, string $route, array $payload = array() ) {
		$settings = WPSB_Utils::settings();
		$body     = 'GET' === strtoupper( $method ) ? '' : wp_json_encode( $payload );
		$path     = '/' . WPSB_REST_NAMESPACE . '/' . ltrim( $route, '/' );
		$url      = trailingslashit( $connection['url'] ) . 'wp-json' . $path;
		$headers  = WPSB_Auth::sign_headers( $connection['secret'], $method, $path, $body );

		$headers['Content-Type'] = 'application/json';
		$headers['Accept']       = 'application/json';
		$headers['Cache-Control'] = 'no-cache';

		$args = array(
			'method'      => strtoupper( $method ),
			'timeout'     => absint( $settings['timeout'] ),
			'headers'     => $headers,
			'redirection' => 3,
			'sslverify'   => apply_filters( 'wpsb_sslverify', true, $connection ),
		);

		if ( '' !== $body ) {
			$args['body'] = $body;
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $json ) && ! empty( $json['message'] ) ? $json['message'] : sprintf( 'Remote request failed with HTTP %d.', $code );
			if ( is_array( $json ) && ! empty( $json['data']['message'] ) ) {
				$message = $json['data']['message'];
			}
			return new WP_Error( 'wpsb_remote_error', $message, array( 'response' => $json, 'status' => $code ) );
		}

		return is_array( $json ) ? $json : array();
	}
}

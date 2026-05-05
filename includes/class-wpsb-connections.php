<?php
defined( 'ABSPATH' ) || exit;

class WPSB_Connections {

	public static function all( bool $active_only = false ): array {
		$connections = get_option( 'wpsb_connections', array() );
		if ( ! is_array( $connections ) ) {
			return array();
		}

		$connections = array_map(
			static function ( $connection ) {
				if ( isset( $connection['secret'] ) ) {
					$connection['secret'] = self::decrypt_secret( (string) $connection['secret'] );
				}
				return $connection;
			},
			$connections
		);

		if ( $active_only ) {
			$connections = array_filter(
				$connections,
				static function ( $connection ) {
					return ! empty( $connection['enabled'] );
				}
			);
		}

		return array_values( $connections );
	}

	public static function get( string $id ): ?array {
		foreach ( self::all() as $connection ) {
			if ( isset( $connection['id'] ) && $connection['id'] === $id ) {
				return $connection;
			}
		}
		return null;
	}

	public static function save_all( array $connections ) {
		$clean = array();

		foreach ( $connections as $connection ) {
			$name   = sanitize_text_field( $connection['name'] ?? '' );
			$url    = WPSB_Utils::normalize_url( (string) ( $connection['url'] ?? '' ) );
			$secret = sanitize_text_field( $connection['secret'] ?? '' );

			if ( '' === $name || '' === $url || '' === $secret ) {
				continue;
			}

			$clean[] = array(
				'id'           => sanitize_key( $connection['id'] ?? wp_generate_uuid4() ),
				'name'         => $name,
				'url'          => $url,
				'secret'       => self::encrypt_secret( $secret ),
				'enabled'      => ! empty( $connection['enabled'] ) ? 1 : 0,
				'last_status'  => sanitize_text_field( $connection['last_status'] ?? '' ),
				'last_checked' => sanitize_text_field( $connection['last_checked'] ?? '' ),
			);
		}

		update_option( 'wpsb_connections', $clean, false );
	}

	public static function has_active(): bool {
		return ! empty( self::all( true ) );
	}

	public static function public_list(): array {
		return array_map(
			static function ( $connection ) {
				return array(
					'id'      => $connection['id'],
					'name'    => $connection['name'],
					'url'     => $connection['url'],
					'enabled' => ! empty( $connection['enabled'] ),
				);
			},
			self::all( true )
		);
	}

	private static function encryption_key(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ), true );
	}

	public static function encrypt_secret( string $secret ): string {
		if ( '' === $secret || 0 === strpos( $secret, 'wpsbenc:' ) || 0 === strpos( $secret, 'wpsbenc2:' ) ) {
			return $secret;
		}

		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return $secret;
		}

		try {
			if ( function_exists( 'openssl_get_cipher_methods' ) && in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) ) {
				$iv         = random_bytes( 12 );
				$tag        = '';
				$ciphertext = openssl_encrypt( $secret, 'aes-256-gcm', self::encryption_key(), OPENSSL_RAW_DATA, $iv, $tag );
				if ( false !== $ciphertext && '' !== $tag ) {
					return 'wpsbenc2:' . base64_encode( $iv . $tag . $ciphertext );
				}
			}

			$iv = random_bytes( 16 );
		} catch ( Exception $e ) {
			return $secret;
		}

		$ciphertext = openssl_encrypt( $secret, 'aes-256-cbc', self::encryption_key(), OPENSSL_RAW_DATA, $iv );
		if ( false === $ciphertext ) {
			return $secret;
		}

		return 'wpsbenc:' . base64_encode( $iv . $ciphertext );
	}

	public static function decrypt_secret( string $secret ): string {
		if ( 0 === strpos( $secret, 'wpsbenc2:' ) && function_exists( 'openssl_decrypt' ) ) {
			$raw = base64_decode( substr( $secret, 9 ), true );
			if ( false === $raw || strlen( $raw ) <= 28 ) {
				return '';
			}
			$iv         = substr( $raw, 0, 12 );
			$tag        = substr( $raw, 12, 16 );
			$ciphertext = substr( $raw, 28 );
			$plain      = openssl_decrypt( $ciphertext, 'aes-256-gcm', self::encryption_key(), OPENSSL_RAW_DATA, $iv, $tag );
			return false === $plain ? '' : $plain;
		}

		if ( 0 !== strpos( $secret, 'wpsbenc:' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return $secret;
		}

		$raw = base64_decode( substr( $secret, 8 ), true );
		if ( false === $raw || strlen( $raw ) <= 16 ) {
			return '';
		}

		$iv = substr( $raw, 0, 16 );
		$ciphertext = substr( $raw, 16 );
		$plain = openssl_decrypt( $ciphertext, 'aes-256-cbc', self::encryption_key(), OPENSSL_RAW_DATA, $iv );

		return false === $plain ? '' : $plain;
	}
}

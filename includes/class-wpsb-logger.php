<?php
defined( 'ABSPATH' ) || exit;

class WPSB_Logger {

	public static function path(): string {
		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . 'woo-product-sync-bridge';

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$index = trailingslashit( $dir ) . 'index.html';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$web_config = trailingslashit( $dir ) . 'web.config';
		if ( ! file_exists( $web_config ) ) {
			file_put_contents(
				$web_config,
				"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n"
			); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		return trailingslashit( $dir ) . 'sync.log';
	}

	public static function write( string $message, array $context = array() ) {
		$line = sprintf(
			"[%s] %s %s\n",
			gmdate( 'Y-m-d H:i:s' ),
			$message,
			$context ? wp_json_encode( $context ) : ''
		);

		file_put_contents( self::path(), $line, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	public static function recent( int $lines = 150 ): string {
		$path = self::path();
		if ( ! file_exists( $path ) ) {
			return '';
		}

		$content = file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file
		if ( ! is_array( $content ) ) {
			return '';
		}

		return implode( "\n", array_slice( $content, -absint( $lines ) ) );
	}

	public static function clear(): bool {
		return false !== file_put_contents( self::path(), '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}
}

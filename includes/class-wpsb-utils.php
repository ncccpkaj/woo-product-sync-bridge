<?php
defined( 'ABSPATH' ) || exit;

class WPSB_Utils {

	public static function settings(): array {
		$settings = get_option( 'wpsb_settings', array() );
		return wp_parse_args(
			is_array( $settings ) ? $settings : array(),
			array(
				'batch_size' => 5,
				'timeout'    => 45,
				'retries'    => 3,
			)
		);
	}

	public static function site_id(): string {
		$site_id = (string) get_option( 'wpsb_site_id', '' );
		if ( '' === $site_id ) {
			$site_id = wp_generate_uuid4();
			update_option( 'wpsb_site_id', $site_id, false );
		}
		return $site_id;
	}

	public static function can_manage(): bool {
		return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
	}

	public static function normalize_url( string $url ): string {
		$url = trim( esc_url_raw( $url ) );
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}
		return untrailingslashit( $url );
	}

	public static function no_cache_headers() {
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
			header( 'Pragma: no-cache' );
		}
	}

	public static function blacklist_meta_key( string $key ): bool {
		$exact = array(
			'_edit_lock',
			'_edit_last',
			'_wp_old_slug',
			'_wp_trash_meta_status',
			'_wp_trash_meta_time',
			'_thumbnail_id',
			'_product_image_gallery',
			'_wc_average_rating',
			'_wc_review_count',
			'_wc_rating_count',
			'_wc_rating_count_1',
			'_wc_rating_count_2',
			'_wc_rating_count_3',
			'_wc_rating_count_4',
			'_wc_rating_count_5',
			'_wc_product_children',
			'_wp_attachment_metadata',
			'_wp_attached_file',
			'_wp_page_template',
			'_wpml_media_duplicate',
			'_wpml_media_featured',
			'total_sales',
		);

		if ( in_array( $key, $exact, true ) ) {
			return true;
		}

		$prefixes = array(
			'_transient_',
			'_site_transient_',
			'_oembed_',
			'_wpsb_',
			'_wc_product_meta_lookup',
			'_elementor_css',
		);

		foreach ( $prefixes as $prefix ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	public static function clean_text_list( array $values ): array {
		return array_values(
			array_filter(
				array_map(
					static function ( $value ) {
						return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
					},
					$values
				)
			)
		);
	}
}

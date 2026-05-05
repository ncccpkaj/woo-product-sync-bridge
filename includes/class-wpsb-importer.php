<?php
defined( 'ABSPATH' ) || exit;

class WPSB_Importer {

	private static $media_map = array();

	public static function import_product( array $payload, bool $replace = false, int $target_id = 0 ): array {
		self::$media_map = array();
		$type = sanitize_key( $payload['type'] ?? '' );
		if ( ! in_array( $type, array( 'simple', 'variable' ), true ) ) {
			return array( 'success' => false, 'message' => 'Unsupported product type.' );
		}

		$sku = sanitize_text_field( $payload['sku'] ?? '' );
		if ( ! $replace && $sku ) {
			$existing_id = self::find_product_id_by_sku( $sku );
			if ( $existing_id ) {
				return self::sku_conflict_response( $existing_id );
			}
		}

		if ( $replace && $target_id ) {
			$product = wc_get_product( $target_id );
			if ( ! $product ) {
				return array( 'success' => false, 'message' => 'Target product not found for replace.' );
			}
		} else {
			$product = 'variable' === $type ? new WC_Product_Variable() : new WC_Product_Simple();
		}

		try {
			self::apply_core_product_data( $product, $payload );
			$product_id = $product->save();
		} catch ( Exception $e ) {
			if ( $sku && false !== stripos( $e->getMessage(), 'sku' ) ) {
				$existing_id = self::find_product_id_by_sku( $sku );
				if ( $existing_id && ! $replace ) {
					return self::sku_conflict_response( $existing_id, $e->getMessage() );
				}
			}
			throw $e;
		}

		self::sync_taxonomies( $product_id, $payload['taxonomies'] ?? array() );
		self::sync_attributes( $product, $payload['attributes'] ?? array(), $payload['default_attributes'] ?? array() );
		self::sync_images( $product, $payload['images'] ?? array() );
		self::replace_meta( $product_id, $payload['meta'] ?? array() );
		$product->save();

		if ( 'variable' === $type ) {
			self::sync_variations( $product, $payload['variations'] ?? array() );
			WC_Product_Variable::sync( $product->get_id() );
		}

		wc_delete_product_transients( $product_id );

		WPSB_Mapping::upsert(
			(string) ( $payload['source_site'] ?? '' ),
			WPSB_Utils::site_id(),
			'product',
			absint( $payload['source_product_id'] ?? 0 ),
			$product_id,
			$sku,
			$product->get_sku(),
			'synced'
		);

		return array(
			'success' => true,
			'message' => $replace ? 'Product replaced.' : 'Product imported.',
			'product' => self::product_summary( $product_id ),
		);
	}

	public static function update_partial( int $target_id, array $payload, string $part ): array {
		self::$media_map = array();
		$product = wc_get_product( $target_id );
		if ( ! $product ) {
			return array( 'success' => false, 'message' => 'Target product not found.' );
		}

		if ( 'full' === $part ) {
			return self::import_product( $payload, true, $target_id );
		}

		switch ( $part ) {
			case 'images':
				self::sync_images( $product, $payload['images'] ?? array() );
				if ( $product->is_type( 'variable' ) && ! empty( $payload['variations'] ) ) {
					self::sync_variation_images_only( $product, $payload['variations'] );
				}
				break;
			case 'price':
				self::sync_price( $product, $payload );
				break;
			case 'descriptions':
				$product->set_description( wp_kses_post( $payload['description'] ?? '' ) );
				$product->set_short_description( wp_kses_post( $payload['short_description'] ?? '' ) );
				break;
			case 'meta':
				self::replace_meta( $target_id, $payload['meta'] ?? array() );
				break;
			default:
				return array( 'success' => false, 'message' => 'Unsupported update part.' );
		}

		$product->save();
		wc_delete_product_transients( $target_id );

		return array(
			'success' => true,
			'message' => 'Product updated.',
			'product' => self::product_summary( $target_id ),
		);
	}

	public static function search_products( string $mode, string $term, int $page = 1, int $per_page = 20 ): array {
		global $wpdb;

		$term = trim( $term );
		if ( '' === $term ) {
			return array( 'items' => array(), 'page' => 1, 'per_page' => $per_page, 'total' => 0, 'has_more' => false );
		}

		$ids = array();
		$page     = max( 1, absint( $page ) );
		$per_page = max( 1, min( 50, absint( $per_page ) ) );
		$offset   = ( $page - 1 ) * $per_page;
		$total    = 0;

		if ( 'sku' === $mode ) {
			$exact_id = self::find_product_id_by_sku( $term );
			if ( $exact_id ) {
				$ids[] = $exact_id;
			}
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT p.ID)
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
					WHERE p.post_type = 'product'
					AND p.post_status NOT IN ('trash', 'auto-draft')
					AND pm.meta_key = '_sku'
					AND pm.meta_value LIKE %s",
					'%' . $wpdb->esc_like( $term ) . '%'
				)
			);
			$like_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT p.ID
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
					WHERE p.post_type = 'product'
					AND p.post_status NOT IN ('trash', 'auto-draft')
					AND pm.meta_key = '_sku'
					AND pm.meta_value LIKE %s
					ORDER BY CASE WHEN pm.meta_value = %s THEN 0 ELSE 1 END, p.post_title ASC
					LIMIT %d OFFSET %d",
					'%' . $wpdb->esc_like( $term ) . '%',
					$term,
					$per_page,
					$offset
				)
			);
			$ids = array_merge( $ids, array_map( 'absint', $like_ids ) );
		} else {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status NOT IN ('trash', 'auto-draft') AND post_title LIKE %s",
					'%' . $wpdb->esc_like( $term ) . '%'
				)
			);
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status NOT IN ('trash', 'auto-draft') AND post_title LIKE %s ORDER BY post_title ASC LIMIT %d OFFSET %d",
					'%' . $wpdb->esc_like( $term ) . '%',
					$per_page,
					$offset
				)
			);
			$ids = array_map( 'absint', $ids );
		}

		$ids   = array_values( array_slice( array_unique( array_filter( $ids ) ), 0, $per_page ) );
		$items = array_map( array( __CLASS__, 'product_summary' ), $ids );

		return array(
			'items'    => $items,
			'page'     => $page,
			'per_page' => $per_page,
			'total'    => $total,
			'has_more' => ( $offset + $per_page ) < $total,
		);
	}

	private static function apply_core_product_data( WC_Product $product, array $payload ) {
		$product->set_name( wp_strip_all_tags( $payload['name'] ?? '' ) );
		$product->set_slug( sanitize_title( $payload['slug'] ?? '' ) );
		$product->set_status( sanitize_key( $payload['status'] ?? 'draft' ) );
		$product->set_catalog_visibility( sanitize_key( $payload['catalog_visibility'] ?? 'visible' ) );
		$product->set_description( wp_kses_post( $payload['description'] ?? '' ) );
		$product->set_short_description( wp_kses_post( $payload['short_description'] ?? '' ) );
		$product->set_menu_order( absint( $payload['menu_order'] ?? 0 ) );

		$sku = sanitize_text_field( $payload['sku'] ?? '' );
		if ( '' !== $sku ) {
			try {
				$product->set_sku( $sku );
			} catch ( Exception $e ) {
				WPSB_Logger::write( 'SKU could not be assigned.', array( 'sku' => $sku, 'error' => $e->getMessage() ) );
			}
		}

		self::apply_core_fields( $product, $payload['core'] ?? array() );
	}

	private static function apply_core_fields( WC_Product $product, array $core ) {
		$map = array(
			'regular_price',
			'sale_price',
			'tax_status',
			'tax_class',
			'manage_stock',
			'stock_quantity',
			'stock_status',
			'backorders',
			'sold_individually',
			'weight',
			'length',
			'width',
			'height',
			'virtual',
			'downloadable',
			'purchase_note',
			'reviews_allowed',
		);

		foreach ( $map as $field ) {
			if ( array_key_exists( $field, $core ) ) {
				$setter = 'set_' . $field;
				if ( method_exists( $product, $setter ) ) {
					$product->$setter( $core[ $field ] );
				}
			}
		}

		if ( ! empty( $core['date_on_sale_from'] ) && method_exists( $product, 'set_date_on_sale_from' ) ) {
			$product->set_date_on_sale_from( $core['date_on_sale_from'] );
		}
		if ( ! empty( $core['date_on_sale_to'] ) && method_exists( $product, 'set_date_on_sale_to' ) ) {
			$product->set_date_on_sale_to( $core['date_on_sale_to'] );
		}
		if ( ! empty( $core['shipping_class'] ) ) {
			$term = self::ensure_term( array( 'name' => $core['shipping_class'], 'slug' => $core['shipping_class'] ), 'product_shipping_class' );
			if ( $term ) {
				$product->set_shipping_class_id( $term );
			}
		}
	}

	private static function sync_taxonomies( int $product_id, array $taxonomies ) {
		foreach ( array( 'product_cat', 'product_tag', 'product_brand' ) as $taxonomy ) {
			if ( empty( $taxonomies[ $taxonomy ] ) || ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$term_ids = array();
			foreach ( $taxonomies[ $taxonomy ] as $term ) {
				$term_id = self::ensure_term( $term, $taxonomy );
				if ( $term_id ) {
					$term_ids[] = $term_id;
				}
			}
			wp_set_object_terms( $product_id, $term_ids, $taxonomy, false );
		}

		if ( ! empty( $taxonomies['_primary_product_cat'] ) ) {
			$primary_id = self::ensure_term( $taxonomies['_primary_product_cat'], 'product_cat' );
			if ( $primary_id ) {
				update_post_meta( $product_id, '_yoast_wpseo_primary_product_cat', $primary_id );
			}
		}
	}

	private static function ensure_term( array $term, string $taxonomy ): int {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}

		$parent_id = 0;
		if ( ! empty( $term['parents'] ) && is_array( $term['parents'] ) ) {
			foreach ( $term['parents'] as $parent ) {
				$parent_id = self::find_or_create_term( $parent, $taxonomy, $parent_id );
			}
		}

		$term_id = self::find_or_create_term( $term, $taxonomy, $parent_id );

		if ( $term_id && ! empty( $term['meta'] ) && is_array( $term['meta'] ) ) {
			foreach ( $term['meta'] as $key => $values ) {
				if ( WPSB_Utils::blacklist_meta_key( $key ) ) {
					continue;
				}
				delete_term_meta( $term_id, $key );
				foreach ( (array) $values as $value ) {
					add_term_meta( $term_id, $key, $value );
				}
			}
		}

		return $term_id;
	}

	private static function find_or_create_term( array $term, string $taxonomy, int $parent_id = 0 ): int {
		$slug = sanitize_title( $term['slug'] ?? $term['name'] ?? '' );
		$name = sanitize_text_field( $term['name'] ?? $slug );

		if ( '' === $slug && '' === $name ) {
			return 0;
		}

		$existing = $slug ? get_term_by( 'slug', $slug, $taxonomy ) : false;
		if ( ! $existing && $name ) {
			$existing = get_term_by( 'name', $name, $taxonomy );
		}

		if ( $existing && ! is_wp_error( $existing ) ) {
			if ( is_taxonomy_hierarchical( $taxonomy ) && (int) $existing->parent !== $parent_id ) {
				wp_update_term( $existing->term_id, $taxonomy, array( 'parent' => $parent_id ) );
			}
			return (int) $existing->term_id;
		}

		$created = wp_insert_term(
			$name,
			$taxonomy,
			array(
				'slug'        => $slug,
				'parent'      => $parent_id,
				'description' => wp_kses_post( $term['description'] ?? '' ),
			)
		);

		if ( is_wp_error( $created ) ) {
			return 0;
		}

		return (int) $created['term_id'];
	}

	private static function sync_attributes( WC_Product $product, array $attributes, array $defaults ) {
		$objects = array();

		foreach ( $attributes as $item ) {
			$name = sanitize_text_field( $item['name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}

			if ( ! empty( $item['is_taxonomy'] ) ) {
				$name = self::ensure_attribute_taxonomy( $name, sanitize_text_field( $item['label'] ?? $name ) );
			}

			$attribute = new WC_Product_Attribute();
			$attribute->set_name( $name );
			$attribute->set_position( absint( $item['position'] ?? 0 ) );
			$attribute->set_visible( ! empty( $item['visible'] ) );
			$attribute->set_variation( ! empty( $item['variation'] ) );

			if ( ! empty( $item['is_taxonomy'] ) && taxonomy_exists( $name ) ) {
				if ( function_exists( 'wc_attribute_taxonomy_id_by_name' ) ) {
					$attribute->set_id( absint( wc_attribute_taxonomy_id_by_name( $name ) ) );
				}
				$term_ids = array();
				foreach ( (array) ( $item['options'] ?? array() ) as $term ) {
					$term_id = self::ensure_term( $term, $name );
					if ( $term_id ) {
						$term_ids[] = $term_id;
					}
				}
				$attribute->set_options( $term_ids );
			} else {
				$attribute->set_options( array_map( 'wc_clean', (array) ( $item['options'] ?? array() ) ) );
			}

			$objects[] = $attribute;
		}

		$product->set_attributes( $objects );
		if ( method_exists( $product, 'set_default_attributes' ) ) {
			$product->set_default_attributes( is_array( $defaults ) ? $defaults : array() );
		}
		$product->save();
	}

	private static function ensure_attribute_taxonomy( string $taxonomy, string $label ): string {
		if ( 0 !== strpos( $taxonomy, 'pa_' ) ) {
			return $taxonomy;
		}

		if ( taxonomy_exists( $taxonomy ) ) {
			return $taxonomy;
		}

		$attribute_name = wc_attribute_taxonomy_slug( $taxonomy );
		if ( function_exists( 'wc_create_attribute' ) ) {
			$result = wc_create_attribute(
				array(
					'name'         => $label,
					'slug'         => $attribute_name,
					'type'         => 'select',
					'order_by'     => 'menu_order',
					'has_archives' => false,
				)
			);
			if ( ! is_wp_error( $result ) ) {
				delete_transient( 'wc_attribute_taxonomies' );
				if ( class_exists( 'WC_Post_Types' ) ) {
					WC_Post_Types::register_taxonomies();
				}
			}
		}

		return $taxonomy;
	}

	private static function sync_images( WC_Product $product, array $images ) {
		if ( ! empty( $images['featured'] ) ) {
			$featured_id = self::ensure_media( $images['featured'] );
			if ( $featured_id ) {
				$product->set_image_id( $featured_id );
				WPSB_Logger::write( 'Featured image synced.', array( 'product_id' => $product->get_id(), 'media_id' => $featured_id ) );
			}
		}

		$gallery_ids = array();
		foreach ( (array) ( $images['gallery'] ?? array() ) as $image ) {
			$media_id = self::ensure_media( $image );
			if ( $media_id ) {
				$gallery_ids[] = $media_id;
			}
		}
		$product->set_gallery_image_ids( $gallery_ids );
		update_post_meta( $product->get_id(), '_product_image_gallery', implode( ',', $gallery_ids ) );
		WPSB_Logger::write( 'Gallery images synced.', array( 'product_id' => $product->get_id(), 'gallery_ids' => $gallery_ids ) );
	}

	private static function ensure_media( array $image ): int {
		global $wpdb;

		$url = esc_url_raw( $image['url'] ?? '' );
		$source_id = absint( $image['source_id'] ?? 0 );
		if ( '' === $url && empty( $image['data_base64'] ) ) {
			return 0;
		}

		if ( $source_id && isset( self::$media_map[ $source_id ] ) ) {
			return absint( self::$media_map[ $source_id ] );
		}

		$hash = ! empty( $image['hash'] ) ? sanitize_text_field( $image['hash'] ) : md5( $url );
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wpsb_source_media_hash' AND meta_value = %s LIMIT 1",
				$hash
			)
		);

		if ( $existing ) {
			if ( $source_id ) {
				self::$media_map[ $source_id ] = absint( $existing );
			}
			return absint( $existing );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$media_id = $url ? media_sideload_image( $url, 0, sanitize_text_field( $image['title'] ?? '' ), 'id' ) : new WP_Error( 'wpsb_no_url', 'No URL available.' );
		if ( is_wp_error( $media_id ) && ! empty( $image['data_base64'] ) ) {
			WPSB_Logger::write( 'Media sideload failed; trying inline image data.', array( 'url' => $url, 'error' => $media_id->get_error_message() ) );
			$media_id = self::create_media_from_data( $image );
		}

		if ( is_wp_error( $media_id ) ) {
			WPSB_Logger::write( 'Media import failed.', array( 'url' => $url, 'error' => $media_id->get_error_message() ) );
			return 0;
		}

		update_post_meta( $media_id, '_wpsb_source_media_hash', $hash );
		update_post_meta( $media_id, '_wpsb_source_media_url_hash', md5( $url ) );
		update_post_meta( $media_id, '_wpsb_source_media_url', $url );
		update_post_meta( $media_id, '_wpsb_source_media_id', $source_id );

		if ( ! empty( $image['alt'] ) ) {
			update_post_meta( $media_id, '_wp_attachment_image_alt', sanitize_text_field( $image['alt'] ) );
		}

		if ( $source_id ) {
			self::$media_map[ $source_id ] = absint( $media_id );
		}

		return absint( $media_id );
	}

	private static function create_media_from_data( array $image ) {
		$decoded = base64_decode( (string) $image['data_base64'], true );
		if ( false === $decoded ) {
			return new WP_Error( 'wpsb_bad_image_data', 'Inline image data could not be decoded.' );
		}

		$filename = sanitize_file_name( $image['filename'] ?? ( 'wpsb-image-' . time() . '.jpg' ) );
		if ( '' === $filename ) {
			$filename = 'wpsb-image-' . time() . '.jpg';
		}

		$upload = wp_upload_bits( $filename, null, $decoded );
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'wpsb_upload_failed', $upload['error'] );
		}

		$filetype = wp_check_filetype( $upload['file'], null );
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $filetype['type'] ?: sanitize_text_field( $image['mime'] ?? 'image/jpeg' ),
				'post_title'     => sanitize_text_field( $image['title'] ?? pathinfo( $filename, PATHINFO_FILENAME ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$upload['file']
		);

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		return $attachment_id;
	}

	private static function replace_meta( int $object_id, array $meta ) {
		foreach ( $meta as $key => $values ) {
			if ( WPSB_Utils::blacklist_meta_key( $key ) ) {
				continue;
			}
			delete_post_meta( $object_id, $key );
			foreach ( (array) $values as $value ) {
				add_post_meta( $object_id, $key, self::remap_meta_media_ids( $key, $value ) );
			}
		}
	}

	private static function sync_variations( WC_Product $product, array $variations ) {
		$existing = self::variation_index( $product );
		$seen     = array();

		foreach ( $variations as $item ) {
			$sku        = sanitize_text_field( $item['sku'] ?? '' );
			$attributes = is_array( $item['attributes'] ?? null ) ? $item['attributes'] : array();
			$key        = self::variation_attribute_key( $attributes );
			$variation_id = 0;

			if ( $sku && ! empty( $existing['sku'][ $sku ] ) ) {
				$variation_id = $existing['sku'][ $sku ];
			} elseif ( ! empty( $existing['attrs'][ $key ] ) ) {
				$variation_id = $existing['attrs'][ $key ];
			}

			$variation = $variation_id ? wc_get_product( $variation_id ) : new WC_Product_Variation();
			if ( ! $variation instanceof WC_Product_Variation ) {
				continue;
			}

			$variation->set_parent_id( $product->get_id() );
			$variation->set_status( sanitize_key( $item['status'] ?? 'publish' ) );
			$variation->set_description( wp_kses_post( $item['description'] ?? '' ) );
			$variation->set_menu_order( absint( $item['menu_order'] ?? 0 ) );
			if ( $sku ) {
				try {
					$variation->set_sku( $sku );
				} catch ( Exception $e ) {
					WPSB_Logger::write( 'Variation SKU could not be assigned.', array( 'sku' => $sku ) );
				}
			}
			$variation->set_attributes( $attributes );
			self::apply_core_fields( $variation, $item['core'] ?? array() );
			if ( ! empty( $item['image'] ) ) {
				$image_id = self::ensure_media( $item['image'] );
				if ( $image_id ) {
					$variation->set_image_id( $image_id );
				}
			}
			$gallery_ids = self::sync_variation_gallery_media( $item['gallery'] ?? array() );
			$variation_id = $variation->save();
			self::replace_meta( $variation_id, $item['meta'] ?? array() );
			self::write_variation_gallery_meta( $variation_id, $gallery_ids, $item['gallery_meta'] ?? array() );
			$seen[] = $variation_id;
		}
	}

	private static function sync_variation_images_only( WC_Product $product, array $variations ) {
		$existing = self::variation_index( $product );
		foreach ( $variations as $item ) {
			$sku        = sanitize_text_field( $item['sku'] ?? '' );
			$attributes = is_array( $item['attributes'] ?? null ) ? $item['attributes'] : array();
			$key        = self::variation_attribute_key( $attributes );
			$variation_id = $sku && ! empty( $existing['sku'][ $sku ] ) ? $existing['sku'][ $sku ] : ( $existing['attrs'][ $key ] ?? 0 );
			$variation = $variation_id ? wc_get_product( $variation_id ) : null;
			if ( ! $variation instanceof WC_Product_Variation ) {
				WPSB_Logger::write( 'Variation image update skipped; no target match.', array( 'sku' => $sku, 'attributes' => $attributes ) );
				continue;
			}
			if ( ! empty( $item['image'] ) ) {
				$image_id = self::ensure_media( $item['image'] );
				if ( $image_id ) {
					$variation->set_image_id( $image_id );
				}
			}
			$gallery_ids = self::sync_variation_gallery_media( $item['gallery'] ?? array() );
			$variation->save();
			self::write_variation_gallery_meta( $variation->get_id(), $gallery_ids, $item['gallery_meta'] ?? array() );
		}
	}

	private static function sync_variation_gallery_media( array $gallery ): array {
		$gallery_ids = array();
		foreach ( $gallery as $image ) {
			$media_id = self::ensure_media( is_array( $image ) ? $image : array() );
			if ( $media_id ) {
				$gallery_ids[] = $media_id;
			}
		}
		return $gallery_ids;
	}

	private static function write_variation_gallery_meta( int $variation_id, array $gallery_ids, array $gallery_meta = array() ) {
		if ( empty( $gallery_ids ) ) {
			self::clear_variation_gallery_meta( $variation_id );
			return;
		}

		foreach ( $gallery_meta as $key => $values ) {
			if ( WPSB_Utils::blacklist_meta_key( $key ) ) {
				continue;
			}
			delete_post_meta( $variation_id, $key );
			foreach ( (array) $values as $value ) {
				add_post_meta( $variation_id, $key, self::remap_meta_media_ids( $key, $value ) );
			}
		}

		update_post_meta( $variation_id, 'woo_variation_gallery_images', $gallery_ids );
		update_post_meta( $variation_id, '_woo_variation_gallery_images', $gallery_ids );
		update_post_meta( $variation_id, 'woo_variation_gallery_images_src', $gallery_ids );
		update_post_meta( $variation_id, 'rtwpvg_images', $gallery_ids );
		update_post_meta( $variation_id, '_rtwpvg_images', $gallery_ids );
		update_post_meta( $variation_id, 'rtwpvg_gallery_images', $gallery_ids );
		update_post_meta( $variation_id, '_rtwpvg_gallery_images', $gallery_ids );
		update_post_meta( $variation_id, 'variation_image_gallery', implode( ',', $gallery_ids ) );
		update_post_meta( $variation_id, '_variation_image_gallery', implode( ',', $gallery_ids ) );
		update_post_meta( $variation_id, 'product_variation_gallery', implode( ',', $gallery_ids ) );
		update_post_meta( $variation_id, '_product_variation_gallery', implode( ',', $gallery_ids ) );
		update_post_meta( $variation_id, 'woodmart_variation_gallery_data', $gallery_ids );
		update_post_meta( $variation_id, '_woodmart_variation_gallery_data', $gallery_ids );
		WPSB_Logger::write( 'Variation gallery images synced.', array( 'variation_id' => $variation_id, 'gallery_ids' => $gallery_ids ) );
	}

	private static function clear_variation_gallery_meta( int $variation_id ) {
		$keys = array(
			'woo_variation_gallery_images',
			'_woo_variation_gallery_images',
			'woo_variation_gallery_images_src',
			'rtwpvg_images',
			'_rtwpvg_images',
			'rtwpvg_gallery_images',
			'_rtwpvg_gallery_images',
			'variation_image_gallery',
			'_variation_image_gallery',
			'product_variation_gallery',
			'_product_variation_gallery',
			'woodmart_variation_gallery_data',
			'_woodmart_variation_gallery_data',
		);

		foreach ( $keys as $key ) {
			delete_post_meta( $variation_id, $key );
		}
	}

	private static function remap_meta_media_ids( string $key, $value ) {
		if ( ! preg_match( '/(image|gallery|thumbnail|attachment)/i', $key ) ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			$int_value = absint( $value );
			return self::$media_map[ $int_value ] ?? $value;
		}

		if ( is_string( $value ) && preg_match( '/^\d+(,\d+)*$/', $value ) ) {
			$ids = array_map( 'absint', explode( ',', $value ) );
			$ids = array_map(
				static function ( $id ) {
					return self::$media_map[ $id ] ?? $id;
				},
				$ids
			);
			return implode( ',', $ids );
		}

		if ( is_string( $value ) && preg_match( '/\d/', $value ) ) {
			foreach ( self::$media_map as $source_id => $target_id ) {
				$value = preg_replace( '/(?<!\d)' . preg_quote( (string) $source_id, '/' ) . '(?!\d)/', (string) $target_id, $value );
			}
			return $value;
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $index => $item ) {
				$value[ $index ] = self::remap_meta_media_ids( $key, $item );
			}
		}

		return $value;
	}

	private static function sync_price( WC_Product $product, array $payload ) {
		self::apply_core_fields( $product, $payload['core'] ?? array() );

		if ( $product->is_type( 'variable' ) && ! empty( $payload['variations'] ) ) {
			$existing = self::variation_index( $product );
			foreach ( $payload['variations'] as $item ) {
				$sku        = sanitize_text_field( $item['sku'] ?? '' );
				$attributes = is_array( $item['attributes'] ?? null ) ? $item['attributes'] : array();
				$key        = self::variation_attribute_key( $attributes );
				$variation_id = $sku && ! empty( $existing['sku'][ $sku ] ) ? $existing['sku'][ $sku ] : ( $existing['attrs'][ $key ] ?? 0 );
				$variation = $variation_id ? wc_get_product( $variation_id ) : null;
				if ( $variation instanceof WC_Product_Variation ) {
					self::apply_core_fields( $variation, $item['core'] ?? array() );
					$variation->save();
				} else {
					WPSB_Logger::write( 'Variation price update skipped; no target match.', array( 'sku' => $sku, 'attributes' => $attributes ) );
				}
			}
			WC_Product_Variable::sync( $product->get_id() );
		}
	}

	private static function variation_index( WC_Product $product ): array {
		$index = array( 'sku' => array(), 'attrs' => array() );
		if ( ! $product->is_type( 'variable' ) ) {
			return $index;
		}

		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( ! $variation instanceof WC_Product_Variation ) {
				continue;
			}
			$sku = $variation->get_sku();
			if ( $sku ) {
				$index['sku'][ $sku ] = $child_id;
			}
			$index['attrs'][ self::variation_attribute_key( $variation->get_attributes() ) ] = $child_id;
		}
		return $index;
	}

	private static function variation_attribute_key( array $attributes ): string {
		ksort( $attributes );
		return md5( wp_json_encode( $attributes ) );
	}

	private static function product_summary( int $product_id ): array {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return array();
		}

		$image_id = $product->get_image_id();
		return array(
			'id'     => $product_id,
			'title'  => $product->get_name(),
			'sku'    => $product->get_sku(),
			'type'   => $product->get_type(),
			'status' => $product->get_status(),
			'price'  => $product->get_price_html(),
			'image'  => $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '',
		);
	}

	private static function sku_conflict_response( int $existing_id, string $message = '' ): array {
		return array(
			'success'  => false,
			'code'     => 'sku_conflict',
			'message'  => $message ? $message : 'A target product with the same SKU already exists.',
			'conflict' => self::product_summary( $existing_id ),
		);
	}

	private static function find_product_id_by_sku( string $sku ): int {
		global $wpdb;

		$sku = wc_clean( $sku );
		if ( '' === $sku ) {
			return 0;
		}

		$product_id = absint( wc_get_product_id_by_sku( $sku ) );
		if ( $product_id ) {
			return $product_id;
		}

		$lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
		$product_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT product_id FROM {$lookup_table} WHERE sku = %s LIMIT 1",
				$sku
			)
		);

		return absint( $product_id );
	}
}

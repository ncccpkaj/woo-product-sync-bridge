<?php
defined( 'ABSPATH' ) || exit;

class WPSB_Exporter {

	public static function product( int $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error( 'wpsb_product_missing', 'Product not found.' );
		}

		if ( ! $product->is_type( array( 'simple', 'variable' ) ) ) {
			return new WP_Error( 'wpsb_unsupported_type', 'Only simple and variable products are supported in version 1.' );
		}

		$data = array(
			'source_site'        => WPSB_Utils::site_id(),
			'source_url'         => home_url(),
			'source_product_id'  => $product_id,
			'type'               => $product->get_type(),
			'name'               => $product->get_name( 'edit' ),
			'slug'               => $product->get_slug( 'edit' ),
			'status'             => $product->get_status( 'edit' ),
			'catalog_visibility' => $product->get_catalog_visibility( 'edit' ),
			'sku'                => $product->get_sku( 'edit' ),
			'description'        => $product->get_description( 'edit' ),
			'short_description'  => $product->get_short_description( 'edit' ),
			'menu_order'         => $product->get_menu_order( 'edit' ),
			'core'               => self::core_data( $product ),
			'taxonomies'         => self::taxonomies( $product_id ),
			'attributes'         => self::attributes( $product ),
			'default_attributes' => method_exists( $product, 'get_default_attributes' ) ? $product->get_default_attributes( 'edit' ) : array(),
			'images'             => array(
				'featured' => self::image( $product->get_image_id( 'edit' ) ),
				'gallery'  => array_values( array_filter( array_map( array( __CLASS__, 'image' ), $product->get_gallery_image_ids( 'edit' ) ) ) ),
			),
			'meta'               => self::meta( $product_id ),
			'variations'         => array(),
		);

		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				if ( $variation ) {
					$data['variations'][] = self::variation( $variation );
				}
			}
		}

		return $data;
	}

	private static function core_data( WC_Product $product ): array {
		$fields = array(
			'regular_price',
			'sale_price',
			'date_on_sale_from',
			'date_on_sale_to',
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

		$data = array();
		foreach ( $fields as $field ) {
			$getter = 'get_' . $field;
			if ( method_exists( $product, $getter ) ) {
				$value = $product->$getter( 'edit' );
				if ( $value instanceof WC_DateTime ) {
					$value = $value->date( 'Y-m-d H:i:s' );
				}
				$data[ $field ] = $value;
			}
		}

		if ( method_exists( $product, 'get_shipping_class' ) ) {
			$data['shipping_class'] = $product->get_shipping_class();
		}

		return $data;
	}

	private static function taxonomies( int $product_id ): array {
		$taxonomies = array( 'product_cat', 'product_tag' );
		if ( taxonomy_exists( 'product_brand' ) ) {
			$taxonomies[] = 'product_brand';
		}

		$out = array();
		foreach ( $taxonomies as $taxonomy ) {
			$terms = wp_get_post_terms( $product_id, $taxonomy );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			$out[ $taxonomy ] = array_map( array( __CLASS__, 'term_payload' ), $terms );
		}

		$primary = get_post_meta( $product_id, '_yoast_wpseo_primary_product_cat', true );
		if ( $primary ) {
			$term = get_term( absint( $primary ), 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$out['_primary_product_cat'] = self::term_payload( $term );
			}
		}

		return $out;
	}

	private static function term_payload( WP_Term $term ): array {
		$parents = array();
		$ancestor_ids = array_reverse( get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' ) );
		foreach ( $ancestor_ids as $ancestor_id ) {
			$ancestor = get_term( $ancestor_id, $term->taxonomy );
			if ( $ancestor && ! is_wp_error( $ancestor ) ) {
				$parents[] = array(
					'name' => $ancestor->name,
					'slug' => $ancestor->slug,
				);
			}
		}

		return array(
			'source_id'   => (int) $term->term_id,
			'taxonomy'    => $term->taxonomy,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'description' => $term->description,
			'parents'     => $parents,
			'meta'        => self::term_meta( $term->term_id ),
		);
	}

	private static function term_meta( int $term_id ): array {
		$meta = get_term_meta( $term_id );
		$out  = array();
		foreach ( $meta as $key => $values ) {
			if ( WPSB_Utils::blacklist_meta_key( $key ) ) {
				continue;
			}
			$out[ $key ] = array_map( 'maybe_unserialize', $values );
		}
		return $out;
	}

	private static function attributes( WC_Product $product ): array {
		$out = array();

		foreach ( $product->get_attributes( 'edit' ) as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute ) {
				continue;
			}

			$item = array(
				'name'       => $attribute->get_name(),
				'label'      => wc_attribute_label( $attribute->get_name() ),
				'position'   => $attribute->get_position(),
				'visible'    => $attribute->get_visible(),
				'variation'  => $attribute->get_variation(),
				'is_taxonomy'=> $attribute->is_taxonomy(),
				'options'    => array(),
			);

			if ( $attribute->is_taxonomy() ) {
				foreach ( $attribute->get_terms() as $term ) {
					$item['options'][] = self::term_payload( $term );
				}
			} else {
				$item['options'] = $attribute->get_options();
			}

			$out[] = $item;
		}

		return $out;
	}

	private static function variation( WC_Product_Variation $variation ): array {
		return array(
			'source_variation_id' => $variation->get_id(),
			'status'              => $variation->get_status( 'edit' ),
			'sku'                 => $variation->get_sku( 'edit' ),
			'description'         => $variation->get_description( 'edit' ),
			'menu_order'          => $variation->get_menu_order( 'edit' ),
			'attributes'          => $variation->get_attributes( 'edit' ),
			'core'                => self::core_data( $variation ),
			'image'               => self::image( $variation->get_image_id( 'edit' ) ),
			'gallery'             => self::variation_gallery( $variation->get_id() ),
			'gallery_meta'        => self::variation_gallery_meta( $variation->get_id() ),
			'meta'                => self::meta( $variation->get_id() ),
		);
	}

	private static function image( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id ) {
			return null;
		}

		$url = wp_get_attachment_url( $attachment_id );
		if ( ! $url ) {
			return null;
		}

		$file = get_attached_file( $attachment_id );

		$payload = array(
			'source_id' => $attachment_id,
			'url'       => $url,
			'filename'  => wp_basename( parse_url( $url, PHP_URL_PATH ) ),
			'title'     => get_the_title( $attachment_id ),
			'alt'       => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'mime'      => get_post_mime_type( $attachment_id ),
			'hash'      => $file && file_exists( $file ) ? md5_file( $file ) : '',
		);

		$max_inline_size = (int) apply_filters( 'wpsb_max_inline_image_size', 8 * MB_IN_BYTES, $attachment_id );
		if ( $file && file_exists( $file ) && is_readable( $file ) && filesize( $file ) <= $max_inline_size ) {
			$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false !== $contents ) {
				$payload['data_base64'] = base64_encode( $contents );
			}
		}

		return $payload;
	}

	private static function variation_gallery( int $variation_id ): array {
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

		$ids = array();
		foreach ( $keys as $key ) {
			$value = get_post_meta( $variation_id, $key, true );
			if ( empty( $value ) ) {
				continue;
			}
			$ids = array_merge( $ids, self::extract_attachment_ids( $value ) );
		}

		foreach ( self::variation_gallery_meta( $variation_id ) as $values ) {
			foreach ( (array) $values as $value ) {
				$ids = array_merge( $ids, self::extract_attachment_ids( $value ) );
			}
		}

		$ids = array_values( array_unique( array_filter( $ids ) ) );
		return array_values( array_filter( array_map( array( __CLASS__, 'image' ), $ids ) ) );
	}

	private static function variation_gallery_meta( int $variation_id ): array {
		$meta = get_post_meta( $variation_id );
		$out  = array();

		foreach ( $meta as $key => $values ) {
			if ( WPSB_Utils::blacklist_meta_key( $key ) ) {
				continue;
			}
			if ( ! preg_match( '/(gallery|variation.*image|image.*variation|additional.*image|rtwpvg|woodmart.*image|woodmart.*gallery|wvg)/i', $key ) ) {
				continue;
			}
			$ids = array();
			foreach ( $values as $value ) {
				$ids = array_merge( $ids, self::extract_attachment_ids( maybe_unserialize( $value ) ) );
			}
			if ( ! empty( array_filter( $ids ) ) ) {
				$out[ $key ] = array_map( 'maybe_unserialize', $values );
			}
		}

		return $out;
	}

	private static function extract_attachment_ids( $value ): array {
		$ids = array();

		if ( is_numeric( $value ) ) {
			return array( absint( $value ) );
		}

		if ( is_string( $value ) ) {
			$maybe_json = json_decode( $value, true );
			if ( is_array( $maybe_json ) ) {
				return self::extract_attachment_ids( $maybe_json );
			}
			if ( preg_match_all( '/\b\d+\b/', $value, $matches ) ) {
				return array_map( 'absint', $matches[0] );
			}
			return array();
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				$ids = array_merge( $ids, self::extract_attachment_ids( $item ) );
			}
		}

		return $ids;
	}

	private static function meta( int $object_id ): array {
		$meta = get_post_meta( $object_id );
		$out  = array();

		foreach ( $meta as $key => $values ) {
			if ( WPSB_Utils::blacklist_meta_key( $key ) ) {
				continue;
			}
			$out[ $key ] = array_map( 'maybe_unserialize', $values );
		}

		return $out;
	}
}

<?php
/**
 * Imports / updates WooCommerce products using the WooCommerce CRUD API.
 *
 * @package OV_Content_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OVCB_Product_Importer {

	/**
	 * Options: match (update|skip|create|update_only), force_draft, images, create_terms.
	 */
	public static function process( $item, $opts, $job_id, $dry ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return OVCB_Post_Importer::row( 'error', 0, '-', 'ووکامرس فعال نیست.' );
		}

		// Aliases so an exported file or a "posts-like" file works too.
		if ( ! isset( $item['name'] ) && isset( $item['title'] ) ) {
			$item['name'] = $item['title'];
		}
		if ( ! isset( $item['description'] ) && isset( $item['content'] ) ) {
			$item['description'] = $item['content'];
		}
		if ( ! isset( $item['short_description'] ) && isset( $item['excerpt'] ) ) {
			$item['short_description'] = $item['excerpt'];
		}

		$name  = isset( $item['name'] ) ? sanitize_text_field( (string) $item['name'] ) : '';
		$label = $name ? $name : ( isset( $item['sku'] ) ? 'SKU ' . $item['sku'] : ( isset( $item['slug'] ) ? $item['slug'] : '#' ) );
		$notes = array();

		// ---- Find existing: ID -> SKU -> slug.
		$existing = 0;
		if ( ! empty( $item['id'] ) && 'product' === get_post_type( (int) $item['id'] ) && 'trash' !== get_post_status( (int) $item['id'] ) ) {
			$existing = (int) $item['id'];
		}
		if ( ! $existing && ! empty( $item['sku'] ) ) {
			$existing = (int) wc_get_product_id_by_sku( (string) $item['sku'] );
			if ( $existing && 'product' !== get_post_type( $existing ) ) {
				$existing = 0; // It is a variation SKU.
			}
		}
		if ( ! $existing && ! empty( $item['slug'] ) ) {
			$existing = OVCB_Helpers::find_by_slug( $item['slug'], array( 'product' ) );
		}

		if ( $existing && '' === $name ) {
			$label = get_the_title( $existing );
		}
		$match = $opts['match'];
		if ( $existing && 'skip' === $match ) {
			return OVCB_Post_Importer::row( 'skipped', $existing, $label, 'از قبل وجود دارد (تنظیم: رد شود).' );
		}
		if ( ! $existing && 'update_only' === $match ) {
			return OVCB_Post_Importer::row( 'skipped', 0, $label, 'محصول متناظری پیدا نشد.' );
		}
		if ( 'create' === $match ) {
			$existing = 0;
		}
		if ( ! $existing && '' === $name ) {
			return OVCB_Post_Importer::row( 'error', 0, $label, 'نام محصول (name/title) برای ساخت محصول جدید الزامی است.' );
		}

		// ---- Product object.
		if ( $existing ) {
			$product = wc_get_product( $existing );
			if ( ! $product ) {
				return OVCB_Post_Importer::row( 'error', $existing, $label, 'بارگذاری محصول ناموفق بود.' );
			}
		} else {
			$type    = isset( $item['product_type'] ) ? sanitize_key( $item['product_type'] ) : ( isset( $item['type'] ) ? sanitize_key( $item['type'] ) : 'simple' );
			$product = ( 'external' === $type ) ? new WC_Product_External() : new WC_Product_Simple();
			if ( ! in_array( $type, array( 'simple', 'external', 'product', '' ), true ) ) {
				$notes[] = 'نوع «' . $type . '» پشتیبانی نمی‌شود؛ به‌صورت محصول ساده ساخته می‌شود.';
			}
		}
		$is_simpleish = $product->is_type( 'simple' ) || $product->is_type( 'external' );
		$is_elementor = $existing && OVCB_Helpers::is_elementor( $existing );

		$cats = null;
		$tags = null;
		if ( isset( $item['categories'] ) ) {
			$cats = OVCB_Helpers::resolve_terms( $item['categories'], 'product_cat', ! empty( $opts['create_terms'] ), $dry );
		}
		if ( isset( $item['tags'] ) ) {
			$tags = OVCB_Helpers::resolve_terms( $item['tags'], 'product_tag', ! empty( $opts['create_terms'] ), $dry );
		}
		foreach ( array( $cats, $tags ) as $r ) {
			if ( $r && $r['created'] ) {
				$notes[] = ( $dry ? 'دسته/برچسب ساخته خواهد شد: ' : 'دسته/برچسب ساخته شد: ' ) . implode( '، ', $r['created'] );
			}
			if ( $r && $r['missing'] ) {
				$notes[] = 'پیدا نشد: ' . implode( '، ', $r['missing'] );
			}
		}

		if ( $dry ) {
			if ( ! $is_simpleish && ( isset( $item['regular_price'] ) || isset( $item['sale_price'] ) || isset( $item['attributes'] ) ) ) {
				$notes[] = 'قیمت/ویژگی برای محصول «' . $product->get_type() . '» تغییر نمی‌کند.';
			}
			if ( $is_elementor && isset( $item['description'] ) ) {
				$notes[] = 'توضیحات این محصول با المنتور ساخته شده و تغییر نمی‌کند.';
			}
			$msg = $existing ? 'به‌روزرسانی خواهد شد.' : 'به‌صورت «' . ( ! empty( $opts['force_draft'] ) ? 'پیش‌نویس' : 'طبق فایل' ) . '» ساخته خواهد شد.';
			return OVCB_Post_Importer::row( $existing ? 'dry-update' : 'dry-create', $existing, $label, trim( $msg . ' ' . implode( ' | ', $notes ) ) );
		}

		if ( $existing ) {
			OVCB_Helpers::backup( $existing, $job_id, 'update' );
		}

		try {
			if ( '' !== $name ) {
				$product->set_name( $name );
			}
			if ( ! empty( $item['slug'] ) && ( ! $existing || ! empty( $opts['update_slug'] ) ) ) {
				$product->set_slug( sanitize_title( rawurldecode( (string) $item['slug'] ) ) );
			}
			if ( isset( $item['description'] ) ) {
				if ( $is_elementor ) {
					$notes[] = 'توضیحات با المنتور ساخته شده؛ تغییر نکرد.';
				} else {
					$product->set_description( OVCB_Helpers::clean_html( $item['description'] ) );
				}
			}
			if ( isset( $item['short_description'] ) ) {
				$product->set_short_description( OVCB_Helpers::clean_html( $item['short_description'] ) );
			}

			// Status.
			$allowed = array( 'draft', 'pending', 'publish', 'private' );
			$status  = isset( $item['status'] ) ? sanitize_key( $item['status'] ) : '';
			if ( ! $existing ) {
				$product->set_status( ( ! empty( $opts['force_draft'] ) || ! in_array( $status, $allowed, true ) ) ? 'draft' : $status );
			} elseif ( $status && empty( $opts['force_draft'] ) && in_array( $status, $allowed, true ) ) {
				$product->set_status( $status );
			}

			if ( isset( $item['sku'] ) && '' !== (string) $item['sku'] && (string) $item['sku'] !== $product->get_sku( 'edit' ) ) {
				try {
					$product->set_sku( wc_clean( (string) $item['sku'] ) );
				} catch ( WC_Data_Exception $e ) {
					$notes[] = 'SKU: ' . $e->getMessage();
				}
			}

			// Prices (simple / external only).
			if ( $is_simpleish ) {
				if ( isset( $item['regular_price'] ) ) {
					$product->set_regular_price( wc_format_decimal( OVCB_Helpers::number( $item['regular_price'] ) ) );
				} elseif ( isset( $item['price'] ) && ! $existing ) {
					$product->set_regular_price( wc_format_decimal( OVCB_Helpers::number( $item['price'] ) ) );
				}
				if ( isset( $item['sale_price'] ) ) {
					$product->set_sale_price( wc_format_decimal( OVCB_Helpers::number( $item['sale_price'] ) ) );
				}
			} elseif ( isset( $item['regular_price'] ) || isset( $item['sale_price'] ) ) {
				$notes[] = 'قیمت محصول «' . $product->get_type() . '» از این‌جا تغییر نمی‌کند.';
			}

			if ( $product->is_type( 'external' ) ) {
				if ( ! empty( $item['product_url'] ) ) {
					$product->set_product_url( esc_url_raw( (string) $item['product_url'] ) );
				}
				if ( ! empty( $item['button_text'] ) ) {
					$product->set_button_text( sanitize_text_field( (string) $item['button_text'] ) );
				}
			}

			// Stock.
			if ( isset( $item['manage_stock'] ) ) {
				$product->set_manage_stock( OVCB_Helpers::bool( $item['manage_stock'] ) );
			}
			if ( isset( $item['stock_quantity'] ) && '' !== (string) $item['stock_quantity'] && null !== $item['stock_quantity'] ) {
				$product->set_manage_stock( true );
				$product->set_stock_quantity( wc_stock_amount( OVCB_Helpers::number( $item['stock_quantity'] ) ) );
			}
			if ( ! empty( $item['stock_status'] ) && in_array( $item['stock_status'], array( 'instock', 'outofstock', 'onbackorder' ), true ) ) {
				$product->set_stock_status( $item['stock_status'] );
			}

			foreach ( array( 'weight', 'length', 'width', 'height' ) as $dim ) {
				if ( isset( $item[ $dim ] ) && '' !== (string) $item[ $dim ] ) {
					$product->{'set_' . $dim}( wc_format_decimal( OVCB_Helpers::number( $item[ $dim ] ) ) );
				}
			}
			if ( isset( $item['featured'] ) ) {
				$product->set_featured( OVCB_Helpers::bool( $item['featured'] ) );
			}
			if ( ! empty( $item['catalog_visibility'] ) && in_array( $item['catalog_visibility'], array( 'visible', 'catalog', 'search', 'hidden' ), true ) ) {
				$product->set_catalog_visibility( $item['catalog_visibility'] );
			}

			if ( $cats && ( $cats['ids'] || empty( $item['categories'] ) ) ) {
				$product->set_category_ids( $cats['ids'] );
			}
			if ( $tags ) {
				$product->set_tag_ids( $tags['ids'] );
			}

			// Attributes (simple / external only; variable products are left untouched).
			if ( isset( $item['attributes'] ) && is_array( $item['attributes'] ) ) {
				if ( $is_simpleish ) {
					$product->set_attributes( self::attributes( $item['attributes'] ) );
				} else {
					$notes[] = 'ویژگی‌های محصول متغیر تغییر نکرد.';
				}
			}

			// Images.
			if ( ! empty( $opts['images'] ) ) {
				$image = isset( $item['featured_image'] ) ? $item['featured_image'] : ( isset( $item['image'] ) ? $item['image'] : '' );
				if ( $image ) {
					$att = OVCB_Helpers::image( $image, $existing, $name );
					if ( is_wp_error( $att ) ) {
						$notes[] = 'تصویر شاخص: ' . $att->get_error_message();
					} else {
						$product->set_image_id( $att );
					}
				}
				$gallery = isset( $item['gallery'] ) ? $item['gallery'] : ( isset( $item['images'] ) ? $item['images'] : null );
				if ( null !== $gallery ) {
					$ids = array();
					foreach ( OVCB_Helpers::to_list( $gallery ) as $g ) {
						$att = OVCB_Helpers::image( $g, $existing, $name );
						if ( is_wp_error( $att ) ) {
							$notes[] = 'گالری: ' . $att->get_error_message();
						} else {
							$ids[] = $att;
						}
					}
					if ( ! $image && $ids && ! $product->get_image_id() ) {
						$product->set_image_id( array_shift( $ids ) );
					}
					$product->set_gallery_image_ids( $ids );
				}
			}

			$id = (int) $product->save();
		} catch ( Exception $e ) {
			return OVCB_Post_Importer::row( 'error', $existing, $label, $e->getMessage() );
		}

		if ( ! $id ) {
			return OVCB_Post_Importer::row( 'error', $existing, $label, 'ذخیره محصول ناموفق بود.' );
		}
		if ( ! $existing ) {
			OVCB_Helpers::backup( $id, $job_id, 'create' );
		}

		$seo = isset( $item['seo'] ) && is_array( $item['seo'] ) ? $item['seo'] : array();
		foreach ( array( 'meta_title' => 'title', 'meta_description' => 'description', 'focus_keyword' => 'focus_keyword' ) as $flat => $key ) {
			if ( isset( $item[ $flat ] ) && ! isset( $seo[ $key ] ) ) {
				$seo[ $key ] = $item[ $flat ];
			}
		}
		if ( $seo ) {
			$storage = OVCB_Helpers::set_seo( $id, $seo );
			if ( 'ovcb' === $storage ) {
				$notes[] = 'افزونه سئو پیدا نشد؛ متا در فیلد داخلی ذخیره شد.';
			}
		}

		return OVCB_Post_Importer::row( $existing ? 'updated' : 'created', $id, $label, implode( ' | ', $notes ) );
	}

	/**
	 * Builds WC_Product_Attribute objects. Uses global attributes (pa_*) when
	 * one with the same name exists, otherwise a custom product attribute.
	 */
	private static function attributes( $list ) {
		$out = array();
		$pos = 0;
		foreach ( $list as $row ) {
			if ( ! is_array( $row ) || empty( $row['name'] ) ) {
				continue;
			}
			$name    = sanitize_text_field( (string) $row['name'] );
			$options = OVCB_Helpers::to_list( isset( $row['options'] ) ? $row['options'] : ( isset( $row['value'] ) ? $row['value'] : array() ) );
			if ( ! $options ) {
				continue;
			}
			$attr   = new WC_Product_Attribute();
			$tax_id = function_exists( 'wc_attribute_taxonomy_id_by_name' ) ? wc_attribute_taxonomy_id_by_name( $name ) : 0;
			if ( $tax_id ) {
				$taxonomy = wc_attribute_taxonomy_name_by_id( $tax_id );
				$term_ids = array();
				foreach ( $options as $opt ) {
					$term = get_term_by( 'name', $opt, $taxonomy );
					if ( ! $term ) {
						$new = wp_insert_term( $opt, $taxonomy );
						if ( ! is_wp_error( $new ) ) {
							$term_ids[] = (int) $new['term_id'];
						}
					} else {
						$term_ids[] = (int) $term->term_id;
					}
				}
				$attr->set_id( $tax_id );
				$attr->set_name( $taxonomy );
				$attr->set_options( $term_ids );
			} else {
				$attr->set_id( 0 );
				$attr->set_name( $name );
				$attr->set_options( $options );
			}
			$attr->set_position( $pos++ );
			$attr->set_visible( isset( $row['visible'] ) ? OVCB_Helpers::bool( $row['visible'] ) : true );
			$attr->set_variation( false );
			$out[] = $attr;
		}
		return $out;
	}
}

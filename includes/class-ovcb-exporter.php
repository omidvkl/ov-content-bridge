<?php
/**
 * JSON exporter for posts, pages, products and taxonomies.
 *
 * @package OV_Content_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OVCB_Exporter {

	/**
	 * admin-post.php?action=ovcb_export
	 */
	public static function handle() {
		if ( ! current_user_can( OVCB_Admin::CAP ) ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'ov-content-bridge' ), 403 );
		}
		check_admin_referer( 'ovcb_export' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$types    = isset( $_POST['types'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['types'] ) ) : array( 'post', 'product' );
		$statuses = isset( $_POST['statuses'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['statuses'] ) ) : array( 'publish' );
		$content  = isset( $_POST['content'] ) ? sanitize_key( wp_unslash( $_POST['content'] ) ) : 'both';
		$terms    = ! empty( $_POST['taxonomies'] );
		$pretty   = ! empty( $_POST['pretty'] );
		// phpcs:enable

		$allowed_status = array( 'publish', 'draft', 'pending', 'private', 'future' );
		$statuses       = array_values( array_intersect( $statuses, $allowed_status ) );
		if ( ! $statuses ) {
			$statuses = array( 'publish' );
		}
		if ( ! in_array( $content, array( 'both', 'html', 'text', 'none' ), true ) ) {
			$content = 'both';
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		wp_raise_memory_limit( 'admin' );

		$data = self::build( $types, $statuses, $content, $terms );

		$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
		if ( $pretty ) {
			$flags |= JSON_PRETTY_PRINT;
		}
		$json = wp_json_encode( $data, $flags );
		if ( false === $json ) {
			wp_die( esc_html__( 'ساخت فایل JSON ناموفق بود.', 'ov-content-bridge' ) );
		}

		$host     = preg_replace( '/[^a-z0-9\-\.]/', '', OVCB_Helpers::site_host() );
		$filename = 'ovcb-export-' . $host . '-' . gmdate( 'Ymd-His' ) . '.json';

		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	public static function build( $types, $statuses, $content_mode, $with_terms ) {
		$has_wc = function_exists( 'wc_get_product' );
		$theme  = wp_get_theme();
		$data   = array(
			'format'   => 'ovcb-export',
			'version'  => OVCB_VERSION,
			'site'     => array(
				'name'               => get_bloginfo( 'name' ),
				'description'        => get_bloginfo( 'description' ),
				'url'                => home_url( '/' ),
				'language'           => get_locale(),
				'exported_at'        => gmdate( 'c' ),
				'theme'              => $theme->get_template() . ' / ' . $theme->get( 'Name' ),
				'seo_plugin'         => OVCB_Helpers::seo_plugin(),
				'woocommerce'        => $has_wc && defined( 'WC_VERSION' ) ? WC_VERSION : null,
				'currency'           => $has_wc ? get_woocommerce_currency() : null,
				'permalink_structure' => get_option( 'permalink_structure' ),
			),
			'counts'   => array(),
			'taxonomies' => array(),
			'posts'    => array(),
			'pages'    => array(),
			'products' => array(),
		);

		if ( in_array( 'post', $types, true ) ) {
			$data['posts'] = self::collect( 'post', $statuses, $content_mode );
		}
		if ( in_array( 'page', $types, true ) ) {
			$data['pages'] = self::collect( 'page', $statuses, $content_mode );
		}
		if ( in_array( 'product', $types, true ) && $has_wc ) {
			$data['products'] = self::collect( 'product', $statuses, $content_mode );
		}

		if ( $with_terms ) {
			$taxes = array( 'category', 'post_tag' );
			if ( $has_wc ) {
				$taxes[] = 'product_cat';
				$taxes[] = 'product_tag';
			}
			foreach ( $taxes as $tax ) {
				$data['taxonomies'][ $tax ] = self::terms( $tax );
			}
		}

		self::add_inbound_counts( $data );

		$data['counts'] = array(
			'posts'    => count( $data['posts'] ),
			'pages'    => count( $data['pages'] ),
			'products' => count( $data['products'] ),
		);
		return $data;
	}

	private static function collect( $post_type, $statuses, $content_mode ) {
		$out   = array();
		$paged = 1;
		do {
			$q = new WP_Query(
				array(
					'post_type'              => $post_type,
					'post_status'            => $statuses,
					'posts_per_page'         => 100,
					'paged'                  => $paged,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'no_found_rows'          => false,
					'suppress_filters'       => true,
					'update_post_term_cache' => true,
					'update_post_meta_cache' => true,
				)
			);
			foreach ( $q->posts as $post ) {
				$out[] = self::item( $post, $content_mode );
			}
			$max = (int) $q->max_num_pages;
			++$paged;
			wp_reset_postdata();
		} while ( $paged <= $max );
		return $out;
	}

	private static function item( $post, $content_mode ) {
		$html = (string) $post->post_content;
		$text = OVCB_Helpers::plain_text( $html );

		$links = array();
		foreach ( OVCB_Helpers::hrefs( $html ) as $href ) {
			if ( OVCB_Helpers::is_internal( $href ) ) {
				$links[] = html_entity_decode( rawurldecode( $href ), ENT_QUOTES, 'UTF-8' );
			}
		}

		$thumb = get_the_post_thumbnail_url( $post, 'full' );
		$item  = array(
			'id'       => (int) $post->ID,
			'type'     => $post->post_type,
			'slug'     => OVCB_Helpers::decode_slug( $post->post_name ),
			'title'    => get_the_title( $post ),
			'url'      => rawurldecode( get_permalink( $post ) ),
			'status'   => $post->post_status,
			'date'     => $post->post_date,
			'modified' => $post->post_modified,
			'builder'  => OVCB_Helpers::builder( $post->ID, $html ),
			'excerpt'  => $post->post_excerpt,
		);

		if ( 'post' === $post->post_type ) {
			$item['categories'] = wp_get_post_terms( $post->ID, 'category', array( 'fields' => 'names' ) );
			$item['tags']       = wp_get_post_terms( $post->ID, 'post_tag', array( 'fields' => 'names' ) );
		}

		if ( 'product' === $post->post_type ) {
			$item = array_merge( $item, self::product_fields( $post ) );
		}

		$item['featured_image'] = $thumb ? $thumb : '';
		$item['seo']            = OVCB_Helpers::get_seo( $post->ID );
		$item['word_count']     = OVCB_Helpers::word_count( $text );
		$item['headings']       = OVCB_Helpers::headings( $html );
		$item['internal_links_out'] = array_values( array_unique( $links ) );

		if ( 'html' === $content_mode || 'both' === $content_mode ) {
			$item['content'] = $html;
		}
		if ( 'text' === $content_mode || 'both' === $content_mode ) {
			$item['content_text'] = $text;
		}
		return $item;
	}

	private static function product_fields( $post ) {
		$product = wc_get_product( $post->ID );
		if ( ! $product ) {
			return array();
		}
		$attrs = array();
		foreach ( $product->get_attributes() as $attr ) {
			if ( ! is_object( $attr ) ) {
				continue;
			}
			$options = $attr->is_taxonomy()
				? wc_get_product_terms( $post->ID, $attr->get_name(), array( 'fields' => 'names' ) )
				: $attr->get_options();
			$attrs[] = array(
				'name'      => wc_attribute_label( $attr->get_name() ),
				'options'   => array_values( (array) $options ),
				'variation' => (bool) $attr->get_variation(),
			);
		}
		$gallery = array();
		foreach ( $product->get_gallery_image_ids() as $gid ) {
			$u = wp_get_attachment_url( $gid );
			if ( $u ) {
				$gallery[] = $u;
			}
		}
		$fields = array(
			'product_type'      => $product->get_type(),
			'sku'               => $product->get_sku(),
			'price'             => $product->get_price(),
			'regular_price'     => $product->get_regular_price(),
			'sale_price'        => $product->get_sale_price(),
			'stock_status'      => $product->get_stock_status(),
			'stock_quantity'    => $product->get_stock_quantity(),
			'short_description' => $product->get_short_description(),
			'categories'        => wp_get_post_terms( $post->ID, 'product_cat', array( 'fields' => 'names' ) ),
			'tags'              => wp_get_post_terms( $post->ID, 'product_tag', array( 'fields' => 'names' ) ),
			'attributes'        => $attrs,
			'gallery'           => $gallery,
			'average_rating'    => (float) $product->get_average_rating(),
			'review_count'      => (int) $product->get_review_count(),
			'total_sales'       => (int) $product->get_total_sales(),
		);
		if ( $product->is_type( 'variable' ) ) {
			$fields['price_min']       = $product->get_variation_price( 'min' );
			$fields['price_max']       = $product->get_variation_price( 'max' );
			$fields['variation_count'] = count( $product->get_children() );
		}
		return $fields;
	}

	private static function terms( $taxonomy ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		$out = array();
		foreach ( $terms as $t ) {
			$link  = get_term_link( $t );
			$out[] = array(
				'id'          => (int) $t->term_id,
				'name'        => $t->name,
				'slug'        => OVCB_Helpers::decode_slug( $t->slug ),
				'parent'      => (int) $t->parent,
				'count'       => (int) $t->count,
				'url'         => is_wp_error( $link ) ? '' : rawurldecode( $link ),
				'description' => wp_strip_all_tags( $t->description ),
			);
		}
		return $out;
	}

	/**
	 * Adds "internal_links_in" (how many exported items link to each item).
	 */
	private static function add_inbound_counts( &$data ) {
		$map = array();
		foreach ( array( 'posts', 'pages', 'products' ) as $group ) {
			foreach ( $data[ $group ] as $i => $item ) {
				$map[ OVCB_Helpers::norm_url( $item['url'] ) ] = array( $group, $i );
				$data[ $group ][ $i ]['internal_links_in'] = 0;
			}
		}
		foreach ( array( 'posts', 'pages', 'products' ) as $group ) {
			foreach ( $data[ $group ] as $item ) {
				$seen = array();
				foreach ( $item['internal_links_out'] as $href ) {
					$key = OVCB_Helpers::norm_url( $href );
					if ( isset( $map[ $key ] ) && ! isset( $seen[ $key ] ) && OVCB_Helpers::norm_url( $item['url'] ) !== $key ) {
						$seen[ $key ] = true;
						list( $g, $idx ) = $map[ $key ];
						++$data[ $g ][ $idx ]['internal_links_in'];
					}
				}
			}
		}
	}
}

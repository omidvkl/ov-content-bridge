<?php
/**
 * Shared helpers: SEO meta, terms, images, backups, normalisation.
 *
 * @package OV_Content_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OVCB_Helpers {

	const BACKUP_META = '_ovcb_backup';
	const SOURCE_META = '_ovcb_source_url';

	/* ------------------------------------------------------------------ */
	/* SEO plugins                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Detect the active SEO plugin.
	 *
	 * @return string yoast|rankmath|seopress|aioseo|none
	 */
	public static function seo_plugin() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			return 'rankmath';
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			return 'seopress';
		}
		if ( defined( 'AIOSEO_VERSION' ) ) {
			return 'aioseo';
		}
		return 'none';
	}

	/**
	 * Meta keys per SEO plugin. "ovcb" is our own fallback storage.
	 */
	public static function seo_keys( $plugin = null ) {
		$map = array(
			'yoast'    => array(
				'title'         => '_yoast_wpseo_title',
				'description'   => '_yoast_wpseo_metadesc',
				'focus_keyword' => '_yoast_wpseo_focuskw',
			),
			'rankmath' => array(
				'title'         => 'rank_math_title',
				'description'   => 'rank_math_description',
				'focus_keyword' => 'rank_math_focus_keyword',
			),
			'seopress' => array(
				'title'         => '_seopress_titles_title',
				'description'   => '_seopress_titles_desc',
				'focus_keyword' => '_seopress_analysis_target_kw',
			),
			'ovcb'     => array(
				'title'         => '_ovcb_seo_title',
				'description'   => '_ovcb_seo_description',
				'focus_keyword' => '_ovcb_seo_focus_keyword',
			),
		);
		if ( null === $plugin ) {
			return $map;
		}
		return isset( $map[ $plugin ] ) ? $map[ $plugin ] : $map['ovcb'];
	}

	public static function get_seo( $post_id ) {
		$plugin = self::seo_plugin();
		$keys   = self::seo_keys( $plugin );
		$out    = array();
		foreach ( $keys as $field => $meta_key ) {
			$out[ $field ] = (string) get_post_meta( $post_id, $meta_key, true );
		}
		return $out;
	}

	/**
	 * Save SEO fields into the active SEO plugin's meta keys.
	 *
	 * @return string Name of the storage used.
	 */
	public static function set_seo( $post_id, $seo ) {
		if ( ! is_array( $seo ) || empty( $seo ) ) {
			return '';
		}
		$plugin  = self::seo_plugin();
		$storage = in_array( $plugin, array( 'yoast', 'rankmath', 'seopress' ), true ) ? $plugin : 'ovcb';
		$keys    = self::seo_keys( $storage );
		foreach ( $keys as $field => $meta_key ) {
			if ( ! array_key_exists( $field, $seo ) ) {
				continue;
			}
			$value = $seo[ $field ];
			if ( is_array( $value ) ) {
				$value = implode( ',', array_map( 'strval', $value ) );
			}
			$value = sanitize_text_field( (string) $value );
			if ( '' === $value ) {
				delete_post_meta( $post_id, $meta_key );
			} else {
				update_post_meta( $post_id, $meta_key, wp_slash( $value ) );
			}
		}
		return $storage;
	}

	/* ------------------------------------------------------------------ */
	/* Value normalisation                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Accepts arrays, "a|b|c" or "a, b، c" strings and returns a clean list.
	 */
	public static function to_list( $value ) {
		if ( is_array( $value ) ) {
			$list = $value;
		} elseif ( is_string( $value ) || is_numeric( $value ) ) {
			$value = trim( (string) $value );
			if ( '' === $value ) {
				return array();
			}
			if ( false !== strpos( $value, '|' ) ) {
				$list = explode( '|', $value );
			} else {
				$list = preg_split( '/[,،]/u', $value );
			}
		} else {
			return array();
		}
		$out = array();
		foreach ( $list as $v ) {
			if ( is_array( $v ) ) {
				// Allow {"name": "..."} / {"id": 12} objects.
				if ( isset( $v['id'] ) ) {
					$v = $v['id'];
				} elseif ( isset( $v['name'] ) ) {
					$v = $v['name'];
				} elseif ( isset( $v['url'] ) ) {
					$v = $v['url'];
				} else {
					continue;
				}
			}
			$v = trim( (string) $v );
			if ( '' !== $v ) {
				$out[] = $v;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Converts Persian/Arabic digits to Latin and strips thousands separators.
	 */
	public static function number( $value ) {
		if ( null === $value || '' === $value ) {
			return '';
		}
		$value = strtr(
			(string) $value,
			array(
				'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
				'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
				'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
				'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
				'٫' => '.', '٬' => '', ',' => '', '،' => '', ' ' => '',
			)
		);
		$value = preg_replace( '/[^0-9.\-]/', '', $value );
		return $value;
	}

	public static function bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		$value = strtolower( trim( (string) $value ) );
		return in_array( $value, array( '1', 'yes', 'true', 'on', 'بله', 'y' ), true );
	}

	/**
	 * Post content sanitisation for the current user.
	 */
	public static function clean_html( $html ) {
		$html = (string) $html;
		if ( current_user_can( 'unfiltered_html' ) ) {
			return $html;
		}
		return wp_kses_post( $html );
	}

	/**
	 * Returns true when the post is built with Elementor.
	 */
	public static function is_elementor( $post_id ) {
		return 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true );
	}

	public static function builder( $post_id, $content = null ) {
		if ( self::is_elementor( $post_id ) ) {
			return 'elementor';
		}
		if ( null === $content ) {
			$content = get_post_field( 'post_content', $post_id );
		}
		if ( false !== strpos( (string) $content, '[vc_' ) ) {
			return 'wpbakery';
		}
		if ( false !== strpos( (string) $content, '<!-- wp:' ) ) {
			return 'gutenberg';
		}
		return 'classic';
	}

	/* ------------------------------------------------------------------ */
	/* Lookups                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Find a post ID by slug within one or more post types.
	 */
	public static function find_by_slug( $slug, $post_types ) {
		$slug = trim( (string) $slug );
		if ( '' === $slug ) {
			return 0;
		}
		// Slugs may arrive decoded (Persian) or percent-encoded; normalise both.
		$name = sanitize_title( rawurldecode( $slug ) );
		if ( '' === $name ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'name'             => $name,
				'post_type'        => (array) $post_types,
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Resolve a reference (id / slug / url) to a post ID.
	 *
	 * @param array|string|int $ref        Reference.
	 * @param array            $post_types Allowed post types.
	 */
	public static function resolve_ref( $ref, $post_types ) {
		$post_types = (array) $post_types;
		if ( is_array( $ref ) ) {
			if ( ! empty( $ref['id'] ) ) {
				$ref = (int) $ref['id'];
			} elseif ( ! empty( $ref['slug'] ) ) {
				$types = ! empty( $ref['type'] ) ? array( sanitize_key( $ref['type'] ) ) : $post_types;
				return self::find_by_slug( $ref['slug'], $types );
			} elseif ( ! empty( $ref['url'] ) ) {
				$ref = (string) $ref['url'];
			} else {
				return 0;
			}
		}
		if ( is_int( $ref ) || ctype_digit( (string) $ref ) ) {
			$post = get_post( (int) $ref );
			return ( $post && in_array( $post->post_type, $post_types, true ) ) ? (int) $post->ID : 0;
		}
		$ref = trim( (string) $ref );
		if ( preg_match( '#^https?://#i', $ref ) || 0 === strpos( $ref, '/' ) ) {
			$id = url_to_postid( $ref );
			if ( $id ) {
				return (int) $id;
			}
			$path  = wp_parse_url( $ref, PHP_URL_PATH );
			$parts = array_values( array_filter( explode( '/', (string) $path ) ) );
			$ref   = $parts ? end( $parts ) : '';
		}
		return self::find_by_slug( $ref, $post_types );
	}

	/* ------------------------------------------------------------------ */
	/* Terms                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Resolves names / slugs / IDs / "Parent > Child" paths into term IDs.
	 *
	 * @return array{ids: int[], created: string[], missing: string[]}
	 */
	public static function resolve_terms( $values, $taxonomy, $create = true, $dry = false ) {
		$result = array(
			'ids'     => array(),
			'created' => array(),
			'missing' => array(),
		);
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return $result;
		}
		foreach ( self::to_list( $values ) as $value ) {
			if ( ctype_digit( $value ) ) {
				$term = get_term( (int) $value, $taxonomy );
				if ( $term && ! is_wp_error( $term ) ) {
					$result['ids'][] = (int) $term->term_id;
					continue;
				}
			}
			$parts  = array_map( 'trim', preg_split( '/\s*>\s*/u', $value ) );
			$parent = 0;
			$tid    = 0;
			foreach ( $parts as $part ) {
				if ( '' === $part ) {
					continue;
				}
				$tid = self::find_term( $part, $taxonomy, $parent );
				if ( ! $tid ) {
					if ( ! $create ) {
						$result['missing'][] = $part;
						$tid                 = 0;
						break;
					}
					if ( $dry ) {
						$result['created'][] = $part;
						$tid                 = -1;
						break;
					}
					$new = wp_insert_term( $part, $taxonomy, array( 'parent' => $parent ) );
					if ( is_wp_error( $new ) ) {
						$existing = $new->get_error_data( 'term_exists' );
						$tid      = $existing ? (int) $existing : 0;
					} else {
						$tid                 = (int) $new['term_id'];
						$result['created'][] = $part;
					}
				}
				if ( ! $tid ) {
					break;
				}
				$parent = $tid;
			}
			if ( $tid > 0 ) {
				$result['ids'][] = $tid;
			}
		}
		$result['ids'] = array_values( array_unique( $result['ids'] ) );
		return $result;
	}

	private static function find_term( $name, $taxonomy, $parent ) {
		$args = array(
			'taxonomy'   => $taxonomy,
			'name'       => $name,
			'hide_empty' => false,
			'number'     => 1,
			'fields'     => 'ids',
		);
		if ( is_taxonomy_hierarchical( $taxonomy ) ) {
			$args['parent'] = (int) $parent;
		}
		$ids = get_terms( $args );
		if ( ! is_wp_error( $ids ) && ! empty( $ids ) ) {
			return (int) $ids[0];
		}
		$term = get_term_by( 'slug', sanitize_title( $name ), $taxonomy );
		if ( $term && ( ! is_taxonomy_hierarchical( $taxonomy ) || (int) $term->parent === (int) $parent || 0 === (int) $parent ) ) {
			return (int) $term->term_id;
		}
		return 0;
	}

	/* ------------------------------------------------------------------ */
	/* Images                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Returns an attachment ID for an ID or URL, downloading remote images once.
	 *
	 * @return int|WP_Error
	 */
	public static function image( $src, $parent_id = 0, $alt = '' ) {
		if ( is_array( $src ) ) {
			$alt = isset( $src['alt'] ) ? $src['alt'] : $alt;
			$src = isset( $src['id'] ) ? $src['id'] : ( isset( $src['url'] ) ? $src['url'] : ( isset( $src['src'] ) ? $src['src'] : '' ) );
		}
		$src = trim( (string) $src );
		if ( '' === $src ) {
			return new WP_Error( 'ovcb_image', 'آدرس تصویر خالی است.' );
		}
		if ( ctype_digit( $src ) ) {
			return 'attachment' === get_post_type( (int) $src ) ? (int) $src : new WP_Error( 'ovcb_image', 'پیوست با شناسه ' . $src . ' پیدا نشد.' );
		}
		$url = esc_url_raw( $src );
		if ( ! $url ) {
			return new WP_Error( 'ovcb_image', 'آدرس تصویر نامعتبر است.' );
		}
		// Already in this site's media library?
		$local = attachment_url_to_postid( $url );
		if ( $local ) {
			return (int) $local;
		}
		// Already downloaded by this plugin before?
		$prev = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_key'         => self::SOURCE_META, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'       => $url, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		if ( $prev ) {
			return (int) $prev[0];
		}
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$id = media_sideload_image( $url, (int) $parent_id, $alt ? sanitize_text_field( $alt ) : null, 'id' );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_post_meta( $id, self::SOURCE_META, $url );
		if ( $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		}
		return (int) $id;
	}

	/* ------------------------------------------------------------------ */
	/* Backups / undo                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Store a snapshot of a post before a job changes it (once per job).
	 *
	 * @param string $action "update" or "create".
	 */
	public static function backup( $post_id, $job_id, $action = 'update' ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}
		$all = get_post_meta( $post_id, self::BACKUP_META, true );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		if ( isset( $all[ $job_id ] ) ) {
			return; // Keep the very first snapshot of this job.
		}
		$snap = array(
			'action' => $action,
			'time'   => time(),
		);
		if ( 'update' === $action ) {
			$snap['post']      = array(
				'post_title'   => $post->post_title,
				'post_content' => $post->post_content,
				'post_excerpt' => $post->post_excerpt,
				'post_name'    => $post->post_name,
				'post_status'  => $post->post_status,
			);
			$snap['seo']       = self::get_seo( $post_id );
			$snap['thumbnail'] = (int) get_post_thumbnail_id( $post_id );
			$snap['elementor'] = self::is_elementor( $post_id ) ? (string) get_post_meta( $post_id, '_elementor_data', true ) : null;
			$snap['terms']     = array();
			foreach ( get_object_taxonomies( $post->post_type ) as $tax ) {
				if ( in_array( $tax, array( 'category', 'post_tag', 'product_cat', 'product_tag' ), true ) ) {
					$snap['terms'][ $tax ] = wp_get_object_terms( $post_id, $tax, array( 'fields' => 'ids' ) );
				}
			}
			if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $post_id );
				if ( $product ) {
					$snap['wc'] = array(
						'regular_price'  => $product->get_regular_price( 'edit' ),
						'sale_price'     => $product->get_sale_price( 'edit' ),
						'sku'            => $product->get_sku( 'edit' ),
						'manage_stock'   => $product->get_manage_stock( 'edit' ),
						'stock_quantity' => $product->get_stock_quantity( 'edit' ),
						'stock_status'   => $product->get_stock_status( 'edit' ),
						'gallery'        => $product->get_gallery_image_ids( 'edit' ),
					);
				}
			}
		}
		$all[ $job_id ] = $snap;
		// Keep only the 5 most recent snapshots per post.
		if ( count( $all ) > 5 ) {
			uasort(
				$all,
				function ( $a, $b ) {
					return (int) $a['time'] - (int) $b['time'];
				}
			);
			$all = array_slice( $all, -5, null, true );
		}
		update_post_meta( $post_id, self::BACKUP_META, wp_slash( $all ) );
	}

	/**
	 * Restore a post to its state before the given job.
	 *
	 * @return string restored|trashed|none
	 */
	public static function restore( $post_id, $job_id ) {
		$all = get_post_meta( $post_id, self::BACKUP_META, true );
		if ( ! is_array( $all ) || ! isset( $all[ $job_id ] ) ) {
			return 'none';
		}
		$snap = $all[ $job_id ];
		unset( $all[ $job_id ] );
		if ( $all ) {
			update_post_meta( $post_id, self::BACKUP_META, wp_slash( $all ) );
		} else {
			delete_post_meta( $post_id, self::BACKUP_META );
		}

		if ( 'create' === $snap['action'] ) {
			wp_trash_post( $post_id );
			return 'trashed';
		}

		$postarr       = $snap['post'];
		$postarr['ID'] = $post_id;
		wp_update_post( wp_slash( $postarr ) );

		if ( isset( $snap['seo'] ) ) {
			self::set_seo( $post_id, $snap['seo'] );
		}
		if ( ! empty( $snap['thumbnail'] ) ) {
			set_post_thumbnail( $post_id, (int) $snap['thumbnail'] );
		} else {
			delete_post_thumbnail( $post_id );
		}
		if ( ! empty( $snap['terms'] ) && is_array( $snap['terms'] ) ) {
			foreach ( $snap['terms'] as $tax => $ids ) {
				if ( is_array( $ids ) ) {
					wp_set_object_terms( $post_id, array_map( 'intval', $ids ), $tax );
				}
			}
		}
		if ( ! empty( $snap['elementor'] ) ) {
			update_post_meta( $post_id, '_elementor_data', wp_slash( $snap['elementor'] ) );
			self::flush_elementor( $post_id );
		}
		if ( ! empty( $snap['wc'] ) && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post_id );
			if ( $product ) {
				try {
					if ( ! $product->is_type( 'variable' ) && ! $product->is_type( 'grouped' ) ) {
						$product->set_regular_price( $snap['wc']['regular_price'] );
						$product->set_sale_price( $snap['wc']['sale_price'] );
					}
					$product->set_sku( $snap['wc']['sku'] );
					$product->set_manage_stock( $snap['wc']['manage_stock'] );
					$product->set_stock_quantity( $snap['wc']['stock_quantity'] );
					$product->set_stock_status( $snap['wc']['stock_status'] );
					$product->set_gallery_image_ids( (array) $snap['wc']['gallery'] );
					$product->save();
				} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
					// Best effort.
				}
			}
		}
		if ( function_exists( 'wc_delete_product_transients' ) && 'product' === get_post_type( $post_id ) ) {
			wc_delete_product_transients( $post_id );
		}
		return 'restored';
	}

	public static function flush_elementor( $post_id ) {
		delete_post_meta( $post_id, '_elementor_css' );
		delete_post_meta( $post_id, '_elementor_element_cache' );
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
	}

	/* ------------------------------------------------------------------ */
	/* URLs / text                                                          */
	/* ------------------------------------------------------------------ */

	private static $site_host = null;

	public static function site_host() {
		if ( null === self::$site_host ) {
			$host            = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			self::$site_host = preg_replace( '/^www\./i', '', strtolower( $host ) );
		}
		return self::$site_host;
	}

	/**
	 * Normalises a URL to "host/path" (decoded, no trailing slash, no query).
	 */
	public static function norm_url( $url ) {
		$url = html_entity_decode( trim( (string) $url ), ENT_QUOTES, 'UTF-8' );
		if ( '' === $url || '#' === $url[0] ) {
			return '';
		}
		$p = wp_parse_url( $url );
		if ( false === $p ) {
			return '';
		}
		$host = isset( $p['host'] ) ? preg_replace( '/^www\./i', '', strtolower( $p['host'] ) ) : self::site_host();
		$path = isset( $p['path'] ) ? rawurldecode( $p['path'] ) : '/';
		$path = '/' . trim( $path, '/' );
		if ( function_exists( 'mb_strtolower' ) ) {
			$path = mb_strtolower( $path, 'UTF-8' );
		}
		return $host . $path;
	}

	public static function is_internal( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url || preg_match( '#^(mailto:|tel:|javascript:|\#)#i', $url ) ) {
			return false;
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return 0 === strpos( $url, '/' );
		}
		return preg_replace( '/^www\./i', '', strtolower( $host ) ) === self::site_host();
	}

	/**
	 * All hrefs from an HTML string.
	 */
	public static function hrefs( $html ) {
		if ( ! preg_match_all( '/<a\s[^>]*?href\s*=\s*(["\'])(.*?)\1/isu', (string) $html, $m ) ) {
			return array();
		}
		return $m[2];
	}

	public static function plain_text( $html ) {
		$html = (string) $html;
		$html = preg_replace( '/<!--.*?-->/su', ' ', $html );
		$html = preg_replace( '/\[\/?[a-zA-Z_][^\]]*\]/u', ' ', $html );
		$html = preg_replace( '/<(script|style)[^>]*>.*?<\/\1>/isu', ' ', $html );
		$html = preg_replace( '/<\/(p|div|h[1-6]|li|tr|br)\s*>|<br\s*\/?>/iu', "$0\n", $html );
		$text = wp_strip_all_tags( $html, false );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( "/[ \t\x{00A0}]+/u", ' ', $text );
		$text = preg_replace( "/\s*\n\s*/u", "\n", $text );
		return trim( $text );
	}

	public static function word_count( $text ) {
		$words = preg_split( '/[\s\x{200C}]+/u', trim( (string) $text ), -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $words ) ? count( $words ) : 0;
	}

	public static function headings( $html ) {
		$out = array();
		if ( preg_match_all( '/<h([2-4])[^>]*>(.*?)<\/h\1>/isu', (string) $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $h ) {
				$t = trim( html_entity_decode( wp_strip_all_tags( $h[2] ), ENT_QUOTES, 'UTF-8' ) );
				if ( '' !== $t ) {
					$out[] = 'H' . $h[1] . ': ' . $t;
				}
			}
		}
		return $out;
	}

	/**
	 * Final (pretty) permalink even for drafts, so links stay valid after publishing.
	 */
	public static function permalink( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		if ( in_array( $post->post_status, array( 'publish', 'private' ), true ) || ! get_option( 'permalink_structure' ) ) {
			return get_permalink( $post );
		}
		if ( ! function_exists( 'get_sample_permalink' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}
		list( $template, $name ) = get_sample_permalink( $post->ID );
		if ( ! $name ) {
			return get_permalink( $post );
		}
		return str_replace( array( '%postname%', '%pagename%' ), $name, $template );
	}

	public static function decode_slug( $slug ) {
		return rawurldecode( (string) $slug );
	}
}

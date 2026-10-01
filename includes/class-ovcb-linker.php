<?php
/**
 * Content updates by ID/slug and rule-based internal linking.
 *
 * @package OV_Content_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OVCB_Linker {

	/** Tags whose inner text must never receive a link. */
	const BLOCKED_TAGS = 'a|h1|h2|h3|h4|h5|h6|script|style|code|pre|button|textarea|select|option|figcaption|label|noscript|svg';

	/* ================================================================== */
	/* 1) Content updates (edited export file, matched by id / slug)       */
	/* ================================================================== */

	/**
	 * Options: fields[] (content, excerpt, seo, title), post_types[].
	 */
	public static function process_update( $item, $opts, $job_id, $dry ) {
		$types = ! empty( $item['type'] ) && in_array( $item['type'], array( 'post', 'page', 'product' ), true )
			? array( $item['type'] )
			: array( 'post', 'product', 'page' );

		$id = 0;
		if ( ! empty( $item['id'] ) ) {
			$id = OVCB_Helpers::resolve_ref( (int) $item['id'], $types );
		}
		if ( ! $id && ! empty( $item['slug'] ) ) {
			$id = OVCB_Helpers::find_by_slug( $item['slug'], $types );
		}
		if ( ! $id && ! empty( $item['url'] ) ) {
			$id = OVCB_Helpers::resolve_ref( (string) $item['url'], $types );
		}
		$label = isset( $item['title'] ) ? (string) $item['title'] : ( isset( $item['slug'] ) ? (string) $item['slug'] : ( isset( $item['id'] ) ? '#' . $item['id'] : '-' ) );
		if ( ! $id ) {
			return OVCB_Post_Importer::row( 'skipped', 0, $label, 'با این شناسه/نامک موردی در سایت پیدا نشد.' );
		}

		$post   = get_post( $id );
		$label  = get_the_title( $post );
		$fields = (array) $opts['fields'];
		$notes  = array();
		$arr    = array();

		$content = null;
		if ( isset( $item['content'] ) ) {
			$content = (string) $item['content'];
		} elseif ( isset( $item['description'] ) && 'product' === $post->post_type ) {
			$content = (string) $item['description'];
		}
		if ( in_array( 'content', $fields, true ) && null !== $content ) {
			if ( OVCB_Helpers::is_elementor( $id ) ) {
				$notes[] = 'با المنتور ساخته شده؛ محتوا جایگزین نشد (از «قوانین لینک» استفاده کنید).';
			} elseif ( self::same( $content, $post->post_content ) ) {
				$notes[] = 'محتوا تغییری نداشت.';
			} else {
				$arr['post_content'] = $content;
				$before              = self::count_internal( $post->post_content );
				$after               = self::count_internal( $content );
				$notes[]             = sprintf( 'لینک داخلی قبل: %d، بعد: %d', $before, $after );
			}
		}

		$excerpt = null;
		if ( isset( $item['excerpt'] ) ) {
			$excerpt = (string) $item['excerpt'];
		} elseif ( isset( $item['short_description'] ) ) {
			$excerpt = (string) $item['short_description'];
		}
		if ( in_array( 'excerpt', $fields, true ) && null !== $excerpt && ! self::same( $excerpt, $post->post_excerpt ) ) {
			$arr['post_excerpt'] = $excerpt;
			$notes[]             = 'خلاصه/توضیح کوتاه تغییر کرد.';
		}

		if ( in_array( 'title', $fields, true ) && ! empty( $item['title'] ) && trim( (string) $item['title'] ) !== $post->post_title ) {
			$arr['post_title'] = sanitize_text_field( (string) $item['title'] );
			$notes[]           = 'عنوان تغییر کرد.';
		}

		$seo_changed = false;
		if ( in_array( 'seo', $fields, true ) && ! empty( $item['seo'] ) && is_array( $item['seo'] ) ) {
			$cur = OVCB_Helpers::get_seo( $id );
			foreach ( $item['seo'] as $k => $v ) {
				if ( isset( $cur[ $k ] ) && trim( (string) $cur[ $k ] ) !== trim( is_array( $v ) ? implode( ',', $v ) : (string) $v ) ) {
					$seo_changed = true;
				}
			}
			if ( $seo_changed ) {
				$notes[] = 'متای سئو تغییر کرد.';
			}
		}

		if ( ! $arr && ! $seo_changed ) {
			return OVCB_Post_Importer::row( 'unchanged', $id, $label, implode( ' | ', $notes ) ? implode( ' | ', $notes ) : 'تغییری لازم نبود.' );
		}
		if ( $dry ) {
			return OVCB_Post_Importer::row( 'dry-update', $id, $label, implode( ' | ', $notes ) );
		}

		OVCB_Helpers::backup( $id, $job_id, 'update' );
		if ( $arr ) {
			$arr['ID'] = $id;
			$res       = wp_update_post( wp_slash( $arr ), true );
			if ( is_wp_error( $res ) ) {
				return OVCB_Post_Importer::row( 'error', $id, $label, $res->get_error_message() );
			}
		}
		if ( $seo_changed ) {
			OVCB_Helpers::set_seo( $id, $item['seo'] );
		}
		if ( 'product' === $post->post_type && function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $id );
		}
		return OVCB_Post_Importer::row( 'updated', $id, $label, implode( ' | ', $notes ) );
	}

	/* ================================================================== */
	/* 2) Link rules (anchor text -> URL)                                  */
	/* ================================================================== */

	/**
	 * Normalises raw rules from the uploaded file. Returns [rules, warnings].
	 */
	public static function prepare_rules( $raw ) {
		$rules    = array();
		$warnings = array();
		foreach ( (array) $raw as $i => $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$anchors = OVCB_Helpers::to_list( isset( $r['anchors'] ) ? $r['anchors'] : ( isset( $r['anchor'] ) ? array( $r['anchor'] ) : array() ) );
			if ( ! $anchors ) {
				$warnings[] = 'قانون ' . ( $i + 1 ) . ': متن لنگر (anchor) ندارد.';
				continue;
			}
			$url       = '';
			$target_id = 0;
			if ( ! empty( $r['target'] ) ) {
				$target_id = OVCB_Helpers::resolve_ref( $r['target'], array( 'post', 'page', 'product' ) );
			} elseif ( ! empty( $r['target_id'] ) ) {
				$target_id = OVCB_Helpers::resolve_ref( (int) $r['target_id'], array( 'post', 'page', 'product' ) );
			} elseif ( ! empty( $r['target_slug'] ) ) {
				$target_id = OVCB_Helpers::resolve_ref( array( 'slug' => $r['target_slug'], 'type' => isset( $r['target_type'] ) ? $r['target_type'] : '' ), array( 'post', 'page', 'product' ) );
			}
			if ( $target_id ) {
				$url = OVCB_Helpers::permalink( $target_id );
				if ( 'publish' !== get_post_status( $target_id ) ) {
					$warnings[] = 'قانون «' . $anchors[0] . '»: مقصد هنوز منتشر نشده؛ لینک با آدرس نهایی ساخته می‌شود و بعد از انتشار کار می‌کند.';
				}
			} elseif ( ! empty( $r['url'] ) ) {
				$url = esc_url_raw( (string) $r['url'] );
				if ( $url && OVCB_Helpers::is_internal( $url ) ) {
					$target_id = (int) url_to_postid( $url );
				}
			}
			if ( ! $url ) {
				$warnings[] = 'قانون «' . $anchors[0] . '»: مقصد پیدا نشد و نادیده گرفته شد.';
				continue;
			}
			$only    = array();
			$exclude = array();
			foreach ( OVCB_Helpers::to_list( isset( $r['apply_to'] ) ? $r['apply_to'] : array() ) as $ref ) {
				$pid = OVCB_Helpers::resolve_ref( $ref, array( 'post', 'page', 'product' ) );
				if ( $pid ) {
					$only[] = $pid;
				}
			}
			foreach ( OVCB_Helpers::to_list( isset( $r['exclude'] ) ? $r['exclude'] : array() ) as $ref ) {
				$pid = OVCB_Helpers::resolve_ref( $ref, array( 'post', 'page', 'product' ) );
				if ( $pid ) {
					$exclude[] = $pid;
				}
			}
			usort(
				$anchors,
				function ( $a, $b ) {
					return self::len( $b ) - self::len( $a );
				}
			);
			$rules[] = array(
				'anchors'      => $anchors,
				'url'          => $url,
				'norm'         => OVCB_Helpers::norm_url( $url ),
				'target_id'    => (int) $target_id,
				'title'        => isset( $r['title'] ) ? sanitize_text_field( (string) $r['title'] ) : '',
				'max_per_post' => isset( $r['max_per_post'] ) ? max( 1, (int) $r['max_per_post'] ) : 1,
				'max_total'    => isset( $r['max_total'] ) ? max( 0, (int) $r['max_total'] ) : 0,
				'new_tab'      => ! empty( $r['new_tab'] ),
				'nofollow'     => ! empty( $r['nofollow'] ),
				'only'         => $only,
				'exclude'      => $exclude,
				'has_only'     => ! empty( $r['apply_to'] ),
			);
		}
		// Longest anchors first so "خرید میلگرد A3" wins over "میلگرد".
		usort(
			$rules,
			function ( $a, $b ) {
				return self::len( $b['anchors'][0] ) - self::len( $a['anchors'][0] );
			}
		);
		return array( $rules, $warnings );
	}

	/**
	 * Apply all rules to one source post.
	 *
	 * @param array $usage Per-rule usage counters across the job (by reference).
	 */
	public static function process_rules( $post_id, $rules, $opts, $job_id, $dry, &$usage ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return OVCB_Post_Importer::row( 'skipped', 0, '#' . $post_id, 'پیدا نشد.' );
		}
		$label  = get_the_title( $post );
		$self   = OVCB_Helpers::norm_url( OVCB_Helpers::permalink( $post_id ) );
		$budget = max( 1, (int) $opts['max_links'] );
		$added  = array();

		// Rules applicable to this post.
		$active = array();
		foreach ( $rules as $i => $rule ) {
			if ( $rule['target_id'] === (int) $post_id || $rule['norm'] === $self ) {
				continue;
			}
			if ( $rule['has_only'] && ! in_array( (int) $post_id, $rule['only'], true ) ) {
				continue;
			}
			if ( in_array( (int) $post_id, $rule['exclude'], true ) ) {
				continue;
			}
			if ( $rule['max_total'] && ( isset( $usage[ $i ] ) ? $usage[ $i ] : 0 ) >= $rule['max_total'] ) {
				continue;
			}
			$active[ $i ] = $rule;
		}
		if ( ! $active ) {
			return OVCB_Post_Importer::row( 'unchanged', $post_id, $label, 'قانونی برای این مورد صدق نمی‌کند.' );
		}

		$is_el   = OVCB_Helpers::is_elementor( $post_id );
		$changes = array();
		$el_data = null;

		// Existing links in the whole post (so we never duplicate a link).
		$existing_html = $post->post_content . ' ' . $post->post_excerpt;
		if ( $is_el ) {
			$raw_el = (string) get_post_meta( $post_id, '_elementor_data', true );
			$existing_html .= ' ' . self::elementor_html( $raw_el );
		}
		$linked = array();
		foreach ( OVCB_Helpers::hrefs( $existing_html ) as $h ) {
			$linked[ OVCB_Helpers::norm_url( $h ) ] = true;
		}

		if ( $is_el ) {
			if ( empty( $opts['elementor'] ) ) {
				return OVCB_Post_Importer::row( 'skipped', $post_id, $label, 'با المنتور ساخته شده (گزینه ویرایش المنتور خاموش است).' );
			}
			$data = json_decode( $raw_el, true );
			if ( is_array( $data ) ) {
				$touched = false;
				self::walk_elementor(
					$data,
					function ( $html ) use ( $active, $opts, &$budget, &$added, &$linked, &$touched ) {
						$new = self::apply( $html, $active, $opts, $budget, $added, $linked );
						if ( $new !== $html ) {
							$touched = true;
						}
						return $new;
					}
				);
				if ( $touched ) {
					$el_data = $data;
				}
			}
		} else {
			$new = self::apply( $post->post_content, $active, $opts, $budget, $added, $linked );
			if ( $new !== $post->post_content ) {
				$changes['post_content'] = $new;
			}
		}
		if ( 'product' === $post->post_type && ! empty( $opts['short_desc'] ) && $budget > 0 && '' !== trim( $post->post_excerpt ) ) {
			$new = self::apply( $post->post_excerpt, $active, $opts, $budget, $added, $linked );
			if ( $new !== $post->post_excerpt ) {
				$changes['post_excerpt'] = $new;
			}
		}

		if ( ! $added ) {
			return OVCB_Post_Importer::row( 'unchanged', $post_id, $label, 'عبارت مناسبی برای لینک پیدا نشد یا لینک‌ها از قبل وجود دارند.' );
		}

		foreach ( $added as $a ) {
			$usage[ $a['rule'] ] = ( isset( $usage[ $a['rule'] ] ) ? $usage[ $a['rule'] ] : 0 ) + 1;
		}
		$msg = implode(
			' | ',
			array_map(
				function ( $a ) {
					return '«' . $a['anchor'] . '» ← ' . rawurldecode( $a['url'] );
				},
				$added
			)
		);

		if ( $dry ) {
			return OVCB_Post_Importer::row( 'dry-update', $post_id, $label, count( $added ) . ' لینک: ' . $msg );
		}

		OVCB_Helpers::backup( $post_id, $job_id, 'update' );
		if ( $el_data ) {
			update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $el_data ) ) );
			OVCB_Helpers::flush_elementor( $post_id );
		}
		if ( $changes ) {
			$changes['ID'] = $post_id;
			$res           = wp_update_post( wp_slash( $changes ), true );
			if ( is_wp_error( $res ) ) {
				return OVCB_Post_Importer::row( 'error', $post_id, $label, $res->get_error_message() );
			}
		}
		if ( 'product' === $post->post_type && function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $post_id );
		}
		return OVCB_Post_Importer::row( 'updated', $post_id, $label, count( $added ) . ' لینک: ' . $msg );
	}

	/**
	 * Insert links into an HTML fragment. Never touches tags, attributes,
	 * shortcodes, existing links or headings.
	 */
	public static function apply( $html, $rules, $opts, &$budget, &$added, &$linked ) {
		$html = (string) $html;
		if ( '' === trim( $html ) ) {
			return $html;
		}
		$skip_linked = ! empty( $opts['skip_linked'] );
		foreach ( $rules as $i => $rule ) {
			if ( $budget <= 0 ) {
				break;
			}
			if ( $skip_linked && isset( $linked[ $rule['norm'] ] ) ) {
				continue;
			}
			$left = min( $rule['max_per_post'], $budget );
			foreach ( $rule['anchors'] as $anchor ) {
				if ( $left <= 0 ) {
					break;
				}
				$count = 0;
				$html  = self::link_anchor( $html, $anchor, $rule, $left, $count, $matched );
				if ( $count ) {
					$left   -= $count;
					$budget -= $count;
					$linked[ $rule['norm'] ] = true;
					for ( $k = 0; $k < $count; $k++ ) {
						$added[] = array(
							'rule'   => $i,
							'anchor' => $matched,
							'url'    => $rule['url'],
						);
					}
					if ( $skip_linked ) {
						break;
					}
				}
			}
		}
		return $html;
	}

	private static function link_anchor( $html, $anchor, $rule, $limit, &$count, &$matched ) {
		$count   = 0;
		$matched = $anchor;
		$tokens  = preg_split( '/(<!--.*?-->|<[^>]+>|\[\/?[a-zA-Z_][^\]]*\])/su', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $tokens ) ) {
			return $html;
		}
		$pattern = '/(?<![\p{L}\p{N}\p{Mn}\x{200C}_])(' . self::anchor_regex( $anchor ) . ')(?![\p{L}\p{N}\p{Mn}\x{200C}_])/iu';
		$depth   = 0;
		$attrs   = self::link_attrs( $rule );
		foreach ( $tokens as $k => $tok ) {
			if ( '' === $tok ) {
				continue;
			}
			if ( '<' === $tok[0] || ( '[' === $tok[0] && preg_match( '/^\[\/?[a-zA-Z_]/', $tok ) ) ) {
				if ( preg_match( '/^<(\/?)(' . self::BLOCKED_TAGS . ')\b/i', $tok, $m ) && '/>' !== substr( $tok, -2 ) ) {
					$depth += ( '/' === $m[1] ) ? -1 : 1;
					if ( $depth < 0 ) {
						$depth = 0;
					}
				}
				continue;
			}
			if ( $depth > 0 || $count >= $limit ) {
				continue;
			}
			$remaining = $limit - $count;
			$new       = preg_replace_callback(
				$pattern,
				static function ( $mm ) use ( $attrs, &$matched ) {
					$matched = $mm[1];
					return '<a href="' . $attrs['href'] . '"' . $attrs['extra'] . '>' . $mm[1] . '</a>';
				},
				$tok,
				$remaining,
				$n
			);
			if ( $n && null !== $new ) {
				$tokens[ $k ] = $new;
				$count       += $n;
			}
		}
		return $count ? implode( '', $tokens ) : $html;
	}

	/**
	 * Regex for an anchor: spaces and ZWNJ (half-space) are interchangeable,
	 * and Arabic/Persian ی/ي and ک/ك variants match each other.
	 */
	private static function anchor_regex( $anchor ) {
		$anchor = trim( preg_replace( '/[\s\x{200C}]+/u', ' ', $anchor ) );
		$chars  = preg_split( '//u', $anchor, -1, PREG_SPLIT_NO_EMPTY );
		$out    = '';
		foreach ( $chars as $c ) {
			if ( ' ' === $c ) {
				$out .= '(?:[\s\x{200C}\x{00A0}]|&nbsp;|&zwnj;|&#8204;)+';
			} elseif ( 'ی' === $c || 'ي' === $c || 'ى' === $c ) {
				$out .= '[یيى]';
			} elseif ( 'ک' === $c || 'ك' === $c ) {
				$out .= '[کك]';
			} else {
				$out .= preg_quote( $c, '/' );
			}
		}
		return $out;
	}

	private static function link_attrs( $rule ) {
		$extra = '';
		if ( $rule['title'] ) {
			$extra .= ' title="' . esc_attr( $rule['title'] ) . '"';
		}
		$rel = array();
		if ( $rule['new_tab'] ) {
			$extra .= ' target="_blank"';
			$rel[]  = 'noopener';
		}
		if ( $rule['nofollow'] ) {
			$rel[] = 'nofollow';
		}
		if ( $rel ) {
			$extra .= ' rel="' . implode( ' ', $rel ) . '"';
		}
		return array(
			'href'  => esc_url( $rule['url'] ),
			'extra' => $extra,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Elementor                                                            */
	/* ------------------------------------------------------------------ */

	/** Widget types and the setting holding rich text. */
	private static function elementor_fields() {
		return apply_filters(
			'ovcb_elementor_text_fields',
			array(
				'text-editor' => array( 'editor' ),
			)
		);
	}

	public static function walk_elementor( &$elements, $fn ) {
		$fields = self::elementor_fields();
		foreach ( $elements as &$el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( isset( $el['widgetType'] ) && isset( $fields[ $el['widgetType'] ] ) ) {
				foreach ( $fields[ $el['widgetType'] ] as $f ) {
					if ( isset( $el['settings'][ $f ] ) && is_string( $el['settings'][ $f ] ) ) {
						$el['settings'][ $f ] = $fn( $el['settings'][ $f ] );
					}
				}
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				self::walk_elementor( $el['elements'], $fn );
			}
		}
		unset( $el );
	}

	private static function elementor_html( $raw ) {
		$data = json_decode( (string) $raw, true );
		if ( ! is_array( $data ) ) {
			return '';
		}
		$html = '';
		self::walk_elementor(
			$data,
			function ( $h ) use ( &$html ) {
				$html .= ' ' . $h;
				return $h;
			}
		);
		return $html;
	}

	/* ------------------------------------------------------------------ */

	private static function same( $a, $b ) {
		$n = function ( $s ) {
			return preg_replace( '/\s+/u', ' ', trim( str_replace( "\r\n", "\n", (string) $s ) ) );
		};
		return $n( $a ) === $n( $b );
	}

	private static function count_internal( $html ) {
		$c = 0;
		foreach ( OVCB_Helpers::hrefs( $html ) as $h ) {
			if ( OVCB_Helpers::is_internal( $h ) ) {
				++$c;
			}
		}
		return $c;
	}

	private static function len( $s ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $s, 'UTF-8' ) : strlen( (string) $s );
	}
}

<?php
/**
 * Imports posts / pages (articles).
 *
 * @package OV_Content_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OVCB_Post_Importer {

	/**
	 * Process one item.
	 *
	 * Options: post_type, match (update|skip|create|update_only), force_draft,
	 * images, create_terms.
	 *
	 * @return array Result row.
	 */
	public static function process( $item, $opts, $job_id, $dry ) {
		$post_type = in_array( $opts['post_type'], array( 'post', 'page' ), true ) ? $opts['post_type'] : 'post';
		$title     = isset( $item['title'] ) ? sanitize_text_field( (string) $item['title'] ) : '';
		$label     = $title ? $title : ( isset( $item['slug'] ) ? (string) $item['slug'] : '#' );
		$notes     = array();

		// ---- Find existing.
		$existing = 0;
		if ( ! empty( $item['id'] ) ) {
			$p = get_post( (int) $item['id'] );
			if ( $p && $p->post_type === $post_type && 'trash' !== $p->post_status ) {
				$existing = (int) $p->ID;
			}
		}
		if ( ! $existing && ! empty( $item['slug'] ) ) {
			$existing = OVCB_Helpers::find_by_slug( $item['slug'], array( $post_type ) );
		}

		if ( $existing && '' === $title ) {
			$label = get_the_title( $existing );
		}
		$match = $opts['match'];
		if ( $existing && 'skip' === $match ) {
			return self::row( 'skipped', $existing, $label, 'از قبل وجود دارد (تنظیم: رد شود).' );
		}
		if ( ! $existing && 'update_only' === $match ) {
			return self::row( 'skipped', 0, $label, 'مورد متناظری در سایت پیدا نشد.' );
		}
		if ( 'create' === $match ) {
			$existing = 0;
		}
		if ( ! $existing && '' === $title ) {
			return self::row( 'error', 0, $label, 'عنوان (title) برای ساخت نوشته جدید الزامی است.' );
		}

		// ---- Build post array (only keys that are present in the item).
		$postarr = array();
		if ( '' !== $title ) {
			$postarr['post_title'] = $title;
		}
		$content = null;
		if ( isset( $item['content'] ) ) {
			$content = (string) $item['content'];
		} elseif ( isset( $item['content_html'] ) ) {
			$content = (string) $item['content_html'];
		}
		if ( null !== $content ) {
			if ( $existing && OVCB_Helpers::is_elementor( $existing ) ) {
				$notes[] = 'این نوشته با المنتور ساخته شده؛ محتوا تغییر نکرد (فقط سایر فیلدها).';
			} else {
				$postarr['post_content'] = $content;
			}
		}
		if ( isset( $item['excerpt'] ) ) {
			$postarr['post_excerpt'] = sanitize_textarea_field( (string) $item['excerpt'] );
		}
		if ( ! empty( $item['slug'] ) && ( ! $existing || ! empty( $opts['update_slug'] ) ) ) {
			$postarr['post_name'] = sanitize_title( rawurldecode( (string) $item['slug'] ) );
		}

		// Status.
		$allowed = array( 'draft', 'pending', 'publish', 'future', 'private' );
		$status  = isset( $item['status'] ) ? sanitize_key( $item['status'] ) : '';
		if ( ! $existing ) {
			$postarr['post_status'] = ( ! empty( $opts['force_draft'] ) || ! in_array( $status, $allowed, true ) ) ? 'draft' : $status;
		} elseif ( $status && empty( $opts['force_draft'] ) && in_array( $status, $allowed, true ) ) {
			$postarr['post_status'] = $status;
		}
		if ( ! empty( $item['date'] ) ) {
			$ts = strtotime( (string) $item['date'] );
			if ( $ts ) {
				$postarr['post_date'] = gmdate( 'Y-m-d H:i:s', $ts );
				$postarr['edit_date'] = true;
			}
		}
		if ( ! empty( $item['author'] ) ) {
			$user = ctype_digit( (string) $item['author'] ) ? get_user_by( 'id', (int) $item['author'] ) : get_user_by( 'login', (string) $item['author'] );
			if ( ! $user ) {
				$user = get_user_by( 'email', (string) $item['author'] );
			}
			if ( $user ) {
				$postarr['post_author'] = (int) $user->ID;
			} else {
				$notes[] = 'نویسنده «' . sanitize_text_field( (string) $item['author'] ) . '» پیدا نشد.';
			}
		}
		if ( ! $existing && empty( $postarr['post_author'] ) ) {
			$postarr['post_author'] = get_current_user_id();
		}
		if ( isset( $item['comment_status'] ) ) {
			$postarr['comment_status'] = OVCB_Helpers::bool( $item['comment_status'] ) || 'open' === $item['comment_status'] ? 'open' : 'closed';
		}

		// Terms (posts only).
		$cats = null;
		$tags = null;
		if ( 'post' === $post_type ) {
			if ( isset( $item['categories'] ) ) {
				$cats = OVCB_Helpers::resolve_terms( $item['categories'], 'category', ! empty( $opts['create_terms'] ), $dry );
			}
			if ( isset( $item['tags'] ) ) {
				$tags = OVCB_Helpers::resolve_terms( $item['tags'], 'post_tag', ! empty( $opts['create_terms'] ), $dry );
			}
			foreach ( array( $cats, $tags ) as $r ) {
				if ( $r && $r['created'] ) {
					$notes[] = ( $dry ? 'ساخته خواهد شد: ' : 'ساخته شد: ' ) . implode( '، ', $r['created'] );
				}
				if ( $r && $r['missing'] ) {
					$notes[] = 'پیدا نشد: ' . implode( '، ', $r['missing'] );
				}
			}
		}

		$seo = isset( $item['seo'] ) && is_array( $item['seo'] ) ? $item['seo'] : array();
		foreach ( array( 'meta_title' => 'title', 'meta_description' => 'description', 'focus_keyword' => 'focus_keyword' ) as $flat => $key ) {
			if ( isset( $item[ $flat ] ) && ! isset( $seo[ $key ] ) ) {
				$seo[ $key ] = $item[ $flat ];
			}
		}

		$words = isset( $postarr['post_content'] ) ? OVCB_Helpers::word_count( OVCB_Helpers::plain_text( $postarr['post_content'] ) ) : 0;
		if ( $words ) {
			$notes[] = $words . ' کلمه';
		}

		// ---- Dry run.
		if ( $dry ) {
			$what = $existing ? 'update' : 'create';
			$msg  = $existing ? 'به‌روزرسانی خواهد شد.' : 'به‌صورت «' . self::status_label( $postarr['post_status'] ) . '» ساخته خواهد شد.';
			return self::row( 'dry-' . $what, $existing, $label, trim( $msg . ' ' . implode( ' | ', $notes ) ) );
		}

		// ---- Write.
		if ( $existing ) {
			OVCB_Helpers::backup( $existing, $job_id, 'update' );
			$postarr['ID'] = $existing;
			$id            = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$postarr['post_type'] = $post_type;
			if ( ! isset( $postarr['post_content'] ) ) {
				$postarr['post_content'] = '';
			}
			$id = wp_insert_post( wp_slash( $postarr ), true );
		}
		if ( is_wp_error( $id ) ) {
			return self::row( 'error', $existing, $label, $id->get_error_message() );
		}
		$id = (int) $id;
		if ( ! $existing ) {
			OVCB_Helpers::backup( $id, $job_id, 'create' );
		}

		if ( $cats && ( $cats['ids'] || empty( $item['categories'] ) ) ) {
			wp_set_object_terms( $id, $cats['ids'], 'category' );
		}
		if ( $tags ) {
			wp_set_object_terms( $id, $tags['ids'], 'post_tag' );
		}

		$image = isset( $item['featured_image'] ) ? $item['featured_image'] : ( isset( $item['image'] ) ? $item['image'] : '' );
		if ( $image && ! empty( $opts['images'] ) ) {
			$alt = isset( $item['featured_image_alt'] ) ? $item['featured_image_alt'] : $title;
			$att = OVCB_Helpers::image( $image, $id, $alt );
			if ( is_wp_error( $att ) ) {
				$notes[] = 'تصویر شاخص: ' . $att->get_error_message();
			} else {
				set_post_thumbnail( $id, $att );
			}
		}

		if ( $seo ) {
			$storage = OVCB_Helpers::set_seo( $id, $seo );
			if ( 'ovcb' === $storage ) {
				$notes[] = 'افزونه سئو (Yoast/RankMath/SEOPress) پیدا نشد؛ متا در فیلد داخلی ذخیره شد.';
			}
		}

		return self::row( $existing ? 'updated' : 'created', $id, $label, implode( ' | ', $notes ) );
	}

	public static function status_label( $status ) {
		$map = array(
			'draft'   => 'پیش‌نویس',
			'pending' => 'در انتظار بررسی',
			'publish' => 'منتشرشده',
			'future'  => 'زمان‌بندی‌شده',
			'private' => 'خصوصی',
		);
		return isset( $map[ $status ] ) ? $map[ $status ] : $status;
	}

	public static function row( $status, $id, $label, $msg = '' ) {
		return array(
			'status' => $status,
			'id'     => (int) $id,
			'title'  => wp_strip_all_tags( (string) $label ),
			'msg'    => (string) $msg,
			'edit'   => $id ? get_edit_post_link( $id, 'raw' ) : '',
			'view'   => $id ? get_permalink( $id ) : '',
		);
	}
}

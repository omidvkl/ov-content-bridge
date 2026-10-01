<?php
/**
 * Upload parsing, chunked AJAX job runner, history and undo.
 *
 * @package OV_Content_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OVCB_Jobs {

	const LOG_OPTION = 'ovcb_jobs_log';
	const MAX_UPLOAD = 31457280; // 30 MB.

	public static function init() {
		add_action( 'wp_ajax_ovcb_create_job', array( __CLASS__, 'ajax_create' ) );
		add_action( 'wp_ajax_ovcb_run_job', array( __CLASS__, 'ajax_run' ) );
		add_action( 'wp_ajax_ovcb_undo_job', array( __CLASS__, 'ajax_undo' ) );
	}

	private static function guard() {
		if ( ! current_user_can( OVCB_Admin::CAP ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی ندارید.' ), 403 );
		}
		if ( ! check_ajax_referer( 'ovcb_ajax', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'نشست منقضی شده؛ صفحه را تازه‌سازی کنید.' ), 403 );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		wp_raise_memory_limit( 'admin' );
	}

	/* ------------------------------------------------------------------ */
	/* Storage                                                              */
	/* ------------------------------------------------------------------ */

	private static function dir() {
		$u   = wp_upload_dir( null, false );
		$dir = trailingslashit( $u['basedir'] ) . 'ovcb-jobs';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return $dir;
	}

	private static function valid_id( $id ) {
		return is_string( $id ) && preg_match( '/^[a-z0-9]{20}$/', $id );
	}

	private static function path( $id ) {
		return self::dir() . '/job-' . $id . '.php';
	}

	private static function save( $job ) {
		// The "exit" header makes the file unreadable over HTTP even without .htaccess (nginx).
		$json = wp_json_encode( $job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return false !== file_put_contents( self::path( $job['id'] ), "<?php exit; ?>\n" . $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	private static function load( $id ) {
		if ( ! self::valid_id( $id ) ) {
			return null;
		}
		$file = self::path( $id );
		if ( ! file_exists( $file ) ) {
			return null;
		}
		$raw = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$pos = strpos( $raw, "\n" );
		$job = json_decode( substr( $raw, $pos + 1 ), true );
		return is_array( $job ) ? $job : null;
	}

	private static function delete( $id ) {
		if ( self::valid_id( $id ) && file_exists( self::path( $id ) ) ) {
			wp_delete_file( self::path( $id ) );
		}
	}

	/** Remove job files older than 2 days. */
	public static function cleanup() {
		$dir = self::dir();
		foreach ( (array) glob( $dir . '/job-*.php' ) as $f ) {
			if ( $f && filemtime( $f ) < time() - 2 * DAY_IN_SECONDS ) {
				wp_delete_file( $f );
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Parsing                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * @return array|WP_Error Decoded data (assoc array).
	 */
	private static function parse_upload() {
		if ( empty( $_FILES['file'] ) || ! isset( $_FILES['file']['error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return new WP_Error( 'ovcb', 'فایلی انتخاب نشده است.' );
		}
		$f = $_FILES['file']; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		if ( UPLOAD_ERR_OK !== (int) $f['error'] ) {
			return new WP_Error( 'ovcb', 'خطا در آپلود فایل (کد ' . (int) $f['error'] . '). ممکن است حجم فایل از حد مجاز سرور بیشتر باشد.' );
		}
		if ( (int) $f['size'] > self::MAX_UPLOAD ) {
			return new WP_Error( 'ovcb', 'حجم فایل بیش از ۳۰ مگابایت است.' );
		}
		$ext = strtolower( pathinfo( (string) $f['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'json', 'csv', 'txt' ), true ) ) {
			return new WP_Error( 'ovcb', 'فقط فایل JSON یا CSV قابل قبول است.' );
		}
		if ( ! is_uploaded_file( $f['tmp_name'] ) ) {
			return new WP_Error( 'ovcb', 'فایل آپلودشده معتبر نیست.' );
		}
		$raw = file_get_contents( $f['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$raw = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $raw );

		if ( 'csv' === $ext ) {
			return array( 'items' => self::parse_csv( $raw ) );
		}
		$data = json_decode( $raw, true );
		if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'ovcb', 'فایل JSON معتبر نیست: ' . json_last_error_msg() );
		}
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'ovcb', 'ساختار فایل JSON قابل شناسایی نیست.' );
		}
		// A plain list.
		if ( isset( $data[0] ) ) {
			return array( 'items' => $data );
		}
		return $data;
	}

	private static function parse_csv( $raw ) {
		$fh = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fwrite( $fh, $raw ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		rewind( $fh );
		$first = strtok( $raw, "\n" );
		$delim = ( substr_count( (string) $first, ';' ) > substr_count( (string) $first, ',' ) ) ? ';' : ',';
		if ( substr_count( (string) $first, "\t" ) > substr_count( (string) $first, $delim ) ) {
			$delim = "\t";
		}
		$header = fgetcsv( $fh, 0, $delim, '"', '\\' );
		if ( ! $header ) {
			return array();
		}
		$header = array_map(
			function ( $h ) {
				return strtolower( trim( str_replace( ' ', '_', (string) $h ) ) );
			},
			$header
		);
		$seo_map = array(
			'seo_title'        => 'title',
			'meta_title'       => 'title',
			'seo_description'  => 'description',
			'meta_description' => 'description',
			'focus_keyword'    => 'focus_keyword',
			'seo_focus_keyword' => 'focus_keyword',
		);
		$items = array();
		while ( false !== ( $row = fgetcsv( $fh, 0, $delim, '"', '\\' ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			if ( array( null ) === $row ) {
				continue;
			}
			$item = array();
			foreach ( $header as $i => $key ) {
				if ( '' === $key || ! isset( $row[ $i ] ) || '' === trim( (string) $row[ $i ] ) ) {
					continue;
				}
				$val = $row[ $i ];
				if ( isset( $seo_map[ $key ] ) ) {
					$item['seo'][ $seo_map[ $key ] ] = $val;
				} elseif ( 0 === strpos( $key, 'attribute:' ) ) {
					$item['attributes'][] = array(
						'name'    => trim( substr( $key, 10 ) ),
						'options' => $val,
					);
				} else {
					$item[ $key ] = $val;
				}
			}
			if ( $item ) {
				$items[] = $item;
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $items;
	}

	/* ------------------------------------------------------------------ */
	/* Options per job kind                                                 */
	/* ------------------------------------------------------------------ */

	private static function read_opts( $kind ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$p     = wp_unslash( $_POST );
		$match = isset( $p['match'] ) ? sanitize_key( $p['match'] ) : 'update';
		if ( ! in_array( $match, array( 'update', 'skip', 'create', 'update_only' ), true ) ) {
			$match = 'update';
		}
		$opts = array(
			'match'        => $match,
			'force_draft'  => ! empty( $p['force_draft'] ),
			'images'       => ! empty( $p['images'] ),
			'create_terms' => ! empty( $p['create_terms'] ),
			'update_slug'  => ! empty( $p['update_slug'] ),
		);
		if ( 'posts' === $kind ) {
			$opts['post_type'] = ( isset( $p['post_type'] ) && 'page' === $p['post_type'] ) ? 'page' : 'post';
		}
		if ( 'linking' === $kind ) {
			$fields = isset( $p['fields'] ) ? array_map( 'sanitize_key', (array) $p['fields'] ) : array( 'content' );
			$types  = isset( $p['scope_types'] ) ? array_map( 'sanitize_key', (array) $p['scope_types'] ) : array( 'post' );
			$status = isset( $p['scope_status'] ) ? array_map( 'sanitize_key', (array) $p['scope_status'] ) : array( 'publish' );
			$opts   = array(
				'fields'       => array_values( array_intersect( $fields, array( 'content', 'excerpt', 'seo', 'title' ) ) ),
				'scope_types'  => array_values( array_intersect( $types, array( 'post', 'product', 'page' ) ) ),
				'scope_status' => array_values( array_intersect( $status, array( 'publish', 'draft', 'pending', 'future', 'private' ) ) ),
				'max_links'    => isset( $p['max_links'] ) ? max( 1, min( 50, (int) $p['max_links'] ) ) : 5,
				'skip_linked'  => ! empty( $p['skip_linked'] ),
				'short_desc'   => ! empty( $p['short_desc'] ),
				'elementor'    => ! empty( $p['elementor'] ),
			);
		}
		// phpcs:enable
		return $opts;
	}

	/* ------------------------------------------------------------------ */
	/* AJAX: create                                                         */
	/* ------------------------------------------------------------------ */

	public static function ajax_create() {
		self::guard();
		self::cleanup();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$kind = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';
		$dry  = ! empty( $_POST['dry_run'] );
		// phpcs:enable
		if ( ! in_array( $kind, array( 'posts', 'products', 'linking' ), true ) ) {
			wp_send_json_error( array( 'message' => 'نوع عملیات نامعتبر است.' ) );
		}
		if ( 'products' === $kind && ! function_exists( 'wc_get_product' ) ) {
			wp_send_json_error( array( 'message' => 'ووکامرس فعال نیست.' ) );
		}

		$data = self::parse_upload();
		if ( is_wp_error( $data ) ) {
			wp_send_json_error( array( 'message' => $data->get_error_message() ) );
		}

		$opts     = self::read_opts( $kind );
		$items    = array();
		$warnings = array();
		$rules    = array();

		if ( 'posts' === $kind ) {
			$key   = ( 'page' === $opts['post_type'] && ! empty( $data['pages'] ) ) ? 'pages' : 'posts';
			$items = isset( $data[ $key ] ) ? $data[ $key ] : ( isset( $data['items'] ) ? $data['items'] : ( isset( $data['articles'] ) ? $data['articles'] : array() ) );
		} elseif ( 'products' === $kind ) {
			$items = isset( $data['products'] ) ? $data['products'] : ( isset( $data['items'] ) ? $data['items'] : array() );
		} else {
			// Linking: content updates (edited export) + link rules.
			$updates = array();
			foreach ( array( 'updates', 'posts', 'pages', 'products', 'items' ) as $k ) {
				if ( ! empty( $data[ $k ] ) && is_array( $data[ $k ] ) ) {
					foreach ( $data[ $k ] as $u ) {
						if ( is_array( $u ) ) {
							if ( empty( $u['type'] ) ) {
								if ( 'products' === $k ) {
									$u['type'] = 'product';
								} elseif ( 'pages' === $k ) {
									$u['type'] = 'page';
								} elseif ( 'posts' === $k ) {
									$u['type'] = 'post';
								}
							}
							$u['_op'] = 'update';
							$updates[] = $u;
						}
					}
				}
			}
			$items = $updates;
			if ( ! empty( $data['link_rules'] ) ) {
				list( $rules, $warnings ) = OVCB_Linker::prepare_rules( $data['link_rules'] );
				if ( $rules && $opts['scope_types'] && $opts['scope_status'] ) {
					$types = $opts['scope_types'];
					if ( ! function_exists( 'wc_get_product' ) ) {
						$types = array_diff( $types, array( 'product' ) );
					}
					$ids = get_posts(
						array(
							'post_type'        => array_values( $types ),
							'post_status'      => $opts['scope_status'],
							'posts_per_page'   => -1,
							'fields'           => 'ids',
							'orderby'          => 'ID',
							'order'            => 'ASC',
							'no_found_rows'    => true,
							'suppress_filters' => true,
						)
					);
					foreach ( $ids as $id ) {
						$items[] = array(
							'_op'     => 'rules',
							'post_id' => (int) $id,
						);
					}
				}
			}
		}

		$items = array_values( array_filter( (array) $items, 'is_array' ) );
		if ( ! $items ) {
			wp_send_json_error(
				array(
					'message'  => 'هیچ موردی برای پردازش در فایل پیدا نشد. ساختار فایل را با نمونه‌ها مقایسه کنید.',
					'warnings' => $warnings,
				)
			);
		}

		$id  = strtolower( wp_generate_password( 20, false, false ) );
		$job = array(
			'id'      => $id,
			'kind'    => $kind,
			'dry'     => $dry,
			'opts'    => $opts,
			'items'   => $items,
			'rules'   => $rules,
			'usage'   => array(),
			'created' => time(),
			'user'    => get_current_user_id(),
			'file'    => isset( $_FILES['file']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['file']['name'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
		);
		if ( ! self::save( $job ) ) {
			wp_send_json_error( array( 'message' => 'امکان ذخیره فایل موقت در پوشه uploads نیست (دسترسی نوشتن را بررسی کنید).' ) );
		}
		if ( ! $dry ) {
			self::log_start( $job );
		}

		wp_send_json_success(
			array(
				'job'      => $id,
				'total'    => count( $items ),
				'rules'    => count( $rules ),
				'dry'      => $dry,
				'warnings' => $warnings,
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* AJAX: run a chunk                                                    */
	/* ------------------------------------------------------------------ */

	public static function ajax_run() {
		self::guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$id     = isset( $_POST['job'] ) ? sanitize_key( wp_unslash( $_POST['job'] ) ) : '';
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		// phpcs:enable
		$job = self::load( $id );
		if ( ! $job ) {
			wp_send_json_error( array( 'message' => 'عملیات پیدا نشد یا منقضی شده است.' ) );
		}

		$batch = 'products' === $job['kind'] ? 3 : ( 'linking' === $job['kind'] ? 15 : 5 );
		if ( ! $job['dry'] && ! empty( $job['opts']['images'] ) ) {
			$batch = 2;
		}
		$total   = count( $job['items'] );
		$end     = min( $total, $offset + $batch );
		$results = array();
		$touched = array();
		$usage   = isset( $job['usage'] ) && is_array( $job['usage'] ) ? $job['usage'] : array();

		// Keep other plugins' heavy save hooks from slowing things down where safe.
		wp_defer_term_counting( true );

		for ( $i = $offset; $i < $end; $i++ ) {
			$item = $job['items'][ $i ];
			try {
				switch ( $job['kind'] ) {
					case 'posts':
						$res = OVCB_Post_Importer::process( $item, $job['opts'], $job['id'], $job['dry'] );
						break;
					case 'products':
						$res = OVCB_Product_Importer::process( $item, $job['opts'], $job['id'], $job['dry'] );
						break;
					default:
						if ( isset( $item['_op'] ) && 'rules' === $item['_op'] ) {
							$res = OVCB_Linker::process_rules( (int) $item['post_id'], $job['rules'], $job['opts'], $job['id'], $job['dry'], $usage );
						} else {
							$res = OVCB_Linker::process_update( $item, $job['opts'], $job['id'], $job['dry'] );
						}
				}
			} catch ( Throwable $e ) {
				$res = OVCB_Post_Importer::row( 'error', 0, '#' . ( $i + 1 ), $e->getMessage() );
			}
			$res['n']  = $i + 1;
			$results[] = $res;
			if ( ! $job['dry'] && in_array( $res['status'], array( 'created', 'updated' ), true ) && $res['id'] ) {
				$touched[] = (int) $res['id'];
			}
		}

		wp_defer_term_counting( false );

		$done = $end >= $total;
		if ( ! $job['dry'] ) {
			self::log_progress( $job['id'], $touched, $results, $done );
		}
		if ( $done ) {
			self::delete( $job['id'] );
		} elseif ( 'linking' === $job['kind'] && $usage !== $job['usage'] ) {
			$job['usage'] = $usage;
			self::save( $job );
		}

		wp_send_json_success(
			array(
				'results' => $results,
				'next'    => $end,
				'total'   => $total,
				'done'    => $done,
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* History / undo                                                       */
	/* ------------------------------------------------------------------ */

	public static function log() {
		$log = get_option( self::LOG_OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	private static function log_start( $job ) {
		$log              = self::log();
		$log[ $job['id'] ] = array(
			'kind'   => $job['kind'],
			'time'   => time(),
			'user'   => (int) $job['user'],
			'file'   => $job['file'],
			'total'  => count( $job['items'] ),
			'ids'    => array(),
			'counts' => array(),
			'done'   => false,
			'undone' => false,
		);
		if ( count( $log ) > 30 ) {
			$log = array_slice( $log, -30, null, true );
		}
		update_option( self::LOG_OPTION, $log, false );
	}

	private static function log_progress( $id, $touched, $results, $done ) {
		$log = self::log();
		if ( ! isset( $log[ $id ] ) ) {
			return;
		}
		$log[ $id ]['ids'] = array_values( array_unique( array_merge( $log[ $id ]['ids'], $touched ) ) );
		foreach ( $results as $r ) {
			$s                         = $r['status'];
			$log[ $id ]['counts'][ $s ] = ( isset( $log[ $id ]['counts'][ $s ] ) ? $log[ $id ]['counts'][ $s ] : 0 ) + 1;
		}
		if ( $done ) {
			$log[ $id ]['done'] = true;
		}
		update_option( self::LOG_OPTION, $log, false );
	}

	public static function ajax_undo() {
		self::guard();
		$id  = isset( $_POST['job'] ) ? sanitize_key( wp_unslash( $_POST['job'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$log = self::log();
		if ( ! self::valid_id( $id ) || ! isset( $log[ $id ] ) ) {
			wp_send_json_error( array( 'message' => 'این عملیات در تاریخچه پیدا نشد.' ) );
		}
		if ( ! empty( $log[ $id ]['undone'] ) ) {
			wp_send_json_error( array( 'message' => 'این عملیات قبلاً بازگردانی شده است.' ) );
		}
		$counts = array(
			'restored' => 0,
			'trashed'  => 0,
			'none'     => 0,
		);
		foreach ( $log[ $id ]['ids'] as $pid ) {
			$r = OVCB_Helpers::restore( (int) $pid, $id );
			++$counts[ $r ];
		}
		$log[ $id ]['undone'] = time();
		update_option( self::LOG_OPTION, $log, false );
		wp_send_json_success(
			array(
				'message' => sprintf( 'بازگردانی انجام شد: %d مورد به حالت قبل برگشت، %d مورد جدید به زباله‌دان منتقل شد.', $counts['restored'], $counts['trashed'] ),
			)
		);
	}
}

<?php
/**
 * Uninstall: removes plugin options, temp files and undo snapshots.
 * Imported posts/products and SEO meta are content and are NOT deleted.
 *
 * @package OV_Content_Bridge
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'ovcb_jobs_log' );
delete_post_meta_by_key( '_ovcb_backup' );

$ovcb_upload = wp_upload_dir( null, false );
$ovcb_dir    = trailingslashit( $ovcb_upload['basedir'] ) . 'ovcb-jobs';
if ( is_dir( $ovcb_dir ) ) {
	foreach ( (array) scandir( $ovcb_dir ) as $ovcb_file ) {
		if ( $ovcb_file && is_file( $ovcb_dir . '/' . $ovcb_file ) ) {
			wp_delete_file( $ovcb_dir . '/' . $ovcb_file );
		}
	}
	@rmdir( $ovcb_dir ); // phpcs:ignore
}

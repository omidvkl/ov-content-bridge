<?php
/**
 * Plugin Name:       OV Content Bridge – ایمپورت، اکسپورت و لینک‌سازی داخلی
 * Description:       خروجی گرفتن از نوشته‌ها و محصولات (JSON)، ایمپورت نوشته‌ها و محصولات ووکامرس به‌صورت پیش‌نویس، به‌روزرسانی محتوا و لینک‌سازی داخلی بر اساس شناسه یا نامک، همراه با پیش‌نمایش (Dry Run) و بازگردانی.
 * Version:           1.0.0
 * Author:            آکادمی امید وکیلی
 * Author URI:        https://omidvakili.com
 * Text Domain:       ov-content-bridge
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * WC requires at least: 6.0
 * WC tested up to:   10.2
 * License:           GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Guard against double loading (e.g. a copy of the plugin in two folders).
if ( defined( 'OVCB_VERSION' ) ) {
	return;
}

define( 'OVCB_VERSION', '1.0.0' );
define( 'OVCB_FILE', __FILE__ );
define( 'OVCB_DIR', plugin_dir_path( __FILE__ ) );
define( 'OVCB_URL', plugin_dir_url( __FILE__ ) );

// Declare compatibility with WooCommerce HPOS (the plugin never touches orders).
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', OVCB_FILE, true );
		}
	}
);

// Everything runs in wp-admin only: nothing is loaded on the front-end, so the
// plugin cannot affect the theme (WoodMart) or front-end performance.
if ( is_admin() ) {
	require_once OVCB_DIR . 'includes/class-ovcb-helpers.php';
	require_once OVCB_DIR . 'includes/class-ovcb-exporter.php';
	require_once OVCB_DIR . 'includes/class-ovcb-post-importer.php';
	require_once OVCB_DIR . 'includes/class-ovcb-product-importer.php';
	require_once OVCB_DIR . 'includes/class-ovcb-linker.php';
	require_once OVCB_DIR . 'includes/class-ovcb-jobs.php';
	require_once OVCB_DIR . 'includes/class-ovcb-admin.php';

	add_action( 'plugins_loaded', array( 'OVCB_Admin', 'init' ) );
}

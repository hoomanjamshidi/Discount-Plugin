<?php
/**
 * Plugin Name: Test host simulator (tests only)
 * Description: Makes the Docker stack behave like difficult real hosts. Mounted as a
 *              must-use plugin by docker-compose; does nothing unless the
 *              `dsd_host_sim` option is set by a test scenario.
 *
 * Knobs (all optional, JSON in option `dsd_host_sim`):
 *   boot_mb        allocate this much memory at boot (a heavy theme/page builder + 40 plugins)
 *   save_ms        burn this much CPU on every product save (SEO/cache/sync plugins hooking save_post)
 *   hide_proc      open_basedir hides /proc (cPanel/CloudLinux shared hosts)
 *   block_loopback the site's own admin-ajax loopback gets 403 (firewall / CDN challenge)
 *   no_cron        WP-Cron never fires from page loads (DISABLE_WP_CRON without a real cron)
 *   fatal_on       product IDs whose save dies with a fatal error (a buggy plugin)
 *   jalali_dates   wp_date()/date_i18n() return Persian digits (like WP-Parsidate / wp-jalali)
 *   fake_cache     a page-cache plugin like WP Rocket/LiteSpeed: counts per-post purges and full purges
 *   count_rocket   count real WP Rocket purges (per post / whole domain)
 *   regular_from_price  a buggy price-sync plugin: copies the selling price into the regular price on save
 *
 * @package SmartBulkDiscount\Tests
 */

// phpcs:ignoreFile

defined( 'ABSPATH' ) || exit;

$dsd_sim = json_decode( (string) get_option( 'dsd_host_sim', '' ), true );
if ( ! is_array( $dsd_sim ) || empty( $dsd_sim ) ) {
	return;
}

if ( ! empty( $dsd_sim['hide_proc'] ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	@ini_set( 'open_basedir', rtrim( ABSPATH, '/' ) . PATH_SEPARATOR . sys_get_temp_dir() . PATH_SEPARATOR . '/usr/local/lib/php' );
}

if ( ! empty( $dsd_sim['boot_mb'] ) ) {
	$GLOBALS['dsd_sim_ballast'] = str_repeat( 'x', (int) $dsd_sim['boot_mb'] * 1048576 );
}

if ( ! empty( $dsd_sim['no_cron'] ) && ! defined( 'DISABLE_WP_CRON' ) ) {
	define( 'DISABLE_WP_CRON', true );
}

if ( ! empty( $dsd_sim['block_loopback'] ) && isset( $_REQUEST['action'] ) && 'dsd_kick' === $_REQUEST['action'] ) {
	status_header( 403 );
	exit( 'Blocked by firewall' );
}

// Docs screenshots: a headless browser on the test stack logs in as admin (tests only).
if ( ! empty( $dsd_sim['shot_login'] ) && isset( $_GET['dsd_shot_login'] ) && hash_equals( (string) $dsd_sim['shot_login'], (string) $_GET['dsd_shot_login'] ) ) {
	add_action(
		'init',
		static function () {
			wp_set_auth_cookie( 1, false );
			$to = isset( $_GET['to'] ) ? (string) wp_unslash( $_GET['to'] ) : admin_url();
			wp_safe_redirect( $to );
			exit;
		}
	);
}

if ( ! empty( $dsd_sim['jalali_dates'] ) ) {
	$dsd_sim_fa = static function ( $date ) {
		return strtr( (string) $date, array( '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹' ) );
	};
	add_filter( 'wp_date', $dsd_sim_fa, 99 );
	add_filter( 'date_i18n', $dsd_sim_fa, 99 );
}

if ( ! empty( $dsd_sim['fake_cache'] ) ) {
	// Same function names and hooks as WP Rocket, plus LiteSpeed's purge-all action.
	if ( ! function_exists( 'rocket_clean_post' ) ) {
		function rocket_clean_post( $post_id = 0 ) {
			update_option( 'dsd_sim_post_purges', (int) get_option( 'dsd_sim_post_purges', 0 ) + 1, false );
		}
	}
	if ( ! function_exists( 'rocket_clean_domain' ) ) {
		function rocket_clean_domain() {
			update_option( 'dsd_sim_full_purges', (int) get_option( 'dsd_sim_full_purges', 0 ) + 1, false );
		}
	}
	add_action( 'clean_post_cache', 'rocket_clean_post' );
	add_action( 'save_post', 'rocket_clean_post' );
	add_action( 'woocommerce_update_product', 'rocket_clean_post' );
	add_action(
		'litespeed_purge_all',
		static function () {
			update_option( 'dsd_sim_ls_purges', (int) get_option( 'dsd_sim_ls_purges', 0 ) + 1, false );
		}
	);
}

if ( ! empty( $dsd_sim['count_rocket'] ) ) {
	add_action(
		'before_rocket_clean_post',
		static function () {
			update_option( 'dsd_sim_rocket_post', (int) get_option( 'dsd_sim_rocket_post', 0 ) + 1, false );
		}
	);
	add_action(
		'before_rocket_clean_domain',
		static function () {
			update_option( 'dsd_sim_rocket_domain', (int) get_option( 'dsd_sim_rocket_domain', 0 ) + 1, false );
		}
	);
}

$dsd_sim_on_save = static function ( $product_id ) use ( $dsd_sim ) {
	if ( ! empty( $dsd_sim['fatal_on'] ) && in_array( (int) $product_id, array_map( 'intval', (array) $dsd_sim['fatal_on'] ), true ) ) {
		dsd_sim_undefined_function_to_crash(); // Fatal error, like a broken plugin.
	}
	if ( ! empty( $dsd_sim['save_ms'] ) ) {
		$until = microtime( true ) + (int) $dsd_sim['save_ms'] / 1000;
		$x     = 0;
		while ( microtime( true ) < $until ) {
			$x += sqrt( $x + 1 ); // Real CPU work, not sleep.
		}
	}
};
add_action( 'woocommerce_update_product', $dsd_sim_on_save, 10, 1 );
add_action( 'woocommerce_update_product_variation', $dsd_sim_on_save, 10, 1 );

// A price-sync plugin with a common mistake (accounting/warehouse sync): on every product save it
// stores the CURRENT SELLING price (get_price) as the regular price. During a campaign that is our
// discounted price, which is exactly the "regular price became the discount" report. Used to prove
// that the tests and docs/diagnose-regular-price.sql catch it (the plugin itself never does this).
if ( ! empty( $dsd_sim['regular_from_price'] ) ) {
	$dsd_sim_regular_from_price = static function ( $product_id, $product = null ) {
		static $busy = false;
		if ( $busy ) {
			return;
		}
		$product = $product instanceof WC_Product ? $product : wc_get_product( $product_id );
		if ( ! $product || $product->is_type( array( 'variable', 'grouped' ) ) ) {
			return;
		}
		$busy  = true;
		$price = $product->get_price();
		if ( '' !== (string) $price ) {
			update_post_meta( $product->get_id(), '_regular_price', $price );
		}
		$busy = false;
	};
	add_action( 'woocommerce_update_product', $dsd_sim_regular_from_price, 50, 2 );
	add_action( 'woocommerce_update_product_variation', $dsd_sim_regular_from_price, 50, 2 );
}

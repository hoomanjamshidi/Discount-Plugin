<?php
// phpcs:ignoreFile

use SmartBulkDiscount\CacheCompat as C;
use PHPUnit\Framework\TestCase;

class CacheCompatTest extends TestCase {

	public function test_recognises_page_cache_plugins() {
		foreach ( array(
			'rocket_clean_post',                                  // WP Rocket function.
			array( 'WP_Rocket\Engine\Cache\Purge', 'purge_post' ), // WP Rocket class.
			array( 'LiteSpeed\Purge', 'purge_post' ),             // LiteSpeed Cache.
			'w3tc_flush_post',                                    // W3 Total Cache.
			'wp_cache_post_change',                               // WP Super Cache.
			array( 'WpFastestCache', 'singleDeleteCache' ),       // WP Fastest Cache.
			array( 'SiteGround_Optimizer\Supercacher\Supercacher', 'purge_everything' ),
			array( 'Breeze_PurgeCache', 'purge_post_on_update' ),
			array( 'CF\WordPress\Hooks', 'purgeCacheByRelevantURLs' ),
			array( 'Nginx_Helper_Purger', 'purge_post' ),
		) as $cb ) {
			$this->assertTrue( C::is_cache_callback( $cb ), json_encode( $cb ) );
		}
	}

	public function test_leaves_other_plugins_alone() {
		foreach ( array(
			'wc_delete_product_transients',
			array( 'WC_Post_Data', 'deferred_product_sync' ),
			array( 'WPSEO_Admin', 'save_post' ),
			array( 'Automattic\WooCommerce\Internal\ProductAttributesLookup\LookupDataStore', 'on_product_changed' ),
			'my_shop_price_feed_sync',
			static function () {},
		) as $cb ) {
			$this->assertFalse( C::is_cache_callback( $cb ), is_string( $cb ) ? $cb : 'callable' );
		}
	}

	public function test_closures_are_matched_by_plugin_folder() {
		$base = sys_get_temp_dir() . '/dsd-cc-' . uniqid();
		mkdir( $base . '/wp-rocket', 0777, true );
		mkdir( $base . '/my-shop-sync', 0777, true );
		file_put_contents( $base . '/wp-rocket/purge.php', '<?php return function () {};' );
		file_put_contents( $base . '/my-shop-sync/sync.php', '<?php return function () {};' );
		$this->assertTrue( C::is_cache_callback( include $base . '/wp-rocket/purge.php' ) );
		$this->assertFalse( C::is_cache_callback( include $base . '/my-shop-sync/sync.php' ) );
	}
}

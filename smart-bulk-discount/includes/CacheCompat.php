<?php
/**
 * Page-cache plugin compatibility (WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super
 * Cache, WP Fastest Cache, SiteGround Optimizer, Breeze, Hummingbird, Cache Enabler,
 * WP-Optimize, Swift Performance, Comet Cache, Nginx Helper, Cloudflare, host caches …).
 *
 * The problem: cache plugins purge on every product save (WP Rocket even on every
 * clean_post_cache, which every product read in our engine triggers). For each product they
 * delete that page plus the home page and all its category pages, and with "preload" turned
 * on they queue all of those URLs to be crawled again. A campaign writes thousands of
 * products twice (apply + restore): thousands of purges and a flood of preload requests that
 * keeps the host busy long after the campaign ended — the "503 a while later".
 *
 * What we do instead:
 *  - while OUR batch writes products, the per-post purge callbacks of known cache plugins
 *    are detached (only in that background request, restored right after);
 *  - the cache is cleared ONCE at each point where shoppers must see new prices:
 *    when the discount is fully applied, the moment the campaign ends (so a cached page
 *    never keeps selling an expired discount), and when the original prices are back.
 *
 * Can be switched off in Settings ("cache_compat").
 *
 * @package SmartBulkDiscount
 */

namespace SmartBulkDiscount;

defined( 'ABSPATH' ) || exit;

/**
 * Defers page-cache purges during batches and purges once per phase.
 */
final class CacheCompat {

	/** Hooks cache plugins listen to when a post/product changes. */
	const HOOKS = array(
		'clean_post_cache',
		'save_post',
		'save_post_product',
		'save_post_product_variation',
		'edit_post',
		'post_updated',
		'wp_insert_post',
		'transition_post_status',
		'pre_post_update',
		'updated_post_meta',
		'added_post_meta',
		'deleted_post_meta',
		'woocommerce_update_product',
		'woocommerce_update_product_variation',
		'woocommerce_product_object_updated_props',
		'woocommerce_product_set_stock',
		'woocommerce_variation_set_stock',
		'woocommerce_delete_product_transients',
	);

	/** Callback name prefixes/fragments of page-cache plugins (case-insensitive). */
	const SIGNATURES = array(
		'rocket_',              // WP Rocket functions.
		'wp_rocket\\',          // WP Rocket classes.
		'litespeed\\',          // LiteSpeed Cache.
		'litespeed_',
		'w3tc',                 // W3 Total Cache.
		'w3_',
		'wp_cache_',            // WP Super Cache.
		'wpsc_',
		'wpfastestcache',       // WP Fastest Cache.
		'wpfc_',
		'sg_cachepress',        // SiteGround Optimizer.
		'siteground_optimizer\\',
		'breeze',               // Breeze (Cloudways).
		'hummingbird\\',        // Hummingbird.
		'wp_hummingbird',
		'wphb_',
		'cache_enabler',        // Cache Enabler.
		'wpo_',                 // WP-Optimize.
		'wp_optimize',
		'swift_performance',    // Swift Performance.
		'comet_cache',          // Comet Cache.
		'zencache',
		'nginx_helper',         // Nginx Helper.
		'rt_wp_nginx',
		'cf\\wordpress\\',      // Cloudflare.
		'powered_cache',        // Powered Cache.
		'flying_press',         // FlyingPress.
		'flyingpress',
		'kinsta\\',             // Host caches.
		'wpecommon',
		'endurance_page_cache',
		'wpaas\\',
		'pagelycachepurge',
		'varnish',
	);

	/** Plugin folders of page-cache plugins (for closures, matched on the defining file). */
	const FOLDERS = array(
		'/wp-rocket/',
		'/litespeed-cache/',
		'/w3-total-cache/',
		'/wp-super-cache/',
		'/wp-fastest-cache/',
		'/sg-cachepress/',
		'/breeze/',
		'/hummingbird-performance/',
		'/wp-hummingbird/',
		'/cache-enabler/',
		'/wp-optimize/',
		'/swift-performance',
		'/comet-cache',
		'/nginx-helper/',
		'/cloudflare/',
		'/powered-cache/',
		'/flying-press/',
	);

	/**
	 * Detached callbacks: list of [hook, callback, priority, accepted_args].
	 *
	 * @var array
	 */
	private static $detached = array();

	/** @var int Nesting depth of suspend(). */
	private static $depth = 0;

	/**
	 * Is the feature on?
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) Settings::get( 'cache_compat' );
	}

	/**
	 * Register purge-on-transition.
	 */
	public static function register() {
		add_action( 'dsd_campaign_transition', array( __CLASS__, 'on_transition' ), 20, 3 );
	}

	/**
	 * Detach cache plugins' per-post purge callbacks (our background batch only).
	 */
	public static function suspend() {
		if ( ! self::enabled() ) {
			return;
		}
		self::$depth++;
		if ( self::$depth > 1 ) {
			return;
		}
		global $wp_filter;
		foreach ( self::HOOKS as $hook ) {
			if ( empty( $wp_filter[ $hook ] ) || ! is_object( $wp_filter[ $hook ] ) || empty( $wp_filter[ $hook ]->callbacks ) ) {
				continue;
			}
			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $cb ) {
					if ( ! isset( $cb['function'] ) || ! self::is_cache_callback( $cb['function'] ) ) {
						continue;
					}
					self::$detached[] = array( $hook, $cb['function'], (int) $priority, isset( $cb['accepted_args'] ) ? (int) $cb['accepted_args'] : 1 );
				}
			}
		}
		foreach ( self::$detached as $d ) {
			remove_filter( $d[0], $d[1], $d[2] );
		}
	}

	/**
	 * Put the detached callbacks back.
	 */
	public static function resume() {
		if ( self::$depth <= 0 ) {
			return;
		}
		self::$depth--;
		if ( self::$depth > 0 ) {
			return;
		}
		foreach ( self::$detached as $d ) {
			add_filter( $d[0], $d[1], $d[2], $d[3] );
		}
		self::$detached = array();
	}

	/**
	 * Number of callbacks currently detached (diagnostics/tests).
	 *
	 * @return int
	 */
	public static function detached_count() {
		return count( self::$detached );
	}

	/**
	 * Does a callback belong to a page-cache plugin?
	 *
	 * @param mixed $callback Callback.
	 * @return bool
	 */
	public static function is_cache_callback( $callback ) {
		$name = self::callback_name( $callback );
		if ( '' === $name ) {
			return false;
		}
		$name = strtolower( $name );
		if ( 0 === strpos( $name, 'file:' ) ) {
			// A closure: judge it by the plugin folder of its file (and by its class below).
			foreach ( self::FOLDERS as $folder ) {
				if ( false !== strpos( $name, $folder ) ) {
					return true;
				}
			}
			$name = (string) substr( $name, (int) strpos( $name, '|' ) + 1 );
		}
		foreach ( self::SIGNATURES as $sig ) {
			if ( false !== strpos( $name, $sig ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * "function", "Class::method" or "Class::__invoke".
	 *
	 * @param mixed $callback Callback.
	 * @return string
	 */
	private static function callback_name( $callback ) {
		if ( is_string( $callback ) ) {
			return $callback;
		}
		if ( is_array( $callback ) && 2 === count( $callback ) ) {
			$class = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
			return $class . '::' . (string) $callback[1];
		}
		if ( $callback instanceof \Closure ) {
			try {
				$ref   = new \ReflectionFunction( $callback );
				$scope = $ref->getClosureScopeClass();
				return 'file:' . str_replace( DIRECTORY_SEPARATOR, '/', (string) $ref->getFileName() ) . '|' . ( $scope ? $scope->getName() . '::{closure}' : '' );
			} catch ( \ReflectionException $e ) {
				return '';
			}
		}
		if ( is_object( $callback ) ) {
			return get_class( $callback ) . '::__invoke';
		}
		return '';
	}

	/**
	 * Purge points.
	 *
	 * @param int    $id   Campaign.
	 * @param string $from From status.
	 * @param string $to   To status.
	 */
	public static function on_transition( $id, $from, $to ) {
		if ( ! self::enabled() ) {
			return;
		}
		$reason = '';
		if ( Campaign::ACTIVE === $to && Campaign::VERIFYING === $from ) {
			$reason = 'applied';
		} elseif ( Campaign::REVERTING === $to ) {
			$reason = 'ending';
		} elseif ( Campaign::VERIFYING_REVERT === $from && in_array( $to, array( Campaign::ENDED, Campaign::SCHEDULED ), true ) ) {
			$reason = 'restored';
		}
		if ( '' !== $reason ) {
			self::purge_all( $reason, (int) $id );
		}
	}

	/**
	 * Clear every known page cache once.
	 *
	 * @param string $reason  applied|ending|restored|manual.
	 * @param int    $campaign Campaign ID.
	 * @return string[] Caches that were cleared.
	 */
	public static function purge_all( $reason, $campaign = 0 ) {
		$done = array();
		$try  = static function ( $name, $fn ) use ( &$done ) {
			try {
				$fn();
				$done[] = $name;
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
				// One broken cache plugin must not stop the others (or our batch).
			}
		};

		if ( function_exists( 'rocket_clean_domain' ) ) {
			$try(
				'WP Rocket',
				static function () {
					rocket_clean_domain();
					if ( function_exists( 'rocket_clean_minify' ) ) {
						rocket_clean_minify();
					}
				}
			);
		}
		if ( has_action( 'litespeed_purge_all' ) || defined( 'LSCWP_V' ) ) {
			$try(
				'LiteSpeed Cache',
				static function () {
					do_action( 'litespeed_purge_all', 'Smart Bulk Discount' );
				}
			);
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			$try( 'W3 Total Cache', 'w3tc_flush_all' );
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			$try(
				'WP Super Cache',
				static function () {
					wp_cache_clear_cache();
				}
			);
		}
		if ( isset( $GLOBALS['wp_fastest_cache'] ) && is_object( $GLOBALS['wp_fastest_cache'] ) && method_exists( $GLOBALS['wp_fastest_cache'], 'deleteCache' ) ) {
			$try(
				'WP Fastest Cache',
				static function () {
					$GLOBALS['wp_fastest_cache']->deleteCache( true );
				}
			);
		}
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			$try(
				'SiteGround Optimizer',
				static function () {
					sg_cachepress_purge_cache();
				}
			);
		}
		if ( has_action( 'breeze_clear_all_cache' ) ) {
			$try(
				'Breeze',
				static function () {
					do_action( 'breeze_clear_all_cache' );
				}
			);
		}
		if ( has_action( 'wphb_clear_page_cache' ) ) {
			$try(
				'Hummingbird',
				static function () {
					do_action( 'wphb_clear_page_cache' );
				}
			);
		}
		if ( has_action( 'cache_enabler_clear_complete_cache' ) ) {
			$try(
				'Cache Enabler',
				static function () {
					do_action( 'cache_enabler_clear_complete_cache' );
				}
			);
		}
		if ( function_exists( 'WP_Optimize' ) ) {
			$try(
				'WP-Optimize',
				static function () {
					$wpo = WP_Optimize();
					if ( is_object( $wpo ) && method_exists( $wpo, 'get_page_cache' ) ) {
						$cache = $wpo->get_page_cache();
						if ( is_object( $cache ) && method_exists( $cache, 'purge' ) ) {
							$cache->purge();
						}
					}
				}
			);
		}
		if ( class_exists( '\Swift_Performance_Cache' ) && method_exists( '\Swift_Performance_Cache', 'clear_all_cache' ) ) {
			$try(
				'Swift Performance',
				static function () {
					\Swift_Performance_Cache::clear_all_cache();
				}
			);
		}
		if ( class_exists( '\comet_cache' ) && method_exists( '\comet_cache', 'clear' ) ) {
			$try(
				'Comet Cache',
				static function () {
					\comet_cache::clear();
				}
			);
		}
		if ( has_action( 'rt_nginx_helper_purge_all' ) ) {
			$try(
				'Nginx Helper',
				static function () {
					do_action( 'rt_nginx_helper_purge_all' );
				}
			);
		}

		/**
		 * Let hosts/other caches clear themselves (e.g. a CDN).
		 *
		 * @param string $reason   applied|ending|restored|manual.
		 * @param int    $campaign Campaign ID.
		 */
		do_action( 'dsd_purge_page_cache', $reason, $campaign );

		update_option(
			'dsd_last_purge',
			array(
				't'        => time(),
				'reason'   => $reason,
				'campaign' => (int) $campaign,
				'caches'   => $done,
			),
			false
		);
		return $done;
	}

	/**
	 * Page-cache plugins found on this site (for the System page).
	 *
	 * @return string[]
	 */
	public static function detected() {
		$found = array();
		$map   = array(
			'WP Rocket'            => function_exists( 'rocket_clean_domain' ),
			'LiteSpeed Cache'      => defined( 'LSCWP_V' ) || has_action( 'litespeed_purge_all' ),
			'W3 Total Cache'       => function_exists( 'w3tc_flush_all' ),
			'WP Super Cache'       => function_exists( 'wp_cache_clear_cache' ),
			'WP Fastest Cache'     => isset( $GLOBALS['wp_fastest_cache'] ),
			'SiteGround Optimizer' => function_exists( 'sg_cachepress_purge_cache' ),
			'Breeze'               => has_action( 'breeze_clear_all_cache' ),
			'Hummingbird'          => has_action( 'wphb_clear_page_cache' ),
			'Cache Enabler'        => has_action( 'cache_enabler_clear_complete_cache' ),
			'WP-Optimize'          => function_exists( 'WP_Optimize' ),
			'Swift Performance'    => class_exists( '\Swift_Performance_Cache' ),
			'Comet Cache'          => class_exists( '\comet_cache' ),
			'Nginx Helper'         => has_action( 'rt_nginx_helper_purge_all' ),
		);
		foreach ( $map as $name => $yes ) {
			if ( $yes ) {
				$found[] = $name;
			}
		}
		return $found;
	}
}

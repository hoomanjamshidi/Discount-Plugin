<?php
/**
 * Runtime safety net.
 *
 * Batches take minutes; a campaign's end time is a second. Between "end time reached"
 * and "last product reverted", and for any product whose revert failed, this guard
 * makes the storefront and cart show the regular price for products still carrying
 * our lock — so a discount is never sold after it should have ended.
 *
 * Cost when nothing is running: one in-memory array check per price read.
 *
 * @package SmartBulkDiscount
 */

namespace SmartBulkDiscount;

defined( 'ABSPATH' ) || exit;

/**
 * Neutralises our sale price outside a campaign's legitimate window.
 */
final class PriceGuard {

	const OPTION = 'dsd_on_index';

	/**
	 * Campaigns whose price may legitimately be shown: id => end timestamp (0 = open-ended).
	 *
	 * @var array|null
	 */
	private static $on = null;

	/**
	 * Hook filters.
	 */
	public static function register() {
		foreach ( array( 'woocommerce_product_get_price', 'woocommerce_product_variation_get_price' ) as $hook ) {
			add_filter( $hook, array( __CLASS__, 'filter_price' ), 99, 2 );
		}
		foreach ( array( 'woocommerce_product_get_sale_price', 'woocommerce_product_variation_get_sale_price' ) as $hook ) {
			add_filter( $hook, array( __CLASS__, 'filter_sale_price' ), 99, 2 );
		}
		add_filter( 'woocommerce_product_is_on_sale', array( __CLASS__, 'filter_on_sale' ), 99, 2 );
		add_filter( 'woocommerce_variation_prices_price', array( __CLASS__, 'filter_variation_price' ), 99, 2 );
		add_filter( 'woocommerce_variation_prices_sale_price', array( __CLASS__, 'filter_variation_sale_price' ), 99, 2 );
		add_filter( 'woocommerce_get_variation_prices_hash', array( __CLASS__, 'filter_hash' ), 99 );
		add_action( 'dsd_campaign_transition', array( __CLASS__, 'rebuild' ) );
	}

	/**
	 * Rebuild the (autoloaded, tiny) index of "on" campaigns.
	 */
	public static function rebuild() {
		$repo = new Repository();
		$on   = array();
		foreach ( $repo->by_status( array( Campaign::APPLYING, Campaign::VERIFYING, Campaign::ACTIVE, Campaign::PAUSED ) ) as $c ) {
			if ( Campaign::PAUSED === $c->status && ! in_array( $c->paused_status, array( Campaign::APPLYING, Campaign::VERIFYING, Campaign::ACTIVE ), true ) ) {
				continue;
			}
			$on[ $c->id ] = (int) $c->ends_at;
		}
		ksort( $on );
		$before = get_option( self::OPTION, array() );
		$before = is_array( $before ) ? $before : array();
		ksort( $before );
		self::$on = $on;
		if ( $before === $on ) {
			// Nothing a shopper sees changed. Bumping the version would throw away the cached
			// price ranges of EVERY variable product in the store (heavy to rebuild on busy
			// shops), so only do it when the set of visible discounts really changes.
			return;
		}
		update_option( self::OPTION, $on, true );
		update_option( 'dsd_on_version', time(), true );
	}

	/**
	 * May this product show our sale price right now?
	 *
	 * @param \WC_Product $product Product.
	 * @return bool True = leave prices alone.
	 */
	public static function allowed( $product ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_meta' ) ) {
			return true;
		}
		$cid = (int) $product->get_meta( '_dsd_campaign', true, 'edit' );
		if ( $cid <= 0 ) {
			return true;
		}
		if ( null === self::$on ) {
			$saved     = get_option( self::OPTION, array() );
			self::$on = is_array( $saved ) ? $saved : array();
		}
		if ( ! isset( self::$on[ $cid ] ) ) {
			return false;
		}
		$end = (int) self::$on[ $cid ];
		return 0 === $end || $end > time();
	}

	/**
	 * Active price.
	 *
	 * @param mixed       $price   Price.
	 * @param \WC_Product $product Product.
	 * @return mixed
	 */
	public static function filter_price( $price, $product ) {
		return self::allowed( $product ) ? $price : $product->get_regular_price( 'edit' );
	}

	/**
	 * Sale price.
	 *
	 * @param mixed       $price   Price.
	 * @param \WC_Product $product Product.
	 * @return mixed
	 */
	public static function filter_sale_price( $price, $product ) {
		return self::allowed( $product ) ? $price : '';
	}

	/**
	 * On-sale flag.
	 *
	 * @param bool        $on_sale Flag.
	 * @param \WC_Product $product Product.
	 * @return bool
	 */
	public static function filter_on_sale( $on_sale, $product ) {
		return self::allowed( $product ) ? $on_sale : false;
	}

	/**
	 * Variation price inside the variable price-range cache.
	 *
	 * @param mixed       $price     Price.
	 * @param \WC_Product $variation Variation.
	 * @return mixed
	 */
	public static function filter_variation_price( $price, $variation ) {
		return self::allowed( $variation ) ? $price : $variation->get_regular_price( 'edit' );
	}

	/**
	 * Variation sale price inside the range cache.
	 *
	 * @param mixed       $price     Price.
	 * @param \WC_Product $variation Variation.
	 * @return mixed
	 */
	public static function filter_variation_sale_price( $price, $variation ) {
		return self::allowed( $variation ) ? $price : $variation->get_regular_price( 'edit' );
	}

	/**
	 * Invalidate cached variable price ranges whenever the "on" set changes.
	 *
	 * @param array $hash Hash parts.
	 * @return array
	 */
	public static function filter_hash( $hash ) {
		$hash[] = 'dsd' . (int) get_option( 'dsd_on_version', 0 );
		// Ranges also expire at the next campaign end.
		$on = get_option( self::OPTION, array() );
		if ( is_array( $on ) ) {
			$now = time();
			foreach ( $on as $end ) {
				if ( $end > 0 && $end <= $now ) {
					$hash[] = 'ended' . $end;
				}
			}
		}
		return $hash;
	}

	/**
	 * Reset in-memory state (tests).
	 */
	public static function flush() {
		self::$on = null;
	}
}

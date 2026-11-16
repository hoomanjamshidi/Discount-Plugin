<?php
/**
 * Deterministic test catalog.
 *
 *   wp eval-file /tests/integration/seed.php 3000
 *
 * Products: ~87% simple, ~13% variable (3 variations each), spread over 12 parent
 * categories × 2 children. Edge cases on purpose:
 *  - ~10% out of stock, ~2% on backorder
 *  - ~8% already have their own sale price, ~2% with a scheduled native sale
 *  - 15 drafts and 10 without a price (must never be touched)
 *
 * @package SmartBulkDiscount\Tests
 */

// phpcs:ignoreFile

$total = isset( $args[0] ) ? (int) $args[0] : 3000;
mt_srand( 20261116 );

wp_defer_term_counting( true );
wp_suspend_cache_invalidation( true );

$cats = array();
$names = array( 'کتاب', 'لوازم‌التحریر', 'بازی فکری', 'کتاب کودک', 'رمان', 'تاریخ', 'روانشناسی', 'هنر', 'آشپزی', 'علمی', 'مجله', 'صوتی' );
foreach ( $names as $i => $name ) {
	$parent = term_exists( $name, 'product_cat' );
	if ( ! $parent ) {
		$parent = wp_insert_term( $name, 'product_cat', array( 'slug' => 'cat-' . $i ) );
	}
	$pid           = (int) ( is_array( $parent ) ? $parent['term_id'] : $parent );
	$cats[ $pid ]  = array();
	foreach ( array( 'الف', 'ب' ) as $j => $sub ) {
		$child = term_exists( "$name $sub", 'product_cat' );
		if ( ! $child ) {
			$child = wp_insert_term( "$name $sub", 'product_cat', array( 'parent' => $pid, 'slug' => "cat-$i-$j" ) );
		}
		$cats[ $pid ][] = (int) ( is_array( $child ) ? $child['term_id'] : $child );
	}
}
$parents = array_keys( $cats );

$existing = (int) wp_count_posts( 'product' )->publish;
$start    = microtime( true );
$made     = 0;

for ( $n = $existing; $n < $total; $n++ ) {
	$parent_cat = $parents[ $n % count( $parents ) ];
	$cat_ids    = array( $parent_cat );
	$slot       = intdiv( $n, count( $parents ) ) % 3; // Independent of the parent index.
	if ( $slot > 0 ) {
		$cat_ids = array( $cats[ $parent_cat ][ $slot - 1 ] ); // In a child category only.
	}
	$regular = 10000 * mt_rand( 5, 500 ); // 50,000 .. 5,000,000 Toman.
	$roll    = mt_rand( 1, 100 );

	if ( 0 === $n % 8 ) {
		$p = new WC_Product_Variable();
		$p->set_name( "محصول متغیر {$n}" );
		$p->set_sku( "VAR-{$n}" );
		$p->set_category_ids( $cat_ids );
		$p->set_status( 'publish' );
		$attr = new WC_Product_Attribute();
		$attr->set_name( 'جلد' );
		$attr->set_options( array( 'شومیز', 'گالینگور', 'دیجیتال' ) );
		$attr->set_visible( true );
		$attr->set_variation( true );
		$p->set_attributes( array( $attr ) );
		$pid = $p->save();
		foreach ( array( 'شومیز', 'گالینگور', 'دیجیتال' ) as $k => $opt ) {
			$v = new WC_Product_Variation();
			$v->set_parent_id( $pid );
			$v->set_attributes( array( 'جلد' => $opt ) );
			$v->set_regular_price( (string) ( $regular + $k * 50000 ) );
			$v->set_sku( "VAR-{$n}-{$k}" );
			if ( 1 === $k && $roll <= 15 ) {
				$v->set_stock_status( 'outofstock' );
			}
			if ( 2 === $k && $roll > 90 ) {
				$v->set_sale_price( (string) ( $regular + $k * 50000 - 20000 ) );
			}
			$v->save();
		}
		WC_Product_Variable::sync( $pid );
	} else {
		$p = new WC_Product_Simple();
		$p->set_name( "محصول ساده {$n}" );
		$p->set_sku( "SKU-{$n}" );
		$p->set_category_ids( $cat_ids );
		$p->set_status( $n < 15 ? 'draft' : 'publish' );
		if ( $n >= 15 && $n < 25 ) {
			$p->set_regular_price( '' ); // No price.
		} else {
			$p->set_regular_price( (string) $regular );
		}
		if ( $roll <= 10 ) {
			$p->set_stock_status( 'outofstock' );
		} elseif ( $roll <= 12 ) {
			$p->set_stock_status( 'onbackorder' );
		} elseif ( $roll <= 20 ) {
			$p->set_sale_price( (string) ( $regular - 10000 * mt_rand( 1, 4 ) ) );
		} elseif ( $roll <= 22 ) {
			$p->set_sale_price( (string) ( $regular * 0.5 ) );
			$p->set_date_on_sale_from( time() + 30 * DAY_IN_SECONDS );
			$p->set_date_on_sale_to( time() + 40 * DAY_IN_SECONDS );
		}
		$p->save();
	}
	$made++;
	if ( 0 === $made % 250 ) {
		wp_cache_flush();
		WP_CLI::log( sprintf( '%d products (%.1fs)', $existing + $made, microtime( true ) - $start ) );
	}
}

wp_suspend_cache_invalidation( false );
wp_defer_term_counting( false );
// Invalidation was suspended: rebuild the cached category hierarchy and counts.
delete_option( 'product_cat_children' );
_get_term_hierarchy( 'product_cat' );
wc_recount_all_terms();
wc_delete_product_transients();
WP_CLI::success( sprintf( 'Catalog has %d products (+%d) in %.1fs', $existing + $made, $made, microtime( true ) - $start ) );

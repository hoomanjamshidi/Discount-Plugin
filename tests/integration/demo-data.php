<?php
/**
 * Demo campaigns for screenshots / manual QA (run on a fresh snapshot):
 *
 *   wp eval-file /tests/integration/demo-data.php <step>
 *
 * Steps: create   → creates 4 campaigns, prints their IDs as JSON.
 *
 * @package SmartBulkDiscount\Tests
 */

// phpcs:ignoreFile

use SmartBulkDiscount\Plugin;

$svc  = Plugin::instance()->service;
$step = isset( $args[0] ) ? $args[0] : 'create';
$tz   = wp_timezone();

if ( 'create' === $step ) {
	$next_thu = new DateTimeImmutable( 'next thursday 18:00', $tz );
	$out      = array();

	// 1) Store-wide autumn sale: now → +5 days at 23:59 (will be applied by the worker).
	$end   = ( new DateTimeImmutable( '+5 days', $tz ) )->setTime( 23, 59 );
	$c     = $svc->create( array( 'name' => 'حراج پاییزه', 'discount_type' => 'percent', 'discount_value' => 20, 'target_type' => 'all_in_stock', 'options' => array( 'round_step' => 1000, 'sale_policy' => 'best' ), 'ends_at' => $end->format( 'Y-m-d H:i' ) ) );
	$out['autumn'] = $c->id;

	// 2) Weekly children's-books Thursday-evening → Friday-night sale.
	$cat = get_term_by( 'slug', 'cat-3', 'product_cat' ); // «کتاب کودک» in the seed.
	$c   = $svc->create(
		array(
			'name'           => 'جمعه‌های کتاب کودک',
			'discount_type'  => 'percent',
			'discount_value' => 15,
			'target_type'    => 'categories',
			'target'         => array( 'category_ids' => array( $cat ? (int) $cat->term_id : 0 ) ),
			'starts_at'      => $next_thu->format( 'Y-m-d H:i' ),
			'ends_at'        => $next_thu->modify( '+1 day' )->setTime( 23, 59 )->format( 'Y-m-d H:i' ),
			'repeat_mode'    => 'weekly',
			'start'          => true,
		)
	);
	$out['weekly'] = is_wp_error( $c ) ? $c->get_error_message() : $c->id;

	// 3) Book-fair special on 12 hand-picked products (will be run and ended).
	global $wpdb;
	$ids = $wpdb->get_col( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_stock_status' AND s.meta_value = 'instock' JOIN {$wpdb->postmeta} r ON r.post_id = p.ID AND r.meta_key = '_regular_price' AND r.meta_value + 0 > 0 WHERE p.post_type = 'product' AND p.post_status = 'publish' ORDER BY p.ID DESC LIMIT 12" );
	$c   = $svc->create( array( 'name' => 'تخفیف ویژهٔ نمایشگاه کتاب', 'discount_type' => 'fixed', 'discount_value' => 50000, 'target_type' => 'products', 'target' => array( 'product_ids' => array_map( 'intval', $ids ) ), 'start' => true ) );
	$out['fair'] = $c->id;

	// 4) A draft for winter.
	$c = $svc->create( array( 'name' => 'حراج زمستانه (پیش‌نویس)', 'discount_type' => 'percent', 'discount_value' => 30, 'target_type' => 'all_in_stock' ) );
	$out['draft'] = $c->id;

	WP_CLI::line( wp_json_encode( $out ) );
}

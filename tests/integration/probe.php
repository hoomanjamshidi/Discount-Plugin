<?php
/**
 * Integration probe — run inside WordPress via WP-CLI:
 *
 *   wp eval-file /tests/integration/probe.php <command> [args...]
 *
 * Commands print one JSON line. Expectations are computed INDEPENDENTLY of the plugin
 * (own eligibility + own price formula) so a plugin bug cannot hide itself.
 *
 *   snapshot <file>                 Save every product's price/stock/sale meta.
 *   create <json>                   Create a campaign through the service; prints id.
 *   cmd <command> <id>              start|pause|resume|stop|retry|duplicate|delete.
 *   status <id>                     Campaign status + item counts.
 *   check_applied <id> <file>       Verify every product against the snapshot.
 *   check_restored <file>           Verify all prices equal the snapshot, no locks left.
 *   view_price <product_id>         Storefront (view-context) price + on_sale.
 *   set_sale <product_id> <price>   Simulate an admin editing a product.
 *   pick <n> [type]                 Pick n published product IDs (simple|variable).
 *   pick_own_sale <n>               In-stock simple products that already have their own sale price.
 *   full_edit|quick_edit <id>       Admin presses "Update" on the edit screen / in Quick Edit, unchanged.
 *   rest_roundtrip <id>             An external app reads the product via /wc/v3 and writes the prices back.
 *   price_html <id,id>              What the storefront shows (price HTML as text).
 *   permalinks <id,id>              Shop links.
 *   diagnose <sql file>             Run docs/diagnose-regular-price.sql.
 *
 * @package SmartBulkDiscount\Tests
 */

// phpcs:ignoreFile

use SmartBulkDiscount\Plugin;
use SmartBulkDiscount\PriceGuard;

$cmd = isset( $args[0] ) ? $args[0] : '';

function dsd_out( $data ) {
	WP_CLI::line( wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) );
}

/** Product straight from the database (no stale object/product cache). */
function dsd_fresh( $id ) {
	clean_post_cache( $id );
	wp_cache_delete( $id, 'post_meta' );
	return wc_get_product( $id );
}

/**
 * Read a snapshot written by `snapshot`. A missing or empty snapshot is a hard error: comparing
 * against nothing would make every check pass silently.
 */
function dsd_load_snapshot( $file ) {
	$raw  = is_readable( $file ) ? file_get_contents( $file ) : false;
	$rows = false === $raw ? null : json_decode( $raw, true );
	if ( ! is_array( $rows ) || count( $rows ) < 1 ) {
		WP_CLI::error( "Snapshot {$file} is missing or empty." );
	}
	return $rows;
}

function dsd_all_rows() {
	global $wpdb;
	$keys = "'_regular_price','_sale_price','_price','_sale_price_dates_from','_sale_price_dates_to','_stock_status','_dsd_campaign'";
	$rows = $wpdb->get_results(
		"SELECT p.ID, p.post_type, p.post_status, p.post_parent, m.meta_key, m.meta_value
		 FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key IN ({$keys})
		 WHERE p.post_type IN ('product','product_variation')"
	);
	$out = array();
	foreach ( $rows as $r ) {
		$id = (int) $r->ID;
		if ( ! isset( $out[ $id ] ) ) {
			$out[ $id ] = array(
				'type'   => $r->post_type,
				'status' => $r->post_status,
				'parent' => (int) $r->post_parent,
				'meta'   => array(),
			);
		}
		if ( null !== $r->meta_key ) {
			$out[ $id ]['meta'][ $r->meta_key ] = (string) $r->meta_value;
		}
	}
	// Product type (simple/variable/...) for parents.
	$types = $wpdb->get_results(
		"SELECT tr.object_id, t.slug FROM {$wpdb->term_relationships} tr
		 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_type'
		 JOIN {$wpdb->terms} t ON t.term_id = tt.term_id"
	);
	foreach ( $types as $t ) {
		if ( isset( $out[ (int) $t->object_id ] ) ) {
			$out[ (int) $t->object_id ]['ptype'] = $t->slug;
		}
	}
	$cats = $wpdb->get_results(
		"SELECT tr.object_id, tt.term_id FROM {$wpdb->term_relationships} tr
		 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'"
	);
	foreach ( $cats as $c ) {
		if ( isset( $out[ (int) $c->object_id ] ) ) {
			$out[ (int) $c->object_id ]['cats'][] = (int) $c->term_id;
		}
	}
	return $out;
}

/** Independent price formula. */
function dsd_expected_price( $regular, $type, $value, $step ) {
	$regular = (float) $regular;
	$cents   = (int) round( $regular * 100 );
	if ( 'percent' === $type ) {
		$new_cents = intdiv( $cents * (int) round( ( 100 - $value ) * 100 ), 10000 );
	} else {
		$new_cents = $cents - (int) round( $value * 100 );
	}
	$new = $new_cents / 100;
	if ( $step > 0 ) {
		$new = floor( $new / $step ) * $step;
	} else {
		$new = floor( $new ); // shop uses 0 decimals.
	}
	if ( $new <= 0 || $new >= $regular ) {
		return null;
	}
	return $new;
}

/** Independent descendant lookup (recursive SQL walk, not the plugin's code, not WP's cached hierarchy). */
function dsd_descendants( $term_id ) {
	global $wpdb;
	$out  = array();
	$kids = $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'product_cat' AND parent = %d", $term_id ) );
	foreach ( $kids as $k ) {
		$out[] = (int) $k;
		$out   = array_merge( $out, dsd_descendants( (int) $k ) );
	}
	return $out;
}

function dsd_eligible( $id, $row, $all, $c ) {
	$owner = 'product_variation' === $row['type'] ? $row['parent'] : $id;
	if ( 'publish' !== $row['status'] ) {
		return 'not_published';
	}
	if ( 'product_variation' === $row['type'] ) {
		if ( ! isset( $all[ $row['parent'] ] ) || 'publish' !== $all[ $row['parent'] ]['status'] ) {
			return 'parent_not_published';
		}
	} elseif ( isset( $row['ptype'] ) && in_array( $row['ptype'], array( 'variable', 'grouped' ), true ) ) {
		return 'parent_type';
	}
	$regular = isset( $row['meta']['_regular_price'] ) ? $row['meta']['_regular_price'] : '';
	if ( '' === $regular || (float) $regular <= 0 ) {
		return 'no_price';
	}
	$stock = isset( $row['meta']['_stock_status'] ) ? $row['meta']['_stock_status'] : '';
	if ( ( 'all_in_stock' === $c['target_type'] || ! empty( $c['options']['only_in_stock'] ) ) && 'instock' !== $stock ) {
		return 'stock';
	}
	$t = $c['target'];
	if ( in_array( $id, $t['exclude_product_ids'], true ) || in_array( $owner, $t['exclude_product_ids'], true ) ) {
		return 'excluded';
	}
	$owner_cats = isset( $all[ $owner ]['cats'] ) ? $all[ $owner ]['cats'] : array();
	if ( ! empty( $t['exclude_category_ids'] ) ) {
		$ex = $t['exclude_category_ids'];
		foreach ( $t['exclude_category_ids'] as $x ) {
			$ex = array_merge( $ex, dsd_descendants( $x ) );
		}
		if ( array_intersect( $owner_cats, $ex ) ) {
			return 'excluded_cat';
		}
	}
	if ( 'products' === $c['target_type'] ) {
		if ( ! in_array( $id, $t['product_ids'], true ) && ! in_array( $owner, $t['product_ids'], true ) ) {
			return 'not_targeted';
		}
	} elseif ( 'categories' === $c['target_type'] ) {
		$want = $t['category_ids'];
		if ( ! empty( $c['options']['include_children'] ) ) {
			foreach ( $t['category_ids'] as $x ) {
				$want = array_merge( $want, dsd_descendants( $x ) );
			}
		}
		if ( ! array_intersect( $owner_cats, $want ) ) {
			return 'not_targeted';
		}
	}
	return '';
}

$plugin = Plugin::instance();

switch ( $cmd ) {
	case 'snapshot':
		$rows = dsd_all_rows();
		if ( false === @file_put_contents( $args[1], wp_json_encode( $rows ) ) || filesize( $args[1] ) < 10 ) { // phpcs:ignore
			WP_CLI::error( "Could not write snapshot {$args[1]} (folder not writable by the web user?)." );
		}
		dsd_out( array( 'saved' => count( $rows ) ) );
		break;

	case 'create':
		$in = json_decode( $args[1], true );
		$c  = $plugin->service->create( $in );
		dsd_out( is_wp_error( $c ) ? array( 'error' => $c->get_error_code() ) : array( 'id' => $c->id, 'status' => $c->status ) );
		break;

	case 'cmd':
		$r = call_user_func( array( $plugin->service, $args[1] ), (int) $args[2] );
		dsd_out( is_wp_error( $r ) ? array( 'error' => $r->get_error_code() ) : array( 'ok' => true, 'status' => $r instanceof \SmartBulkDiscount\Campaign ? $r->status : null ) );
		break;

	case 'status':
		$c = $plugin->repo->find( (int) $args[1] );
		dsd_out(
			array(
				'status'    => $c ? $c->status : null,
				'counts'    => $c ? $plugin->repo->counts( $c->id ) : null,
				'starts_at' => $c ? $c->starts_at : null,
				'ends_at'   => $c ? $c->ends_at : null,
				'run_count' => $c ? $c->run_count : null,
				'log'       => $c ? wp_list_pluck( $c->log, 'code' ) : null,
			)
		);
		break;

	case 'check_applied':
		$c = $plugin->repo->find( (int) $args[1] );
		if ( ! $c ) {
			dsd_out( array( 'error' => 'not_found' ) );
			break;
		}
		$def    = $c->to_api();
		$before = dsd_load_snapshot( $args[2] );
		$now    = dsd_all_rows();
		$report = array(
			'eligible'          => 0,
			'applied_ok'        => 0,
			'kept_better_sale'  => 0,
			'wrong'             => 0,
			'untouched_ok'      => 0,
			'untouched_changed' => 0,
			'errors'            => array(),
			'reasons'           => array(),
		);
		$step   = (int) $def['options']['round_step'];
		foreach ( $before as $id => $old ) {
			$id     = (int) $id;
			$cur    = $now[ $id ];
			$reason = dsd_eligible( $id, $old, $before, $def );
			if ( '' !== $reason ) {
				$report['reasons'][ $reason ] = ( isset( $report['reasons'][ $reason ] ) ? $report['reasons'][ $reason ] : 0 ) + 1;
				$same = true;
				// A variable parent's _price is WooCommerce's synced min-of-variations: it must move.
				$keys = 'parent_type' === $reason ? array( '_regular_price', '_sale_price' ) : array( '_regular_price', '_sale_price', '_price' );
				foreach ( $keys as $k ) {
					$a = isset( $old['meta'][ $k ] ) ? $old['meta'][ $k ] : null;
					$b = isset( $cur['meta'][ $k ] ) ? $cur['meta'][ $k ] : null;
					if ( (string) $a !== (string) $b ) {
						$same = false;
					}
				}
				if ( isset( $cur['meta']['_dsd_campaign'] ) ) {
					$same = false;
				}
				if ( $same ) {
					$report['untouched_ok']++;
				} else {
					$report['untouched_changed']++;
					if ( count( $report['errors'] ) < 10 ) {
						$report['errors'][] = array( 'id' => $id, 'why' => 'changed_but_ineligible:' . $reason, 'before' => $old['meta'], 'after' => $cur['meta'] );
					}
				}
				continue;
			}
			$report['eligible']++;
			$regular  = (float) $old['meta']['_regular_price'];
			$expected = dsd_expected_price( $regular, $def['discount_type'], (float) $def['discount_value'], $step );
			$old_sale = isset( $old['meta']['_sale_price'] ) && '' !== $old['meta']['_sale_price'] ? (float) $old['meta']['_sale_price'] : null;
			$policy   = $def['options']['sale_policy'];
			$keep     = false;
			if ( null !== $old_sale && $old_sale > 0 && $old_sale < $regular ) {
				if ( 'keep' === $policy || ( 'best' === $policy && null !== $expected && $old_sale <= $expected ) ) {
					$keep = true;
				}
			}
			$cur_sale  = isset( $cur['meta']['_sale_price'] ) ? $cur['meta']['_sale_price'] : '';
			$cur_price = isset( $cur['meta']['_price'] ) ? $cur['meta']['_price'] : '';
			$lock      = isset( $cur['meta']['_dsd_campaign'] ) ? (int) $cur['meta']['_dsd_campaign'] : 0;
			if ( $keep || null === $expected ) {
				$ok = ( (string) ( isset( $old['meta']['_sale_price'] ) ? $old['meta']['_sale_price'] : '' ) === (string) $cur_sale ) && 0 === $lock;
				if ( $ok ) {
					$report['kept_better_sale']++;
				}
			} else {
				$ok = abs( (float) $cur_sale - $expected ) < 0.001 && abs( (float) $cur_price - $expected ) < 0.001 && $lock === $c->id
					&& empty( $cur['meta']['_sale_price_dates_from'] ) && empty( $cur['meta']['_sale_price_dates_to'] );
				if ( $ok ) {
					$report['applied_ok']++;
				}
			}
			if ( ! $ok ) {
				$report['wrong']++;
				if ( count( $report['errors'] ) < 10 ) {
					$report['errors'][] = array( 'id' => $id, 'expected' => $keep ? 'keep' : $expected, 'before' => $old['meta'], 'after' => $cur['meta'] );
				}
			}
		}
		$report['counts'] = $plugin->repo->counts( $c->id );
		$report['status'] = $c->status;
		// Storefront lookup table must agree (price filters/sorting use it).
		global $wpdb;
		$report['lookup_mismatch'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}dsd_items i JOIN {$wpdb->prefix}wc_product_meta_lookup l ON l.product_id = i.product_id
				 WHERE i.campaign_id = %d AND i.status = 'applied' AND ABS(l.min_price - i.new_sale) > 0.001",
				$c->id
			)
		);
		dsd_out( $report );
		break;

	case 'check_restored':
		$before = dsd_load_snapshot( $args[1] );
		$now    = dsd_all_rows();
		$diff   = array();
		$n      = 0;
		foreach ( $before as $id => $old ) {
			$cur  = $now[ (int) $id ];
			$keys = array( '_regular_price', '_sale_price', '_price', '_sale_price_dates_from', '_sale_price_dates_to', '_dsd_campaign' );
			if ( isset( $old['ptype'] ) && in_array( $old['ptype'], array( 'variable', 'grouped' ), true ) ) {
				// Parent _price is WooCommerce's own sync of its children; it is re-derived, not ours.
				$keys = array_diff( $keys, array( '_price' ) );
			}
			foreach ( $keys as $k ) {
				$a = isset( $old['meta'][ $k ] ) ? (string) $old['meta'][ $k ] : null;
				$b = isset( $cur['meta'][ $k ] ) ? (string) $cur['meta'][ $k ] : null;
				// WooCommerce may store an empty date as "" vs missing.
				if ( ( null === $a || '' === $a ) && ( null === $b || '' === $b ) ) {
					continue;
				}
				if ( $a !== $b ) {
					$n++;
					if ( count( $diff ) < 10 ) {
						$diff[] = array( 'id' => (int) $id, 'key' => $k, 'before' => $a, 'after' => $b );
					}
				}
			}
		}
		// Storefront lookup table (price filter/sort) must match the price meta again.
		global $wpdb;
		$lookup = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}wc_product_meta_lookup l
			 JOIN {$wpdb->postmeta} pm ON pm.post_id = l.product_id AND pm.meta_key = '_price'
			 JOIN {$wpdb->posts} p ON p.ID = l.product_id AND p.post_type IN ('product','product_variation')
			 WHERE pm.meta_value <> '' AND ABS(l.min_price - pm.meta_value) > 0.001
			   AND p.ID NOT IN (SELECT tr.object_id FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_type' JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE t.slug IN ('variable','grouped'))"
		);
		dsd_out( array( 'products' => count( $before ), 'differences' => $n, 'samples' => $diff, 'lookup_mismatch' => $lookup ) );
		break;

	case 'view_price':
		PriceGuard::flush();
		clean_post_cache( (int) $args[1] );
		$p = wc_get_product( (int) $args[1] );
		dsd_out(
			array(
				'price'   => $p->get_price(),
				'sale'    => $p->get_sale_price(),
				'regular' => $p->get_regular_price(),
				'on_sale' => $p->is_on_sale(),
				'db_sale' => $p->get_sale_price( 'edit' ),
			)
		);
		break;

	case 'set_sale':
		$p = wc_get_product( (int) $args[1] );
		$p->set_sale_price( $args[2] );
		$p->save();
		dsd_out( array( 'ok' => true ) );
		break;

	case 'full_edit':
		// Admin presses "Update" on the product edit screen without changing anything. The
		// screen renders prices with 'edit' context (html-product-data-general.php).
		$id   = (int) $args[1];
		$p    = dsd_fresh( $id );
		$from = $p->get_date_on_sale_from( 'edit' );
		$to   = $p->get_date_on_sale_to( 'edit' );
		wp_set_current_user( 1 );
		$_POST    = array(
			'_regular_price'         => $p->get_regular_price( 'edit' ),
			'_sale_price'            => $p->get_sale_price( 'edit' ),
			'_sale_price_dates_from' => $from ? $from->date( 'Y-m-d' ) : '',
			'_sale_price_dates_to'   => $to ? $to->date( 'Y-m-d' ) : '',
			'_stock_status'          => $p->get_stock_status( 'edit' ),
			'_tax_status'            => $p->get_tax_status( 'edit' ),
			'_tax_class'             => $p->get_tax_class( 'edit' ),
			'_sku'                   => $p->get_sku( 'edit' ),
			'_backorders'            => $p->get_backorders( 'edit' ),
			'_visibility'            => $p->get_catalog_visibility( 'edit' ),
			'product-type'           => $p->get_type(),
		);
		$_REQUEST = $_POST;
		WC_Meta_Box_Product_Data::save( $id, get_post( $id ) );
		$_POST    = array();
		$_REQUEST = array();
		dsd_out( array( 'ok' => true ) );
		break;

	case 'quick_edit':
		// Quick Edit fills its form from hidden inline data rendered with VIEW-context getters
		// (class-wc-admin-list-table-products.php), i.e. what PriceGuard lets the storefront see.
		$id = (int) $args[1];
		PriceGuard::flush();
		$p = dsd_fresh( $id );
		wp_set_current_user( 1 );
		$data     = array(
			'woocommerce_quick_edit'       => '1',
			'woocommerce_quick_edit_nonce' => wp_create_nonce( 'woocommerce_quick_edit_nonce' ),
			'_regular_price'               => (string) $p->get_regular_price(),
			'_sale_price'                  => (string) $p->get_sale_price(),
			'_sale_price_dates_from'       => '',
			'_sale_price_dates_to'         => '',
			'_stock_status'                => $p->get_stock_status(),
			'_tax_status'                  => $p->get_tax_status(),
			'_visibility'                  => $p->get_catalog_visibility(),
			'_sku'                         => $p->get_sku(),
		);
		$_POST    = $data;
		$_REQUEST = $data;
		wp_update_post( array( 'ID' => $id ) ); // save_post → WooCommerce quick edit save.
		$_POST    = array();
		$_REQUEST = array();
		dsd_out( array( 'ok' => true, 'regular' => $data['_regular_price'], 'sale' => $data['_sale_price'] ) );
		break;

	case 'rest_roundtrip':
		// An external app reads the product through /wc/v3 and writes the same prices back.
		$id = (int) $args[1];
		PriceGuard::flush();
		wp_set_current_user( 1 );
		$post  = get_post( $id );
		$route = 'product_variation' === $post->post_type ? "/wc/v3/products/{$post->post_parent}/variations/{$id}" : "/wc/v3/products/{$id}";
		$got   = rest_do_request( new WP_REST_Request( 'GET', $route ) )->get_data();
		$put   = new WP_REST_Request( 'PUT', $route );
		$put->set_body_params( array( 'regular_price' => $got['regular_price'], 'sale_price' => $got['sale_price'] ) );
		dsd_out( array( 'status' => rest_do_request( $put )->get_status(), 'price' => $got['price'] ) );
		break;

	case 'price_html':
		PriceGuard::flush();
		$out = array();
		foreach ( array_map( 'intval', explode( ',', $args[1] ) ) as $id ) {
			$out[ $id ] = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( html_entity_decode( dsd_fresh( $id )->get_price_html() ) ) ) );
		}
		dsd_out( $out );
		break;

	case 'permalinks':
		$out = array();
		foreach ( array_map( 'intval', explode( ',', $args[1] ) ) as $id ) {
			$out[ $id ] = (string) get_permalink( $id );
		}
		dsd_out( $out );
		break;

	case 'pick_own_sale':
		// In-stock simple products that already have their own (undated) sale price.
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
				 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_type'
				 JOIN {$wpdb->terms} t ON t.term_id = tt.term_id AND t.slug = 'simple'
				 JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_stock_status' AND s.meta_value = 'instock'
				 JOIN {$wpdb->postmeta} sp ON sp.post_id = p.ID AND sp.meta_key = '_sale_price' AND sp.meta_value <> ''
				 LEFT JOIN {$wpdb->postmeta} df ON df.post_id = p.ID AND df.meta_key = '_sale_price_dates_from'
				 WHERE p.post_type = 'product' AND p.post_status = 'publish' AND ( df.meta_value IS NULL OR df.meta_value = '' )
				 ORDER BY p.ID LIMIT %d",
				(int) $args[1]
			)
		);
		dsd_out( array_map( 'intval', $ids ) );
		break;

	case 'diagnose':
		// Runs docs/diagnose-regular-price.sql exactly as a shop owner would (table prefix adapted).
		global $wpdb;
		$sql  = file_get_contents( $args[1] );
		$sql  = preg_replace( '/^\s*--.*$/m', '', $sql );
		$sql  = str_replace( 'wp_', $wpdb->prefix, trim( rtrim( trim( $sql ), ';' ) ) );
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		dsd_out( array( 'error' => $wpdb->last_error, 'rows' => $rows ) );
		break;

	case 'pick':
		global $wpdb;
		$type = isset( $args[2] ) ? $args[2] : 'simple';
		$ids  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
				 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_type'
				 JOIN {$wpdb->terms} t ON t.term_id = tt.term_id AND t.slug = %s
				 JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_stock_status' AND s.meta_value = 'instock'
				 LEFT JOIN {$wpdb->postmeta} sp ON sp.post_id = p.ID AND sp.meta_key = '_sale_price'
				 LEFT JOIN {$wpdb->postmeta} rp ON rp.post_id = p.ID AND rp.meta_key = '_regular_price'
				 WHERE p.post_type = 'product' AND p.post_status = 'publish' AND (sp.meta_value IS NULL OR sp.meta_value = '')
				   AND ( %s = 'variable' OR rp.meta_value + 0 > 0 )
				 ORDER BY p.ID LIMIT %d",
				$type,
				$type,
				(int) $args[1]
			)
		);
		dsd_out( array_map( 'intval', $ids ) );
		break;

	case 'force_end':
		// Simulate a campaign whose revert never ran (e.g. plugin removed mid-way, DB restored):
		// it ends, but its prices and locks stay on the products.
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'dsd_campaigns', array( 'status' => 'ended' ), array( 'id' => (int) $args[1] ) );
		PriceGuard::rebuild();
		dsd_out( array( 'ok' => true ) );
		break;

	case 'item_rows':
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}dsd_items WHERE product_id = %d", (int) $args[1] ), ARRAY_A );
		$meta = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key IN ('_sale_price','_price','_regular_price','_dsd_campaign')", (int) $args[1] ), ARRAY_A );
		dsd_out( array( 'items' => $rows, 'meta' => $meta ) );
		break;

	case 'categories':
		// Top-level categories that actually contain products (directly or via children).
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'parent' => 0 ) );
		$out   = array();
		foreach ( $terms as $t ) {
			$ids = array_merge( array( (int) $t->term_id ), dsd_descendants( (int) $t->term_id ) );
			$n   = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->term_relationships} tr JOIN {$GLOBALS['wpdb']->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.term_id IN (" . implode( ',', $ids ) . ')' );
			if ( $n > 0 ) {
				$out[] = array( 'id' => (int) $t->term_id, 'name' => $t->name, 'products' => $n );
			}
		}
		dsd_out( $out );
		break;

	default:
		WP_CLI::error( 'Unknown probe command.' );
}

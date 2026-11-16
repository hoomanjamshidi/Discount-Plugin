<?php
/**
 * Turns a campaign target (products / categories / all in stock) into product IDs
 * using cursor-paginated SQL, so 3000+ products never load into memory at once.
 *
 * Purchasable rows are simple-type products and variations. Variable/grouped parents
 * are expanded into their variations (a variable parent has no price of its own).
 *
 * @package SmartBulkDiscount
 */

namespace SmartBulkDiscount;

defined( 'ABSPATH' ) || exit;

/**
 * SQL-level target resolution.
 */
final class TargetResolver {

	/**
	 * Next page of [product_id, parent_id] after $after_id.
	 *
	 * @param Campaign $c        Campaign.
	 * @param int      $after_id Cursor.
	 * @param int      $limit    Page size.
	 * @return array<int, array{0:int,1:int}>
	 */
	public function next_ids( Campaign $c, $after_id, $limit ) {
		global $wpdb;
		list( $from, $where ) = $this->build( $c );
		if ( null === $from ) {
			return array();
		}
		$sql  = "SELECT p.ID, p.post_parent, p.post_type {$from} WHERE {$where} AND p.ID > %d ORDER BY p.ID ASC LIMIT %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, (int) $after_id, (int) $limit ) ); // phpcs:ignore
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array( (int) $r->ID, 'product_variation' === $r->post_type ? (int) $r->post_parent : 0 );
		}
		return $out;
	}

	/**
	 * Next chunk for the prepare phase, with a bounded cost per call.
	 *
	 * Step 1 reads the next $limit product/variation IDs straight from the posts table
	 * (cheap index range, independent of how many orders, revisions or postmeta rows the
	 * site has). Step 2 applies the full target filter to just those IDs. The old single
	 * query could scan large parts of a busy store's database on every page.
	 *
	 * @param Campaign $c        Campaign.
	 * @param int      $after_id Cursor (last scanned post ID).
	 * @param int      $limit    Chunk size.
	 * @return array{rows:array,cursor:int,done:bool}
	 */
	public function next_chunk( Campaign $c, $after_id, $limit ) {
		global $wpdb;
		list( $from, $where ) = $this->build( $c );
		if ( null === $from ) {
			return array(
				'rows'   => array(),
				'cursor' => (int) $after_id,
				'done'   => true,
			);
		}
		$scope = '';
		if ( Campaign::TARGET_PRODUCTS === $c->target_type ) {
			$list  = implode( ',', self::int_list( $c->target['product_ids'] ) );
			$scope = " AND ( ID IN ({$list}) OR post_parent IN ({$list}) )";
		}
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') AND ID > %d{$scope} ORDER BY ID ASC LIMIT %d", // phpcs:ignore
				(int) $after_id,
				(int) $limit
			)
		);
		$ids = array_map( 'intval', (array) $ids );
		if ( empty( $ids ) ) {
			return array(
				'rows'   => array(),
				'cursor' => (int) $after_id,
				'done'   => true,
			);
		}
		$in   = implode( ',', $ids );
		$rows = $wpdb->get_results( "SELECT p.ID, p.post_parent, p.post_type {$from} WHERE {$where} AND p.ID IN ({$in}) ORDER BY p.ID ASC" ); // phpcs:ignore
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array( (int) $r->ID, 'product_variation' === $r->post_type ? (int) $r->post_parent : 0 );
		}
		return array(
			'rows'   => $out,
			'cursor' => (int) end( $ids ),
			'done'   => count( $ids ) < (int) $limit,
		);
	}

	/**
	 * How many purchasable rows the target matches.
	 *
	 * @param Campaign $c Campaign.
	 * @return int
	 */
	public function count( Campaign $c ) {
		global $wpdb;
		list( $from, $where ) = $this->build( $c );
		if ( null === $from ) {
			return 0;
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) {$from} WHERE {$where}" ); // phpcs:ignore
	}

	/**
	 * Build FROM and WHERE (both already escaped).
	 *
	 * @param Campaign $c Campaign.
	 * @return array{0:string|null,1:string}
	 */
	private function build( Campaign $c ) {
		global $wpdb;
		$posts = $wpdb->posts;
		$meta  = $wpdb->postmeta;
		$tr    = $wpdb->term_relationships;
		$tt    = $wpdb->term_taxonomy;
		$terms = $wpdb->terms;

		$only_in_stock = Campaign::TARGET_ALL === $c->target_type || ! empty( $c->options['only_in_stock'] );

		$from  = "FROM {$posts} p
			LEFT JOIN {$posts} par ON par.ID = p.post_parent AND p.post_type = 'product_variation'
			INNER JOIN {$meta} rp ON rp.post_id = p.ID AND rp.meta_key = '_regular_price'";
		if ( $only_in_stock ) {
			$from .= " INNER JOIN {$meta} ss ON ss.post_id = p.ID AND ss.meta_key = '_stock_status' AND ss.meta_value = 'instock'";
		}

		$owner = "IF(p.post_type = 'product_variation', p.post_parent, p.ID)";
		$where = array(
			"rp.meta_value <> '' AND rp.meta_value + 0 > 0",
			"( (p.post_type = 'product' AND p.post_status = 'publish')
			   OR (p.post_type = 'product_variation' AND p.post_status = 'publish' AND par.post_status = 'publish') )",
			// Parents without a price of their own.
			"NOT EXISTS (SELECT 1 FROM {$tr} tr0
				INNER JOIN {$tt} tt0 ON tt0.term_taxonomy_id = tr0.term_taxonomy_id AND tt0.taxonomy = 'product_type'
				INNER JOIN {$terms} t0 ON t0.term_id = tt0.term_id
				WHERE tr0.object_id = p.ID AND t0.slug IN ('variable','grouped','variable-subscription'))",
		);

		$target = $c->target;

		if ( Campaign::TARGET_PRODUCTS === $c->target_type ) {
			$ids = self::int_list( $target['product_ids'] );
			if ( empty( $ids ) ) {
				return array( null, '' );
			}
			$list    = implode( ',', $ids );
			$where[] = "( p.ID IN ({$list}) OR ( p.post_type = 'product_variation' AND p.post_parent IN ({$list}) ) )";
		} elseif ( Campaign::TARGET_CATEGORIES === $c->target_type ) {
			$tt_ids = $this->term_taxonomy_ids( $target['category_ids'], ! empty( $c->options['include_children'] ) );
			if ( empty( $tt_ids ) ) {
				return array( null, '' );
			}
			$list    = implode( ',', $tt_ids );
			$where[] = "EXISTS (SELECT 1 FROM {$tr} tr1 WHERE tr1.object_id = {$owner} AND tr1.term_taxonomy_id IN ({$list}))";
		} elseif ( Campaign::TARGET_ALL !== $c->target_type ) {
			return array( null, '' );
		}

		$exclude = self::int_list( $target['exclude_product_ids'] );
		if ( ! empty( $exclude ) ) {
			$list    = implode( ',', $exclude );
			$where[] = "p.ID NOT IN ({$list}) AND {$owner} NOT IN ({$list})";
		}
		$ex_cats = $this->term_taxonomy_ids( $target['exclude_category_ids'], true );
		if ( ! empty( $ex_cats ) ) {
			$list    = implode( ',', $ex_cats );
			$where[] = "NOT EXISTS (SELECT 1 FROM {$tr} tr2 WHERE tr2.object_id = {$owner} AND tr2.term_taxonomy_id IN ({$list}))";
		}

		return array( $from, implode( ' AND ', $where ) );
	}

	/**
	 * product_cat term IDs → term_taxonomy IDs (optionally with descendants).
	 *
	 * @param array $term_ids Term IDs.
	 * @param bool  $children Include children.
	 * @return int[]
	 */
	private function term_taxonomy_ids( $term_ids, $children ) {
		$term_ids = self::int_list( $term_ids );
		if ( empty( $term_ids ) ) {
			return array();
		}
		$all = $children ? self::with_descendants( $term_ids ) : $term_ids;
		$out = array();
		foreach ( array_unique( $all ) as $id ) {
			$term = get_term( $id, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$out[] = (int) $term->term_taxonomy_id;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Category IDs plus all their descendants, read straight from term_taxonomy.
	 *
	 * We deliberately do not use get_term_children(): it relies on the cached
	 * `product_cat_children` option, which goes stale after bulk imports that suspend
	 * cache invalidation — and a stale cache would silently drop whole sub-categories.
	 *
	 * @param int[] $term_ids Roots.
	 * @return int[]
	 */
	public static function with_descendants( array $term_ids ) {
		global $wpdb;
		$rows      = $wpdb->get_results( "SELECT term_id, parent FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'product_cat'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$by_parent = array();
		foreach ( (array) $rows as $r ) {
			$by_parent[ (int) $r->parent ][] = (int) $r->term_id;
		}
		$out   = array();
		$queue = array_map( 'intval', $term_ids );
		while ( $queue ) {
			$id = array_shift( $queue );
			if ( isset( $out[ $id ] ) ) {
				continue; // Guards against corrupted (cyclic) hierarchies.
			}
			$out[ $id ] = true;
			if ( isset( $by_parent[ $id ] ) ) {
				$queue = array_merge( $queue, $by_parent[ $id ] );
			}
		}
		return array_keys( $out );
	}

	/**
	 * Positive unique ints.
	 *
	 * @param mixed $list List.
	 * @return int[]
	 */
	public static function int_list( $list ) {
		if ( ! is_array( $list ) ) {
			return array();
		}
		$ints = array_filter( array_map( 'absint', $list ) );
		return array_values( array_unique( $ints ) );
	}
}

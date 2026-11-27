<?php
/**
 * Data access for campaigns and their items.
 *
 * @package SmartBulkDiscount
 */

namespace SmartBulkDiscount;

defined( 'ABSPATH' ) || exit;

/**
 * All SQL for the two plugin tables lives here.
 */
final class Repository {

	const ITEM_PENDING   = 'pending';
	const ITEM_APPLIED   = 'applied';
	const ITEM_SKIPPED   = 'skipped';
	const ITEM_FAILED    = 'failed';
	const ITEM_REVERTED  = 'reverted';
	const ITEM_KEPT      = 'kept';       // admin changed the price meanwhile; left as is.
	const ITEM_CANCELLED = 'cancelled';  // never applied because the campaign stopped.

	const MAX_ATTEMPTS = 3;

	/**
	 * Per-request cache: campaign id => is live.
	 *
	 * @var array
	 */
	private $live_cache = array();

	/**
	 * Campaign table.
	 *
	 * @return string
	 */
	public static function campaigns_table() {
		global $wpdb;
		return $wpdb->prefix . 'dsd_campaigns';
	}

	/**
	 * Items table.
	 *
	 * @return string
	 */
	public static function items_table() {
		global $wpdb;
		return $wpdb->prefix . 'dsd_items';
	}

	/**
	 * Empty stats shape.
	 *
	 * @return array
	 */
	public static function empty_stats() {
		return array(
			'total'     => 0,
			'pending'   => 0,
			'applied'   => 0,
			'skipped'   => 0,
			'failed'    => 0,
			'reverted'  => 0,
			'kept'      => 0,
			'cancelled' => 0,
		);
	}

	// ---------------------------------------------------------------------
	// Campaigns.
	// ---------------------------------------------------------------------

	/**
	 * Find one.
	 *
	 * @param int $id ID.
	 * @return Campaign|null
	 */
	public function find( $id ) {
		global $wpdb;
		$t   = self::campaigns_table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? Campaign::from_row( $row ) : null;
	}

	/**
	 * All campaigns, newest first.
	 *
	 * @return Campaign[]
	 */
	public function all() {
		global $wpdb;
		$t    = self::campaigns_table();
		$rows = $wpdb->get_results( "SELECT * FROM {$t} ORDER BY id DESC LIMIT 500" ); // phpcs:ignore
		return array_map( array( Campaign::class, 'from_row' ), (array) $rows );
	}

	/**
	 * Campaigns in the given statuses.
	 *
	 * @param string[] $statuses Statuses.
	 * @return Campaign[]
	 */
	public function by_status( array $statuses ) {
		global $wpdb;
		if ( empty( $statuses ) ) {
			return array();
		}
		$t    = self::campaigns_table();
		$in   = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE status IN ({$in}) ORDER BY id ASC", $statuses ) ); // phpcs:ignore
		return array_map( array( Campaign::class, 'from_row' ), (array) $rows );
	}

	/**
	 * The campaign the worker should process next.
	 * Ending on time matters most (money), then confirming, then applying, then preparing.
	 *
	 * @return Campaign|null
	 */
	public function next_working() {
		global $wpdb;
		$t   = self::campaigns_table();
		$row = $wpdb->get_row( // phpcs:ignore
			"SELECT * FROM {$t} WHERE status IN ('reverting','verifying_revert','verifying','applying','preparing')
			ORDER BY FIELD(status,'reverting','verifying_revert','verifying','applying','preparing'), updated_at ASC, id ASC LIMIT 1" // phpcs:ignore
		);
		return $row ? Campaign::from_row( $row ) : null;
	}

	/**
	 * Insert a campaign; returns its ID.
	 *
	 * @param Campaign $c Campaign.
	 * @return int
	 */
	public function insert( Campaign $c ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->insert( self::campaigns_table(), array_merge( $this->row( $c ), array( 'created_at' => $now, 'updated_at' => $now, 'created_by' => get_current_user_id() ) ) ); // phpcs:ignore
		return (int) $wpdb->insert_id;
	}

	/**
	 * Save definition fields.
	 *
	 * @param Campaign $c Campaign.
	 */
	public function save( Campaign $c ) {
		global $wpdb;
		$wpdb->update( self::campaigns_table(), array_merge( $this->row( $c ), array( 'updated_at' => current_time( 'mysql', true ) ) ), array( 'id' => $c->id ) ); // phpcs:ignore
	}

	/**
	 * Patch columns directly.
	 *
	 * @param int   $id     ID.
	 * @param array $fields Column => value.
	 */
	public function patch( $id, array $fields ) {
		global $wpdb;
		foreach ( array( 'stats', 'log', 'target', 'options' ) as $json ) {
			if ( isset( $fields[ $json ] ) && is_array( $fields[ $json ] ) ) {
				$fields[ $json ] = wp_json_encode( $fields[ $json ] );
			}
		}
		foreach ( array( 'starts_at', 'ends_at' ) as $date ) {
			if ( array_key_exists( $date, $fields ) && ( null === $fields[ $date ] || is_int( $fields[ $date ] ) ) ) {
				$fields[ $date ] = Campaign::dt( $fields[ $date ] );
			}
		}
		$fields['updated_at'] = current_time( 'mysql', true );
		$wpdb->update( self::campaigns_table(), $fields, array( 'id' => (int) $id ) ); // phpcs:ignore
	}

	/**
	 * Compare-and-set status (prevents two workers racing a transition).
	 *
	 * @param int    $id   ID.
	 * @param string $from Expected current status.
	 * @param string $to   New status.
	 * @param array  $more Extra columns.
	 * @return bool
	 */
	public function transition( $id, $from, $to, array $more = array() ) {
		global $wpdb;
		$t   = self::campaigns_table();
		$set = array( 'status = %s', 'updated_at = %s' );
		$val = array( $to, current_time( 'mysql', true ) );
		foreach ( $more as $col => $value ) {
			if ( ! preg_match( '/^[a-z_]+$/', $col ) ) {
				continue;
			}
			if ( null === $value ) {
				$set[] = "{$col} = NULL";
				continue;
			}
			$set[] = "{$col} = %s";
			$val[] = is_array( $value ) ? wp_json_encode( $value ) : $value;
		}
		$val[] = (int) $id;
		$val[] = $from;
		$sql   = "UPDATE {$t} SET " . implode( ', ', $set ) . ' WHERE id = %d AND status = %s';
		$done  = (int) $wpdb->query( $wpdb->prepare( $sql, $val ) ); // phpcs:ignore
		if ( $done > 0 ) {
			do_action( 'dsd_campaign_transition', (int) $id, $from, $to );
		}
		return $done > 0;
	}

	/**
	 * Append a log line (kept to the last 50).
	 *
	 * @param int    $id      ID.
	 * @param string $code    Machine code (translated in the UI).
	 * @param array  $context Numbers etc.
	 */
	public function log( $id, $code, array $context = array() ) {
		global $wpdb;
		$t   = self::campaigns_table();
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT log FROM {$t} WHERE id = %d", (int) $id ) ); // phpcs:ignore
		$log = $raw ? json_decode( $raw, true ) : array();
		$log = is_array( $log ) ? $log : array();
		$log[] = array(
			't'    => time(),
			'code' => $code,
			'ctx'  => $context,
		);
		$log = array_slice( $log, -50 );
		$wpdb->update( $t, array( 'log' => wp_json_encode( $log ) ), array( 'id' => (int) $id ) ); // phpcs:ignore
	}

	/**
	 * Delete a campaign and its items.
	 *
	 * @param int $id ID.
	 */
	public function delete( $id ) {
		global $wpdb;
		$wpdb->delete( self::items_table(), array( 'campaign_id' => (int) $id ) ); // phpcs:ignore
		$wpdb->delete( self::campaigns_table(), array( 'id' => (int) $id ) ); // phpcs:ignore
	}

	/**
	 * Map definition to columns.
	 *
	 * @param Campaign $c Campaign.
	 * @return array
	 */
	private function row( Campaign $c ) {
		return array(
			'name'           => $c->name,
			'status'         => $c->status,
			'paused_status'  => $c->paused_status,
			'discount_type'  => $c->discount_type,
			'discount_value' => $c->discount_value,
			'target_type'    => $c->target_type,
			'target'         => wp_json_encode( $c->target ),
			'options'        => wp_json_encode( $c->options ),
			'starts_at'      => Campaign::dt( $c->starts_at ),
			'ends_at'        => Campaign::dt( $c->ends_at ),
			'repeat_mode'    => $c->repeat_mode,
		);
	}

	// ---------------------------------------------------------------------
	// Items.
	// ---------------------------------------------------------------------

	/**
	 * Bulk insert items, ignoring duplicates (idempotent prepare).
	 *
	 * @param int   $campaign_id Campaign.
	 * @param array $rows        Each: [product_id, parent_id].
	 * @return int Inserted rows.
	 */
	public function add_items( $campaign_id, array $rows ) {
		global $wpdb;
		if ( empty( $rows ) ) {
			return 0;
		}
		$t      = self::items_table();
		$now    = current_time( 'mysql', true );
		$values = array();
		$args   = array();
		foreach ( $rows as $r ) {
			$values[] = '(%d,%d,%d,%s,%s)';
			array_push( $args, (int) $campaign_id, (int) $r[0], (int) $r[1], self::ITEM_PENDING, $now );
		}
		$sql = "INSERT IGNORE INTO {$t} (campaign_id, product_id, parent_id, status, updated_at) VALUES " . implode( ',', $values );
		return (int) $wpdb->query( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore
	}

	/**
	 * Next items in a status, ordered by ID.
	 *
	 * @param int    $campaign_id Campaign.
	 * @param string $status      Status.
	 * @param int    $limit       Limit.
	 * @param int    $after_id    Cursor.
	 * @return object[]
	 */
	public function items_in_status( $campaign_id, $status, $limit, $after_id = 0 ) {
		global $wpdb;
		$t = self::items_table();
		return (array) $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare( "SELECT * FROM {$t} WHERE campaign_id = %d AND status = %s AND id > %d ORDER BY id ASC LIMIT %d", (int) $campaign_id, $status, (int) $after_id, (int) $limit ) // phpcs:ignore
		);
	}

	/**
	 * One campaign's item for a product.
	 *
	 * @param int $campaign_id Campaign.
	 * @param int $product_id  Product.
	 * @return object|null
	 */
	public function find_item( $campaign_id, $product_id ) {
		global $wpdb;
		$t = self::items_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE campaign_id = %d AND product_id = %d", (int) $campaign_id, (int) $product_id ) ); // phpcs:ignore
	}

	/**
	 * One item by its own ID.
	 *
	 * @param int $item_id Item ID.
	 * @return object|null
	 */
	public function find_item_by_id( $item_id ) {
		global $wpdb;
		$t = self::items_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $item_id ) ); // phpcs:ignore
	}

	/**
	 * Update one item.
	 *
	 * @param int   $item_id ID.
	 * @param array $fields  Columns.
	 */
	public function update_item( $item_id, array $fields ) {
		global $wpdb;
		$fields['updated_at'] = current_time( 'mysql', true );
		if ( isset( $fields['note'] ) ) {
			$fields['note'] = substr( (string) $fields['note'], 0, 190 );
		}
		$wpdb->update( self::items_table(), $fields, array( 'id' => (int) $item_id ) ); // phpcs:ignore
	}

	/**
	 * Move every item in $from to $to.
	 *
	 * @param int    $campaign_id Campaign.
	 * @param string $from        From.
	 * @param string $to          To.
	 * @param string $note        Note.
	 * @return int
	 */
	public function bulk_status( $campaign_id, $from, $to, $note = '' ) {
		global $wpdb;
		$t = self::items_table();
		return (int) $wpdb->query( // phpcs:ignore
			$wpdb->prepare( "UPDATE {$t} SET status = %s, note = %s, updated_at = %s WHERE campaign_id = %d AND status = %s", $to, $note, current_time( 'mysql', true ), (int) $campaign_id, $from ) // phpcs:ignore
		);
	}

	/**
	 * Failed items get another chance (manual "retry").
	 *
	 * @param int $campaign_id Campaign.
	 * @return int
	 */
	public function retry_failed( $campaign_id ) {
		global $wpdb;
		$t = self::items_table();
		return (int) $wpdb->query( // phpcs:ignore
			$wpdb->prepare( "UPDATE {$t} SET status = 'pending', attempts = 0, note = '' WHERE campaign_id = %d AND status = 'failed'", (int) $campaign_id ) // phpcs:ignore
		);
	}

	/**
	 * When a campaign ends: pending items with a backup may carry our price (interrupted
	 * write) → applied (the revert checks them); the rest were never touched → cancelled.
	 *
	 * @param int $campaign_id Campaign.
	 */
	public function pending_to_revert( $campaign_id ) {
		global $wpdb;
		$t = self::items_table();
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = 'applied', note = 'interrupted' WHERE campaign_id = %d AND status = 'pending' AND has_backup = 1", (int) $campaign_id ) ); // phpcs:ignore
		$this->bulk_status( $campaign_id, self::ITEM_PENDING, self::ITEM_CANCELLED );
	}

	/**
	 * Failed items that have a price backup become "applied" again so the revert phase
	 * checks them (it leaves anything that is not our price untouched).
	 *
	 * @param int $campaign_id Campaign.
	 * @return int
	 */
	public function failed_with_backup_to_applied( $campaign_id ) {
		global $wpdb;
		$t = self::items_table();
		return (int) $wpdb->query( // phpcs:ignore
			$wpdb->prepare( "UPDATE {$t} SET status = 'applied', note = 'failed_apply' WHERE campaign_id = %d AND status = 'failed' AND has_backup = 1", (int) $campaign_id ) // phpcs:ignore
		);
	}

	/**
	 * Reset retry counters before a new phase (e.g. revert gets its own 3 attempts).
	 *
	 * @param int    $campaign_id Campaign.
	 * @param string $status      Status.
	 */
	public function reset_attempts( $campaign_id, $status ) {
		global $wpdb;
		$t = self::items_table();
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET attempts = 0 WHERE campaign_id = %d AND status = %s", (int) $campaign_id, $status ) ); // phpcs:ignore
	}

	/**
	 * Remove all items (used before a repeat window rebuilds the list).
	 *
	 * @param int $campaign_id Campaign.
	 */
	public function clear_items( $campaign_id ) {
		global $wpdb;
		$wpdb->delete( self::items_table(), array( 'campaign_id' => (int) $campaign_id ) ); // phpcs:ignore
	}

	/**
	 * Counts per status.
	 *
	 * @param int $campaign_id Campaign.
	 * @return array
	 */
	public function counts( $campaign_id ) {
		global $wpdb;
		$t     = self::items_table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS n FROM {$t} WHERE campaign_id = %d GROUP BY status", (int) $campaign_id ) ); // phpcs:ignore
		$stats = self::empty_stats();
		foreach ( (array) $rows as $r ) {
			$stats[ $r->status ] = (int) $r->n;
			$stats['total']     += (int) $r->n;
		}
		return $stats;
	}

	/**
	 * Refresh cached stats on the campaign row.
	 *
	 * @param int   $campaign_id Campaign.
	 * @param array $extra       Extra stats keys (e.g. throughput).
	 * @return array
	 */
	public function refresh_stats( $campaign_id, array $extra = array() ) {
		$c     = $this->find( $campaign_id );
		$stats = array_merge( $c ? $c->stats : array(), $this->counts( $campaign_id ), $extra );
		$this->patch( $campaign_id, array( 'stats' => $stats ) );
		return $stats;
	}

	/**
	 * Paged items for the UI, joined with product titles.
	 *
	 * @param int    $campaign_id Campaign.
	 * @param string $status      '' = all.
	 * @param int    $page        1-based.
	 * @param int    $per_page    Per page.
	 * @return array{items:array,total:int}
	 */
	public function items_page( $campaign_id, $status, $page, $per_page ) {
		global $wpdb;
		$t      = self::items_table();
		$where  = $wpdb->prepare( 'i.campaign_id = %d', (int) $campaign_id );
		if ( '' !== $status ) {
			$where .= $wpdb->prepare( ' AND i.status = %s', $status );
		}
		$offset = max( 0, ( (int) $page - 1 ) * (int) $per_page );
		$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} i WHERE {$where}" ); // phpcs:ignore
		$rows   = $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				"SELECT i.*, p.post_title, pp.post_title AS parent_title FROM {$t} i
				LEFT JOIN {$wpdb->posts} p ON p.ID = i.product_id
				LEFT JOIN {$wpdb->posts} pp ON pp.ID = i.parent_id
				WHERE {$where} ORDER BY i.id ASC LIMIT %d OFFSET %d", // phpcs:ignore
				(int) $per_page,
				$offset
			)
		);
		return array(
			'items' => (array) $rows,
			'total' => $total,
		);
	}

	/**
	 * Items for a report export, keyset-paged by item ID (cost stays flat on any page),
	 * with product/parent titles, SKUs and current prices (for items never backed up). Read-only.
	 *
	 * @param int    $campaign_id Campaign.
	 * @param string $status      '' = all.
	 * @param int    $after_id    Cursor (last item ID already read).
	 * @param int    $limit       Rows.
	 * @return object[]
	 */
	public function export_chunk( $campaign_id, $status, $after_id, $limit ) {
		global $wpdb;
		$t     = self::items_table();
		$where = $wpdb->prepare( 'i.campaign_id = %d AND i.id > %d', (int) $campaign_id, (int) $after_id );
		if ( '' !== (string) $status ) {
			$where .= $wpdb->prepare( ' AND i.status = %s', (string) $status );
		}
		return (array) $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				"SELECT i.id, i.product_id, i.parent_id, i.status, i.orig_regular, i.orig_sale, i.new_sale, i.note,
				p.post_title, pp.post_title AS parent_title,
				(SELECT m.meta_value FROM {$wpdb->postmeta} m WHERE m.post_id = i.product_id AND m.meta_key = '_sku' LIMIT 1) AS sku,
				(SELECT pm.meta_value FROM {$wpdb->postmeta} pm WHERE pm.post_id = i.parent_id AND pm.meta_key = '_sku' LIMIT 1) AS parent_sku,
				(SELECT r.meta_value FROM {$wpdb->postmeta} r WHERE r.post_id = i.product_id AND r.meta_key = '_regular_price' LIMIT 1) AS cur_regular,
				(SELECT s.meta_value FROM {$wpdb->postmeta} s WHERE s.post_id = i.product_id AND s.meta_key = '_sale_price' LIMIT 1) AS cur_sale
				FROM {$t} i
				LEFT JOIN {$wpdb->posts} p ON p.ID = i.product_id
				LEFT JOIN {$wpdb->posts} pp ON pp.ID = i.parent_id
				WHERE {$where} ORDER BY i.id ASC LIMIT %d", // phpcs:ignore
				max( 1, (int) $limit )
			)
		);
	}

	/**
	 * Variation attributes (meta_key attribute_* => value) for a list of variations.
	 *
	 * @param int[] $variation_ids IDs.
	 * @return array<int, array<string, string>>
	 */
	public function variation_attributes( array $variation_ids ) {
		global $wpdb;
		$ids = TargetResolver::int_list( $variation_ids );
		if ( empty( $ids ) ) {
			return array();
		}
		$rows = $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN (" . implode( ',', $ids ) . ') AND meta_key LIKE %s', // phpcs:ignore
				$wpdb->esc_like( 'attribute_' ) . '%'
			)
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r->post_id ][ (string) $r->meta_key ] = (string) $r->meta_value;
		}
		return $out;
	}

	/**
	 * Products currently locked by a *live* campaign other than $campaign_id.
	 *
	 * @param int $product_id  Product.
	 * @param int $campaign_id Asking campaign.
	 * @return int Other campaign ID, or 0.
	 */
	public function foreign_lock( $product_id, $campaign_id ) {
		$owner = (int) get_post_meta( (int) $product_id, '_dsd_campaign', true );
		if ( $owner <= 0 || $owner === (int) $campaign_id ) {
			return 0;
		}
		if ( ! isset( $this->live_cache[ $owner ] ) ) {
			$other                      = $this->find( $owner );
			$this->live_cache[ $owner ] = ( $other && $other->is_live() );
		}
		return $this->live_cache[ $owner ] ? $owner : 0;
	}
}

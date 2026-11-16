<?php
/**
 * Campaign value object.
 *
 * @package SmartBulkDiscount
 */

namespace SmartBulkDiscount;

defined( 'ABSPATH' ) || exit;

/**
 * One discount campaign.
 */
final class Campaign {

	// Lifecycle.
	const DRAFT            = 'draft';
	const SCHEDULED        = 'scheduled';
	const PREPARING        = 'preparing';        // building the product list.
	const APPLYING         = 'applying';         // writing sale prices.
	const VERIFYING        = 'verifying';        // re-reading the DB to confirm every write.
	const ACTIVE           = 'active';
	const REVERTING        = 'reverting';        // restoring original prices.
	const VERIFYING_REVERT = 'verifying_revert';
	const ENDED            = 'ended';
	const PAUSED           = 'paused';

	const TARGET_PRODUCTS   = 'products';
	const TARGET_CATEGORIES = 'categories';
	const TARGET_ALL        = 'all_in_stock';

	/** Statuses in which the worker has something to do. */
	const WORKING = array( 'preparing', 'applying', 'verifying', 'reverting', 'verifying_revert' );

	/** Statuses in which our prices may be live on products (product lock is honoured). */
	const LIVE = array( 'preparing', 'applying', 'verifying', 'active', 'reverting', 'verifying_revert', 'paused' );

	/** Statuses where the definition can still be edited. */
	const EDITABLE = array( 'draft', 'scheduled', 'ended' );

	/** @var int */
	public $id = 0;
	/** @var string */
	public $name = '';
	/** @var string */
	public $status = self::DRAFT;
	/** @var string */
	public $paused_status = '';
	/** @var string */
	public $discount_type = 'percent';
	/** @var float */
	public $discount_value = 0.0;
	/** @var string */
	public $target_type = self::TARGET_PRODUCTS;
	/** @var array */
	public $target = array();
	/** @var array */
	public $options = array();
	/** @var int|null UTC timestamp. */
	public $starts_at = null;
	/** @var int|null UTC timestamp. */
	public $ends_at = null;
	/** @var string */
	public $repeat_mode = 'none';
	/** @var int */
	public $cursor_id = 0;
	/** @var array */
	public $stats = array();
	/** @var array */
	public $log = array();
	/** @var int */
	public $run_count = 0;
	/** @var int */
	public $created_by = 0;
	/** @var int */
	public $created_at = 0;
	/** @var int */
	public $updated_at = 0;

	/**
	 * Default options.
	 *
	 * @return array
	 */
	public static function default_options() {
		return array(
			'only_in_stock'   => true,
			'sale_policy'     => PriceCalculator::POLICY_BEST,
			'round_step'      => 0,
			'min_price'       => 0,
			'include_children' => true,
		);
	}

	/**
	 * Default target.
	 *
	 * @return array
	 */
	public static function default_target() {
		return array(
			'product_ids'          => array(),
			'category_ids'         => array(),
			'exclude_product_ids'  => array(),
			'exclude_category_ids' => array(),
		);
	}

	/**
	 * Hydrate from a DB row.
	 *
	 * @param object $row Row.
	 * @return Campaign
	 */
	public static function from_row( $row ) {
		$c                 = new self();
		$c->id             = (int) $row->id;
		$c->name           = (string) $row->name;
		$c->status         = (string) $row->status;
		$c->paused_status  = (string) $row->paused_status;
		$c->discount_type  = (string) $row->discount_type;
		$c->discount_value = (float) $row->discount_value;
		$c->target_type    = (string) $row->target_type;
		$c->target         = array_merge( self::default_target(), self::decode( $row->target ) );
		$c->options        = array_merge( self::default_options(), self::decode( $row->options ) );
		$c->starts_at      = self::ts( $row->starts_at );
		$c->ends_at        = self::ts( $row->ends_at );
		$c->repeat_mode    = (string) $row->repeat_mode;
		$c->cursor_id      = (int) $row->cursor_id;
		$c->stats          = self::decode( $row->stats );
		$c->log            = self::decode( $row->log );
		$c->run_count      = (int) $row->run_count;
		$c->created_by     = (int) $row->created_by;
		$c->created_at     = (int) self::ts( $row->created_at );
		$c->updated_at     = (int) self::ts( $row->updated_at );
		return $c;
	}

	/**
	 * Is this campaign in a state where it may hold product locks?
	 *
	 * @return bool
	 */
	public function is_live() {
		return in_array( $this->status, self::LIVE, true );
	}

	/**
	 * Does the worker need to process it?
	 *
	 * @return bool
	 */
	public function is_working() {
		return in_array( $this->status, self::WORKING, true );
	}

	/**
	 * Shape for the REST API.
	 *
	 * @return array
	 */
	public function to_api() {
		return array(
			'id'             => $this->id,
			'name'           => $this->name,
			'status'         => $this->status,
			'paused_status'  => $this->paused_status,
			'discount_type'  => $this->discount_type,
			'discount_value' => $this->discount_value,
			'target_type'    => $this->target_type,
			'target'         => $this->target,
			'options'        => $this->options,
			'starts_at'      => $this->starts_at,
			'ends_at'        => $this->ends_at,
			'repeat_mode'    => $this->repeat_mode,
			'stats'          => array_merge( Repository::empty_stats(), $this->stats ),
			'log'            => array_slice( $this->log, -20 ),
			'run_count'      => $this->run_count,
			'created_at'     => $this->created_at,
			'updated_at'     => $this->updated_at,
		);
	}

	/**
	 * JSON → array.
	 *
	 * @param string|null $json JSON.
	 * @return array
	 */
	private static function decode( $json ) {
		if ( null === $json || '' === $json ) {
			return array();
		}
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * UTC DATETIME → timestamp.
	 *
	 * @param string|null $dt Datetime.
	 * @return int|null
	 */
	public static function ts( $dt ) {
		if ( null === $dt || '' === $dt || '0000-00-00 00:00:00' === $dt ) {
			return null;
		}
		$t = strtotime( $dt . ' UTC' );
		return false === $t ? null : $t;
	}

	/**
	 * Timestamp → UTC DATETIME.
	 *
	 * @param int|null $ts Timestamp.
	 * @return string|null
	 */
	public static function dt( $ts ) {
		return null === $ts ? null : gmdate( 'Y-m-d H:i:s', (int) $ts );
	}
}

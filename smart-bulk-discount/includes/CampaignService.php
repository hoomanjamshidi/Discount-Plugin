<?php
/**
 * Use-cases: create, edit, start, pause, resume, stop, retry, duplicate, delete.
 * Shared by the REST API and WP-CLI so both follow identical rules.
 *
 * @package SmartBulkDiscount
 */

namespace SmartBulkDiscount;

defined( 'ABSPATH' ) || exit;

/**
 * Campaign lifecycle commands.
 */
final class CampaignService {

	/** @var Repository */
	private $repo;
	/** @var TargetResolver */
	private $resolver;

	/**
	 * Constructor.
	 *
	 * @param Repository     $repo     Repo.
	 * @param TargetResolver $resolver Resolver.
	 */
	public function __construct( Repository $repo, TargetResolver $resolver ) {
		$this->repo     = $repo;
		$this->resolver = $resolver;
	}

	/**
	 * Build a Campaign from raw input (does not save).
	 *
	 * @param array         $in   Input.
	 * @param Campaign|null $base Existing campaign when editing.
	 * @return Campaign|\WP_Error
	 */
	public function from_input( array $in, $base = null ) {
		$c = $base ? clone $base : new Campaign();

		if ( isset( $in['name'] ) ) {
			$c->name = sanitize_text_field( wp_unslash( (string) $in['name'] ) );
		}
		if ( '' === trim( $c->name ) ) {
			$c->name = __( 'کمپین تخفیف', 'smart-bulk-discount' ); // Numbered after insert.
		}

		if ( isset( $in['discount_type'] ) ) {
			$c->discount_type = (string) $in['discount_type'];
		}
		if ( isset( $in['discount_value'] ) ) {
			$c->discount_value = (float) $in['discount_value'];
		}
		$err = PriceCalculator::validate( $c->discount_type, $c->discount_value );
		if ( '' !== $err ) {
			return new \WP_Error( 'dsd_' . $err, self::message( $err ), array( 'status' => 400 ) );
		}

		if ( isset( $in['target_type'] ) ) {
			$c->target_type = (string) $in['target_type'];
		}
		if ( ! in_array( $c->target_type, array( Campaign::TARGET_PRODUCTS, Campaign::TARGET_CATEGORIES, Campaign::TARGET_ALL ), true ) ) {
			return new \WP_Error( 'dsd_invalid_target', self::message( 'invalid_target' ), array( 'status' => 400 ) );
		}
		if ( isset( $in['target'] ) && is_array( $in['target'] ) ) {
			$target = Campaign::default_target();
			foreach ( array_keys( $target ) as $key ) {
				if ( isset( $in['target'][ $key ] ) ) {
					$target[ $key ] = TargetResolver::int_list( (array) $in['target'][ $key ] );
				}
			}
			$c->target = $target;
		}
		if ( Campaign::TARGET_PRODUCTS === $c->target_type && empty( $c->target['product_ids'] ) ) {
			return new \WP_Error( 'dsd_no_products', self::message( 'no_products' ), array( 'status' => 400 ) );
		}
		if ( Campaign::TARGET_CATEGORIES === $c->target_type && empty( $c->target['category_ids'] ) ) {
			return new \WP_Error( 'dsd_no_categories', self::message( 'no_categories' ), array( 'status' => 400 ) );
		}

		if ( isset( $in['options'] ) && is_array( $in['options'] ) ) {
			$o = array_merge( Campaign::default_options(), $c->options );
			if ( isset( $in['options']['only_in_stock'] ) ) {
				$o['only_in_stock'] = (bool) $in['options']['only_in_stock'];
			}
			if ( isset( $in['options']['include_children'] ) ) {
				$o['include_children'] = (bool) $in['options']['include_children'];
			}
			if ( isset( $in['options']['sale_policy'] ) && in_array( $in['options']['sale_policy'], array( 'best', 'override', 'keep' ), true ) ) {
				$o['sale_policy'] = $in['options']['sale_policy'];
			}
			if ( isset( $in['options']['round_step'] ) ) {
				$o['round_step'] = PriceCalculator::sanitize_step( $in['options']['round_step'] );
			}
			if ( isset( $in['options']['min_price'] ) ) {
				$o['min_price'] = max( 0, (float) $in['options']['min_price'] );
			}
			$c->options = $o;
		} elseif ( ! $base ) {
			$c->options               = Campaign::default_options();
			$c->options['round_step'] = (int) Settings::get( 'round_step' );
		}
		if ( Campaign::TARGET_ALL === $c->target_type ) {
			$c->options['only_in_stock'] = true;
		}

		// Schedule: local wall-clock strings in the site timezone ("2026-11-20 08:00"), or null.
		// An unreadable date is an error — it must never silently become "now" / "no end".
		foreach ( array( 'starts_at', 'ends_at' ) as $key ) {
			if ( array_key_exists( $key, $in ) ) {
				$ts = self::parse_local( $in[ $key ] );
				if ( false === $ts ) {
					return new \WP_Error( 'dsd_invalid_date', self::message( 'invalid_date' ), array( 'status' => 400 ) );
				}
				$c->$key = $ts;
			}
		}
		if ( isset( $in['repeat_mode'] ) ) {
			$c->repeat_mode = (string) $in['repeat_mode'];
		}
		$err = Schedule::validate( $c->starts_at, $c->ends_at, $c->repeat_mode, time() );
		if ( '' !== $err ) {
			return new \WP_Error( 'dsd_' . $err, self::message( $err ), array( 'status' => 400 ) );
		}
		return $c;
	}

	/**
	 * Create (and optionally start).
	 *
	 * @param array $in Input; `start` => true to launch immediately/schedule.
	 * @return Campaign|\WP_Error
	 */
	public function create( array $in ) {
		$c = $this->from_input( $in );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		$c->status = Campaign::DRAFT;
		$c->id     = $this->repo->insert( $c );
		if ( empty( $in['name'] ) || '' === trim( (string) $in['name'] ) ) {
			$this->repo->patch( $c->id, array( 'name' => $c->name . ' ' . self::fa_digits( '#' . $c->id ) ) );
		}
		$this->repo->log( $c->id, 'created', array() );
		if ( ! empty( $in['start'] ) ) {
			return $this->start( $c->id );
		}
		return $this->repo->find( $c->id );
	}

	/**
	 * Edit definition (only draft/scheduled/ended).
	 *
	 * @param int   $id ID.
	 * @param array $in Input.
	 * @return Campaign|\WP_Error
	 */
	public function update( $id, array $in ) {
		$c = $this->repo->find( $id );
		if ( ! $c ) {
			return self::not_found();
		}
		if ( ! in_array( $c->status, Campaign::EDITABLE, true ) ) {
			return new \WP_Error( 'dsd_not_editable', self::message( 'not_editable' ), array( 'status' => 409 ) );
		}
		$was_scheduled = Campaign::SCHEDULED === $c->status;
		Queue::clear_timers( $c );
		$updated = $this->from_input( $in, $c );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		$this->repo->save( $updated );
		$this->repo->log( $id, 'edited', array() );
		if ( $was_scheduled ) {
			// Re-arm with the new times.
			$this->repo->transition( $id, Campaign::SCHEDULED, Campaign::DRAFT );
			return $this->start( $id );
		}
		return $this->repo->find( $id );
	}

	/**
	 * Launch: schedule for later, or begin now.
	 *
	 * @param int $id ID.
	 * @return Campaign|\WP_Error
	 */
	public function start( $id ) {
		$c = $this->repo->find( $id );
		if ( ! $c ) {
			return self::not_found();
		}
		if ( ! in_array( $c->status, array( Campaign::DRAFT, Campaign::ENDED ), true ) ) {
			return new \WP_Error( 'dsd_bad_state', self::message( 'bad_state' ), array( 'status' => 409 ) );
		}
		$now = time();
		if ( Campaign::ENDED === $c->status ) {
			// Re-running an ended campaign: it needs a fresh, future window.
			if ( null !== $c->ends_at && $c->ends_at <= $now ) {
				return new \WP_Error( 'dsd_end_in_past', self::message( 'end_in_past' ), array( 'status' => 400 ) );
			}
			$this->repo->clear_items( $id );
		}
		$err = Schedule::validate( $c->starts_at, $c->ends_at, $c->repeat_mode, $now );
		if ( '' !== $err ) {
			return new \WP_Error( 'dsd_' . $err, self::message( $err ), array( 'status' => 400 ) );
		}

		$stats = $c->stats;
		unset( $stats['stopped_manually'] );

		if ( null !== $c->starts_at && $c->starts_at > $now ) {
			$this->repo->transition( $id, $c->status, Campaign::SCHEDULED, array( 'cursor_id' => 0, 'stats' => $stats ) );
			Queue::schedule_timer( $id, $c->starts_at );
			$this->repo->log( $id, 'scheduled', array( 'starts_at' => $c->starts_at ) );
		} else {
			$this->repo->transition(
				$id,
				$c->status,
				Campaign::PREPARING,
				array(
					'cursor_id' => 0,
					'starts_at' => Campaign::dt( null === $c->starts_at ? $now : $c->starts_at ),
					'stats'     => $stats,
				)
			);
			$this->repo->log( $id, 'started', array() );
			Queue::schedule_pump( 0 );
			Queue::kick();
		}
		if ( null !== $c->ends_at ) {
			Queue::schedule_timer( $id, $c->ends_at );
		}
		Queue::ensure_watchdog( true );
		return $this->repo->find( $id );
	}

	/**
	 * Pause a running job (prices already written stay; end time still wins).
	 *
	 * @param int $id ID.
	 * @return Campaign|\WP_Error
	 */
	public function pause( $id ) {
		$c = $this->repo->find( $id );
		if ( ! $c ) {
			return self::not_found();
		}
		if ( ! in_array( $c->status, array( Campaign::PREPARING, Campaign::APPLYING, Campaign::VERIFYING ), true ) ) {
			return new \WP_Error( 'dsd_bad_state', self::message( 'bad_state' ), array( 'status' => 409 ) );
		}
		$this->repo->transition( $id, $c->status, Campaign::PAUSED, array( 'paused_status' => $c->status ) );
		$this->repo->log( $id, 'paused', array() );
		return $this->repo->find( $id );
	}

	/**
	 * Resume a paused job.
	 *
	 * @param int $id ID.
	 * @return Campaign|\WP_Error
	 */
	public function resume( $id ) {
		$c = $this->repo->find( $id );
		if ( ! $c ) {
			return self::not_found();
		}
		if ( Campaign::PAUSED !== $c->status ) {
			return new \WP_Error( 'dsd_bad_state', self::message( 'bad_state' ), array( 'status' => 409 ) );
		}
		$to = $c->paused_status ? $c->paused_status : Campaign::APPLYING;
		$this->repo->transition( $id, Campaign::PAUSED, $to, array( 'paused_status' => '' ) );
		$this->repo->log( $id, 'resumed', array() );
		Queue::schedule_pump( 0 );
		Queue::kick();
		return $this->repo->find( $id );
	}

	/**
	 * Stop: cancel a scheduled campaign, or end a live one now (prices are restored).
	 *
	 * @param int $id ID.
	 * @return Campaign|\WP_Error
	 */
	public function stop( $id ) {
		$c = $this->repo->find( $id );
		if ( ! $c ) {
			return self::not_found();
		}
		if ( Campaign::SCHEDULED === $c->status ) {
			Queue::clear_timers( $c );
			$this->repo->transition( $id, Campaign::SCHEDULED, Campaign::DRAFT );
			$this->repo->log( $id, 'unscheduled', array() );
			return $this->repo->find( $id );
		}
		if ( ! in_array( $c->status, array( Campaign::PREPARING, Campaign::APPLYING, Campaign::VERIFYING, Campaign::ACTIVE, Campaign::PAUSED ), true ) ) {
			return new \WP_Error( 'dsd_bad_state', self::message( 'bad_state' ), array( 'status' => 409 ) );
		}
		Queue::clear_timers( $c );
		Watchdog::begin_revert( $this->repo, $c, 'stopped' );
		Queue::schedule_pump( 0 );
		Queue::kick();
		return $this->repo->find( $id );
	}

	/**
	 * Give failed products another try.
	 *
	 * @param int $id ID.
	 * @return Campaign|\WP_Error
	 */
	public function retry( $id ) {
		$c = $this->repo->find( $id );
		if ( ! $c ) {
			return self::not_found();
		}
		if ( Campaign::ACTIVE !== $c->status ) {
			return new \WP_Error( 'dsd_bad_state', self::message( 'bad_state' ), array( 'status' => 409 ) );
		}
		$n = $this->repo->retry_failed( $id );
		if ( $n > 0 ) {
			$this->repo->transition( $id, Campaign::ACTIVE, Campaign::APPLYING, array( 'cursor_id' => 0 ) );
			$this->repo->log( $id, 'retry', array( 'count' => $n ) );
			Queue::schedule_pump( 0 );
			Queue::kick();
		}
		$this->repo->refresh_stats( $id );
		return $this->repo->find( $id );
	}

	/**
	 * Copy as a new draft.
	 *
	 * @param int $id ID.
	 * @return Campaign|\WP_Error
	 */
	public function duplicate( $id ) {
		$c = $this->repo->find( $id );
		if ( ! $c ) {
			return self::not_found();
		}
		$copy            = clone $c;
		$copy->id        = 0;
		$copy->status    = Campaign::DRAFT;
		$copy->name      = $c->name . ' ' . __( '(کپی)', 'smart-bulk-discount' );
		$copy->starts_at = null;
		$copy->ends_at   = ( null !== $c->ends_at && $c->ends_at > time() ) ? $c->ends_at : null;
		$copy->repeat_mode = null === $copy->ends_at ? Schedule::REPEAT_NONE : $c->repeat_mode;
		if ( Schedule::REPEAT_NONE !== $copy->repeat_mode ) {
			$copy->repeat_mode = Schedule::REPEAT_NONE;
		}
		$copy->id = $this->repo->insert( $copy );
		$this->repo->log( $copy->id, 'created', array( 'from' => $id ) );
		return $this->repo->find( $copy->id );
	}

	/**
	 * Delete (never while prices may be live).
	 *
	 * @param int $id ID.
	 * @return true|\WP_Error
	 */
	public function delete( $id ) {
		$c = $this->repo->find( $id );
		if ( ! $c ) {
			return self::not_found();
		}
		if ( $c->is_live() ) {
			return new \WP_Error( 'dsd_live', self::message( 'live' ), array( 'status' => 409 ) );
		}
		Queue::clear_timers( $c );
		$this->repo->delete( $id );
		return true;
	}

	/**
	 * Preview a target: how many products, plus a few example prices.
	 *
	 * @param array $in Input (same shape as create).
	 * @return array|\WP_Error
	 */
	public function preview( array $in ) {
		$in['starts_at'] = null;
		$in['ends_at']   = null;
		$in['repeat_mode'] = 'none';
		$c = $this->from_input( $in );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		$count   = $this->resolver->count( $c );
		$samples = array();
		foreach ( $this->resolver->next_ids( $c, 0, 6 ) as $row ) {
			$p = wc_get_product( $row[0] );
			if ( ! $p ) {
				continue;
			}
			$regular   = (float) $p->get_regular_price( 'edit' );
			$new       = PriceCalculator::calculate(
				$regular,
				$c->discount_type,
				$c->discount_value,
				array(
					'round_step' => $c->options['round_step'],
					'decimals'   => wc_get_price_decimals(),
					'min_price'  => $c->options['min_price'],
				)
			);
			$samples[] = array(
				'id'      => $p->get_id(),
				'name'    => wp_strip_all_tags( $p->get_name() ),
				'regular' => $regular,
				'sale'    => '' === $p->get_sale_price( 'edit' ) ? null : (float) $p->get_sale_price( 'edit' ),
				'new'     => $new,
			);
		}
		$conflicts = $this->count_conflicts( $c );
		return array(
			'count'     => $count,
			'samples'   => $samples,
			'conflicts' => $conflicts,
		);
	}

	/**
	 * How many matching products are locked by another live campaign (approximate, sampled up to 5000).
	 *
	 * @param Campaign $c Campaign.
	 * @return int
	 */
	private function count_conflicts( Campaign $c ) {
		global $wpdb;
		$live = $this->repo->by_status( Campaign::LIVE );
		if ( empty( $live ) ) {
			return 0;
		}
		$ids = wp_list_pluck( $this->resolver->next_ids( $c, 0, 5000 ), 0 );
		if ( empty( $ids ) ) {
			return 0;
		}
		$live_ids = implode( ',', array_map( 'intval', wp_list_pluck( $live, 'id' ) ) );
		$list     = implode( ',', array_map( 'intval', $ids ) );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_dsd_campaign' AND post_id IN ({$list}) AND meta_value IN ({$live_ids})" ); // phpcs:ignore
	}

	/**
	 * Parse "Y-m-d H:i" in the site timezone (or a unix timestamp) → UTC timestamp.
	 *
	 * @param mixed $value Value.
	 * @return int|null|false Null = no date, false = unreadable.
	 */
	public static function parse_local( $value ) {
		if ( is_string( $value ) ) {
			$value = sanitize_text_field( $value );
		}
		return Clock::parse( $value );
	}

	/**
	 * Latin → Persian digits.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	public static function fa_digits( $s ) {
		return strtr( (string) $s, array( '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹' ) );
	}

	/**
	 * Not found error.
	 *
	 * @return \WP_Error
	 */
	private static function not_found() {
		return new \WP_Error( 'dsd_not_found', self::message( 'not_found' ), array( 'status' => 404 ) );
	}

	/**
	 * Persian, human messages for error codes.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function message( $code ) {
		$m = array(
			'invalid_type'               => __( 'نوع تخفیف نامعتبر است.', 'smart-bulk-discount' ),
			'value_must_be_positive'     => __( 'مقدار تخفیف باید بیشتر از صفر باشد.', 'smart-bulk-discount' ),
			'percent_must_be_below_100'  => __( 'درصد تخفیف باید کمتر از ۱۰۰ باشد.', 'smart-bulk-discount' ),
			'invalid_target'             => __( 'محدودهٔ محصولات نامعتبر است.', 'smart-bulk-discount' ),
			'no_products'                => __( 'حداقل یک محصول انتخاب کنید.', 'smart-bulk-discount' ),
			'no_categories'              => __( 'حداقل یک دسته‌بندی انتخاب کنید.', 'smart-bulk-discount' ),
			'invalid_repeat'             => __( 'حالت تکرار نامعتبر است.', 'smart-bulk-discount' ),
			'end_before_start'           => __( 'زمان پایان باید بعد از زمان شروع باشد.', 'smart-bulk-discount' ),
			'invalid_date'               => __( 'تاریخ واردشده قابل خواندن نیست. دوباره از تقویم انتخاب کنید.', 'smart-bulk-discount' ),
			'end_in_past'                => __( 'زمان پایان گذشته است. یک زمان در آینده انتخاب کنید.', 'smart-bulk-discount' ),
			'repeat_needs_start_and_end' => __( 'برای تکرار، زمان شروع و پایان هر دو لازم است.', 'smart-bulk-discount' ),
			'repeat_window_too_long'     => __( 'بازهٔ تخفیف از فاصلهٔ تکرار طولانی‌تر است.', 'smart-bulk-discount' ),
			'not_found'                  => __( 'کمپین پیدا نشد.', 'smart-bulk-discount' ),
			'not_editable'               => __( 'کمپین در حال اجراست؛ اول آن را متوقف کنید.', 'smart-bulk-discount' ),
			'bad_state'                  => __( 'این کار در وضعیت فعلی کمپین ممکن نیست.', 'smart-bulk-discount' ),
			'live'                       => __( 'کمپین فعال است؛ اول آن را متوقف کنید تا قیمت‌ها برگردند.', 'smart-bulk-discount' ),
		);
		return isset( $m[ $code ] ) ? $m[ $code ] : $code;
	}
}

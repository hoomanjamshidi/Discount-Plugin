<?php
/**
 * The worker. One call to pump() = one governed batch.
 *
 * Phases per campaign:
 *   preparing → applying → verifying → active → reverting → verifying_revert → ended
 *                    ↑__________|  (mismatches go back to applying, max 3 attempts per product)
 *
 * Invariants (see CLAUDE.md):
 *  - Original prices are written to dsd_items BEFORE a product is touched.
 *  - Every step is idempotent: a batch may die at any line and be re-run.
 *  - Only one batch runs site-wide at a time (Lock 'worker').
 *
 * @package SmartBulkDiscount
 */

namespace SmartBulkDiscount;

defined( 'ABSPATH' ) || exit;

/**
 * Batch processor.
 */
final class Engine {

	const PREPARE_PAGE = 500;
	const VERIFY_PAGE  = 500;
	const ITEM_PAGE    = 25;

	/** Seconds to wait after a batch that died or threw. */
	const CRASH_COOLDOWN = 30;

	/** @var Repository */
	private $repo;
	/** @var TargetResolver */
	private $resolver;
	/** @var Environment */
	private $env;

	/**
	 * Args of the batch currently running in this request (null = none). Read by the
	 * shutdown handler to tell a fatal error mid-batch from a normal end.
	 *
	 * @var array|null
	 */
	private $running = null;

	/** @var bool */
	private $shutdown_registered = false;

	/** @var array|null Item being written right now (see mark_inflight()). */
	private $inflight = null;

	/**
	 * Constructor.
	 *
	 * @param Repository     $repo     Repo.
	 * @param TargetResolver $resolver Resolver.
	 * @param Environment    $env      Env.
	 */
	public function __construct( Repository $repo, TargetResolver $resolver, Environment $env ) {
		$this->repo     = $repo;
		$this->resolver = $resolver;
		$this->env      = $env;
	}

	/**
	 * Run one governed batch.
	 *
	 * @param array $args max_seconds (float|null), source (string), chain (bool), token (loopback chain id).
	 * @return array Result summary.
	 */
	public function pump( array $args = array() ) {
		$args = array_merge(
			array(
				'max_seconds' => null,
				'source'      => 'queue',
				'chain'       => true,
				'token'       => null,
			),
			$args
		);

		$kick   = null;
		$result = null;

		// Site-wide rest between batches. Every runner (loopback, Action Scheduler, WP-Cron,
		// admin page, CLI) respects it, so the pace holds however many of them poke us.
		// (The WP-CLI runner rests by itself between its batches, see Cli::run().)
		$wait = 'cli' === $args['source'] ? 0 : self::cooling_left();
		if ( $wait > 0 ) {
			if ( $args['chain'] ) {
				Queue::schedule_pump( $wait );
			}
			return array(
				'ran'    => false,
				'reason' => 'cooling',
				'wait'   => $wait,
			);
		}

		if ( 'cli' === $args['source'] ) {
			// Each CLI batch behaves like its own request (fresh budget, fresh PHP timer).
			$limit = (int) ini_get( 'max_execution_time' );
			if ( $limit > 0 && function_exists( 'set_time_limit' ) ) {
				@set_time_limit( $limit ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
			$this->env->restart_clock();
		}

		if ( ! Lock::acquire( 'worker', 120 ) ) {
			return array(
				'ran'    => false,
				'reason' => 'busy',
			);
		}

		if ( ! $this->shutdown_registered ) {
			register_shutdown_function( array( $this, 'on_shutdown' ) );
			$this->shutdown_registered = true;
		}

		try {
			// Pessimistic rest: if this request dies (fatal error, killed by the host), the
			// next runner waits instead of hammering the same failure in a tight loop.
			update_option( 'dsd_not_before', time() + self::CRASH_COOLDOWN, false );
			$this->running = $args;
			$result        = $this->run_locked( $args, $kick );
			$this->running = null;
		} catch ( \Throwable $e ) {
			$this->running = null;
			update_option(
				'dsd_last_skip',
				array(
					't'      => time(),
					'reason' => 'error',
					'source' => (string) $args['source'],
					'note'   => substr( $e->getMessage(), 0, 300 ),
				),
				false
			);
			if ( $args['chain'] ) {
				Queue::schedule_pump( self::CRASH_COOLDOWN );
			}
			$result = array(
				'ran'    => false,
				'reason' => 'error',
				'wait'   => self::CRASH_COOLDOWN,
			);
		} finally {
			CacheCompat::resume();
			Lock::release( 'worker' );
		}

		// Only after the lock is released, so the next request can take it.
		if ( null !== $kick ) {
			Queue::kick( $kick, $args['token'] );
		}
		return $result;
	}

	/**
	 * The body of pump(), run while holding the worker lock.
	 *
	 * @param array    $args Pump args.
	 * @param int|null $kick Out: loopback delay to fire after the lock is released.
	 * @return array
	 */
	private function run_locked( array $args, &$kick ) {
		Watchdog::check_times( $this->repo );
		$this->recover_inflight();

		$campaign = $this->repo->next_working();
		if ( ! $campaign ) {
			delete_option( 'dsd_not_before' );
			return array(
				'ran'    => false,
				'reason' => 'idle',
			);
		}

		$settings = Settings::all();
		$governor = new ResourceGovernor(
			$this->env,
			$settings['profile'],
			(array) get_option( 'dsd_governor', array() ),
			! empty( $settings['pause_on_high_load'] ),
			$args['max_seconds']
		);

		$pre = $governor->preflight();
		if ( ! $pre['ok'] ) {
			update_option( 'dsd_not_before', time() + min( 30, (int) $pre['wait'] ), false );
			if ( $args['chain'] ) {
				Queue::schedule_pump( max( 5, $pre['wait'] ) );
				// A fresh request starts with a full time/memory budget.
				$kick = min( Queue::max_kick_delay(), max( 1, $pre['wait'] ) );
			}
			update_option(
				'dsd_last_skip',
				array(
					't'      => time(),
					'reason' => $pre['reason'],
					'source' => (string) $args['source'],
				),
				false
			);
			return array(
				'ran'    => false,
				'reason' => $pre['reason'],
				'wait'   => (int) $pre['wait'],
			);
		}

		$governor->begin();

		// Page-cache plugins must not purge (and re-crawl) the site for every product we
		// write; CacheCompat purges once per phase instead. Restored in pump()'s finally.
		CacheCompat::suspend();

		$worked = array();
		// Keep going across campaigns/phases while the budget allows.
		while ( $campaign && ! $governor->should_yield() ) {
			$before = $campaign->status;
			$this->run_phase( $campaign, $governor );
			$worked[ $campaign->id ] = true;
			$campaign                = $this->repo->next_working();
			if ( $campaign && isset( $worked[ $campaign->id ] ) && $campaign->status === $before ) {
				break; // Same phase still has work but we yielded inside it.
			}
		}

		$summary           = $governor->finish();
		$cooldown          = $governor->cooldown();
		$summary['source'] = (string) $args['source'];
		$summary['cool']   = $cooldown;
		$state             = $governor->state();
		$state['history'][ count( $state['history'] ) - 1 ] = $summary;
		update_option( 'dsd_governor', $state, false );
		update_option( 'dsd_heartbeat', time(), false );
		update_option( 'dsd_not_before', time() + $cooldown, false );

		foreach ( array_keys( $worked ) as $id ) {
			$this->repo->refresh_stats(
				$id,
				array(
					'last_batch' => $summary,
				)
			);
		}

		$more = null !== $this->repo->next_working();
		if ( ! $more ) {
			delete_option( 'dsd_not_before' );
		} elseif ( $args['chain'] ) {
			// Backup path: Action Scheduler / WP-Cron (needs site traffic or a real cron).
			Queue::schedule_pump( $cooldown );
			// Fast path: the next loopback request waits out the cooldown itself (sleeping,
			// no CPU), so the chain does not depend on site traffic. A long cooldown is
			// covered by several short waits (the request re-arms itself while cooling).
			// Without a way to sleep, a loopback request could not wait out the rest: leave it
			// to the scheduler so batches never run back to back.
			$kick = $this->env->can_pause() ? min( $cooldown, Queue::max_kick_delay() ) : null;
		}

		return array(
			'ran'     => true,
			'more'    => $more,
			'summary' => $summary,
		);
	}

	/**
	 * A request that died while working on one product (fatal error, out of memory, killed
	 * by the host) must not crash on that same product forever: count it as a failed attempt.
	 */
	private function recover_inflight() {
		$inflight = get_option( 'dsd_inflight', null );
		if ( ! is_array( $inflight ) || empty( $inflight['item'] ) ) {
			return;
		}
		delete_option( 'dsd_inflight' );
		$item = $this->repo->find_item_by_id( (int) $inflight['item'] );
		if ( ! $item || ! in_array( $item->status, array( Repository::ITEM_PENDING, Repository::ITEM_APPLIED ), true ) ) {
			return;
		}
		$expected = 'revert' === $inflight['phase'] ? Repository::ITEM_APPLIED : Repository::ITEM_PENDING;
		if ( $item->status !== $expected ) {
			return; // It finished after all.
		}
		$attempts = (int) $item->attempts + 1;
		$this->repo->update_item(
			$item->id,
			array(
				'status'   => $attempts >= Repository::MAX_ATTEMPTS ? Repository::ITEM_FAILED : $item->status,
				'attempts' => $attempts,
				'note'     => 'crashed',
			)
		);
	}

	/**
	 * Remember (in memory) which item is being written. Only if PHP dies mid-item does the
	 * shutdown handler store it for recover_inflight() — no extra database write per product.
	 *
	 * @param object $item  Item.
	 * @param string $phase apply|revert.
	 */
	private function mark_inflight( $item, $phase ) {
		$this->inflight = array(
			'item'  => (int) $item->id,
			'phase' => $phase,
		);
	}

	/**
	 * Shutdown handler: if PHP died in the middle of a batch (fatal error from another
	 * plugin's hook, out of memory, max_execution_time), keep the work alive: record what
	 * happened for the System page and re-arm the runners after the crash cooldown.
	 * (A process killed outright never gets here; the watchdog covers that case.)
	 */
	public function on_shutdown() {
		if ( null === $this->running ) {
			return;
		}
		$args          = $this->running;
		$this->running = null;
		try {
			if ( null !== $this->inflight ) {
				update_option( 'dsd_inflight', $this->inflight, false );
			}
			$err = error_get_last();
			update_option(
				'dsd_last_skip',
				array(
					't'      => time(),
					'reason' => 'crashed',
					'source' => (string) $args['source'],
					'note'   => $err ? substr( $err['message'], 0, 300 ) : '',
				),
				false
			);
			if ( ! empty( $args['chain'] ) ) {
				Queue::schedule_pump( self::CRASH_COOLDOWN );
				Queue::kick( Queue::max_kick_delay(), $args['token'] );
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
			// Nothing else can be done in a dying request; the watchdog will restart the work.
		}
	}

	/**
	 * Seconds left of the rest between batches (0 = may run).
	 *
	 * @return int
	 */
	public static function cooling_left() {
		$until = (int) Queue::db_option( 'dsd_not_before', 0 );
		$left  = $until - time();
		// A clock jump (or a stale value) never blocks for more than a minute.
		return $left > 60 ? 60 : max( 0, $left );
	}

	/**
	 * Run the current phase of one campaign until it finishes or the governor says stop.
	 *
	 * @param Campaign         $c Campaign.
	 * @param ResourceGovernor $g Governor.
	 */
	private function run_phase( Campaign $c, ResourceGovernor $g ) {
		switch ( $c->status ) {
			case Campaign::PREPARING:
				$this->prepare( $c, $g );
				break;
			case Campaign::APPLYING:
				$this->apply( $c, $g );
				break;
			case Campaign::VERIFYING:
				$this->verify_apply( $c, $g );
				break;
			case Campaign::REVERTING:
				$this->revert( $c, $g );
				break;
			case Campaign::VERIFYING_REVERT:
				$this->verify_revert( $c, $g );
				break;
		}
		$this->inflight = null;
	}

	// ------------------------------------------------------------------
	// Phases.
	// ------------------------------------------------------------------

	/**
	 * Build the item list (snapshot of matching products).
	 *
	 * @param Campaign         $c Campaign.
	 * @param ResourceGovernor $g Governor.
	 */
	private function prepare( Campaign $c, ResourceGovernor $g ) {
		$cursor = (int) $c->cursor_id;
		while ( true ) {
			$chunk = $this->resolver->next_chunk( $c, $cursor, self::PREPARE_PAGE );
			if ( ! empty( $chunk['rows'] ) ) {
				$this->repo->add_items( $c->id, $chunk['rows'] );
			}
			if ( $chunk['cursor'] > $cursor ) {
				$cursor = (int) $chunk['cursor'];
				$this->repo->patch( $c->id, array( 'cursor_id' => $cursor ) );
			}
			if ( $chunk['done'] ) {
				$counts = $this->repo->counts( $c->id );
				$this->repo->transition( $c->id, Campaign::PREPARING, Campaign::APPLYING, array( 'cursor_id' => 0 ) );
				$this->repo->log( $c->id, 'prepared', array( 'total' => $counts['total'] ) );
				return;
			}
			$g->tick( false );
			if ( $g->should_yield() ) {
				return;
			}
			$g->pace();
		}
	}

	/**
	 * Write sale prices.
	 *
	 * @param Campaign         $c Campaign.
	 * @param ResourceGovernor $g Governor.
	 */
	private function apply( Campaign $c, ResourceGovernor $g ) {
		$after = 0;
		while ( true ) {
			$items = $this->repo->items_in_status( $c->id, Repository::ITEM_PENDING, self::ITEM_PAGE, $after );
			if ( empty( $items ) ) {
				if ( 0 === $after || 0 === count( $this->repo->items_in_status( $c->id, Repository::ITEM_PENDING, 1, 0 ) ) ) {
					$this->repo->transition( $c->id, Campaign::APPLYING, Campaign::VERIFYING, array( 'cursor_id' => 0 ) );
				}
				return;
			}
			foreach ( $items as $item ) {
				$after = (int) $item->id;
				$this->mark_inflight( $item, 'apply' );
				$this->apply_item( $c, $item );
				$g->tick();
				$this->maybe_free_memory( $g );
				if ( $g->should_yield() ) {
					return;
				}
				$g->pace();
			}
		}
	}

	/**
	 * Re-read the DB and confirm every applied product really carries our price.
	 *
	 * @param Campaign         $c Campaign.
	 * @param ResourceGovernor $g Governor.
	 */
	private function verify_apply( Campaign $c, ResourceGovernor $g ) {
		global $wpdb;
		$cursor = $c->cursor_id;
		$items  = Repository::items_table();
		$lookup = $wpdb->prefix . 'wc_product_meta_lookup';
		while ( true ) {
			$rows = $wpdb->get_results( // phpcs:ignore
				$wpdb->prepare(
					"SELECT i.id, i.product_id, i.new_sale, i.attempts, s.meta_value AS sale, pr.meta_value AS price, l.meta_value AS owner,
						lk.min_price AS lk_min, lk.max_price AS lk_max, lk.onsale AS lk_onsale
					FROM {$items} i
					LEFT JOIN {$lookup} lk ON lk.product_id = i.product_id
					LEFT JOIN {$wpdb->postmeta} s  ON s.post_id = i.product_id AND s.meta_key = '_sale_price'
					LEFT JOIN {$wpdb->postmeta} pr ON pr.post_id = i.product_id AND pr.meta_key = '_price'
					LEFT JOIN {$wpdb->postmeta} l  ON l.post_id = i.product_id AND l.meta_key = '_dsd_campaign'
					WHERE i.campaign_id = %d AND i.status = 'applied' AND i.id > %d
					ORDER BY i.id ASC LIMIT %d", // phpcs:ignore
					$c->id,
					$cursor,
					self::VERIFY_PAGE
				)
			);

			if ( empty( $rows ) ) {
				$pending = count( $this->repo->items_in_status( $c->id, Repository::ITEM_PENDING, 1, 0 ) );
				if ( $pending > 0 ) {
					$this->repo->transition( $c->id, Campaign::VERIFYING, Campaign::APPLYING, array( 'cursor_id' => 0 ) );
					$this->repo->log( $c->id, 'verify_retry', array( 'pending' => $pending ) );
				} else {
					$counts = $this->repo->counts( $c->id );
					$this->repo->transition( $c->id, Campaign::VERIFYING, Campaign::ACTIVE, array( 'cursor_id' => 0 ) );
					$this->repo->log( $c->id, 'activated', $counts );
					do_action( 'dsd_campaign_activated', $c->id, $counts );
				}
				return;
			}

			foreach ( $rows as $r ) {
				$cursor = (int) $r->id;
				$ok     = PriceCalculator::same( $r->sale, $r->new_sale )
					&& PriceCalculator::same( $r->price, $r->new_sale )
					&& (int) $r->owner === $c->id;
				if ( $ok ) {
					$this->repair_lookup( $r, $r->new_sale, true );
				}
				if ( ! $ok ) {
					$attempts = (int) $r->attempts + 1;
					$this->repo->update_item(
						$r->id,
						array(
							'status'   => $attempts >= Repository::MAX_ATTEMPTS ? Repository::ITEM_FAILED : Repository::ITEM_PENDING,
							'attempts' => $attempts,
							'note'     => 'verify_mismatch',
						)
					);
				}
			}
			$this->repo->patch( $c->id, array( 'cursor_id' => $cursor ) );
			$g->tick( false );
			if ( $g->should_yield() ) {
				return;
			}
			$g->pace();
		}
	}

	/**
	 * Restore original prices.
	 *
	 * @param Campaign         $c Campaign.
	 * @param ResourceGovernor $g Governor.
	 */
	private function revert( Campaign $c, ResourceGovernor $g ) {
		// Items never touched are simply cancelled; pending items that already have a backup
		// (a write was interrupted) are checked by the revert like applied ones.
		$this->repo->pending_to_revert( $c->id );

		$after = 0;
		while ( true ) {
			$items = $this->repo->items_in_status( $c->id, Repository::ITEM_APPLIED, self::ITEM_PAGE, $after );
			if ( empty( $items ) ) {
				if ( 0 === $after || 0 === count( $this->repo->items_in_status( $c->id, Repository::ITEM_APPLIED, 1, 0 ) ) ) {
					$this->repo->transition( $c->id, Campaign::REVERTING, Campaign::VERIFYING_REVERT, array( 'cursor_id' => 0 ) );
				}
				return;
			}
			foreach ( $items as $item ) {
				$after = (int) $item->id;
				$this->mark_inflight( $item, 'revert' );
				$this->revert_item( $c, $item );
				$g->tick();
				$this->maybe_free_memory( $g );
				if ( $g->should_yield() ) {
					return;
				}
				$g->pace();
			}
		}
	}

	/**
	 * Confirm every reverted product lost our price; then end or schedule the next repeat.
	 *
	 * @param Campaign         $c Campaign.
	 * @param ResourceGovernor $g Governor.
	 */
	private function verify_revert( Campaign $c, ResourceGovernor $g ) {
		global $wpdb;
		$cursor = $c->cursor_id;
		$items  = Repository::items_table();
		$lookup = $wpdb->prefix . 'wc_product_meta_lookup';
		while ( true ) {
			$rows = $wpdb->get_results( // phpcs:ignore
				$wpdb->prepare(
					"SELECT i.id, i.product_id, i.new_sale, i.orig_sale, i.attempts, s.meta_value AS sale, pr.meta_value AS price, l.meta_value AS owner,
						lk.min_price AS lk_min, lk.max_price AS lk_max, lk.onsale AS lk_onsale
					FROM {$items} i
					LEFT JOIN {$lookup} lk ON lk.product_id = i.product_id
					LEFT JOIN {$wpdb->postmeta} s  ON s.post_id = i.product_id AND s.meta_key = '_sale_price'
					LEFT JOIN {$wpdb->postmeta} pr ON pr.post_id = i.product_id AND pr.meta_key = '_price'
					LEFT JOIN {$wpdb->postmeta} l  ON l.post_id = i.product_id AND l.meta_key = '_dsd_campaign'
					WHERE i.campaign_id = %d AND i.status = 'reverted' AND i.id > %d
					ORDER BY i.id ASC LIMIT %d", // phpcs:ignore
					$c->id,
					$cursor,
					self::VERIFY_PAGE
				)
			);

			if ( empty( $rows ) ) {
				$left = count( $this->repo->items_in_status( $c->id, Repository::ITEM_APPLIED, 1, 0 ) );
				if ( $left > 0 ) {
					$this->repo->transition( $c->id, Campaign::VERIFYING_REVERT, Campaign::REVERTING, array( 'cursor_id' => 0 ) );
					return;
				}
				$this->finish_cycle( $c );
				return;
			}

			foreach ( $rows as $r ) {
				$cursor     = (int) $r->id;
				$still_ours = (int) $r->owner === $c->id && PriceCalculator::same( $r->sale, $r->new_sale );
				if ( ! $still_ours && PriceCalculator::same( $r->price, $r->new_sale ) && ! PriceCalculator::same( $r->sale, $r->new_sale ) ) {
					// Sale price restored but the active price is still ours (interrupted save): repair.
					$p = wc_get_product( (int) $r->product_id );
					if ( $p ) {
						$this->ensure_active_price( $p );
						$r->price = get_post_meta( $p->get_id(), '_price', true );
					}
				}
				if ( ! $still_ours && '' !== (string) $r->price ) {
					$on_sale = '' !== (string) $r->sale && PriceCalculator::same( $r->sale, $r->price );
					$this->repair_lookup( $r, $r->price, $on_sale );
				}
				if ( $still_ours ) {
					$attempts = (int) $r->attempts + 1;
					$this->repo->update_item(
						$r->id,
						array(
							'status'   => $attempts >= Repository::MAX_ATTEMPTS ? Repository::ITEM_FAILED : Repository::ITEM_APPLIED,
							'attempts' => $attempts,
							'note'     => 'revert_mismatch',
						)
					);
				}
			}
			$this->repo->patch( $c->id, array( 'cursor_id' => $cursor ) );
			$g->tick( false );
			if ( $g->should_yield() ) {
				return;
			}
			$g->pace();
		}
	}

	/**
	 * End the campaign, or roll it to its next repeat window.
	 *
	 * @param Campaign $c Campaign.
	 */
	private function finish_cycle( Campaign $c ) {
		$counts = $this->repo->counts( $c->id );
		$next   = Schedule::next_window( $c->starts_at, $c->ends_at, $c->repeat_mode, time(), Clock::timezone() );
		$this->repo->log( $c->id, 'reverted', $counts );

		if ( $next && empty( $c->stats['stopped_manually'] ) ) {
			$stats = array_merge( $c->stats, array( 'previous_run' => $counts ) );
			if ( $this->repo->transition(
				$c->id,
				Campaign::VERIFYING_REVERT,
				Campaign::SCHEDULED,
				array(
					'cursor_id' => 0,
					'starts_at' => Campaign::dt( $next[0] ),
					'ends_at'   => Campaign::dt( $next[1] ),
					'run_count' => $c->run_count + 1,
					'stats'     => $stats,
				)
			) ) {
				$this->repo->clear_items( $c->id );
				Queue::schedule_timer( $c->id, $next[0] );
				Queue::schedule_timer( $c->id, $next[1] );
				$this->repo->log( $c->id, 'rescheduled', array( 'starts_at' => $next[0], 'ends_at' => $next[1] ) );
			}
			return;
		}

		$this->repo->transition( $c->id, Campaign::VERIFYING_REVERT, Campaign::ENDED, array( 'cursor_id' => 0 ) );
		$this->repo->log( $c->id, 'ended', $counts );
		do_action( 'dsd_campaign_ended', $c->id, $counts );
	}

	// ------------------------------------------------------------------
	// Single product operations.
	// ------------------------------------------------------------------

	/**
	 * Apply our price to one product (idempotent).
	 *
	 * @param Campaign $c    Campaign.
	 * @param object   $item Item row.
	 */
	public function apply_item( Campaign $c, $item ) {
		try {
			$product = $this->fresh_product( (int) $item->product_id );
			if ( ! $product ) {
				return $this->skip( $item, 'not_found' );
			}
			if ( ! in_array( $product->get_type(), self::supported_types(), true ) ) {
				return $this->skip( $item, 'unsupported_type' );
			}
			if ( ! empty( $c->options['only_in_stock'] ) || Campaign::TARGET_ALL === $c->target_type ) {
				if ( 'instock' !== $product->get_stock_status( 'edit' ) ) {
					return $this->skip( $item, 'out_of_stock' );
				}
			}
			if ( $this->repo->foreign_lock( $product->get_id(), $c->id ) ) {
				return $this->skip( $item, 'other_campaign' );
			}

			$regular = (float) $product->get_regular_price( 'edit' );
			if ( $regular <= 0 ) {
				return $this->skip( $item, 'no_price' );
			}

			$owner = (int) $product->get_meta( '_dsd_campaign', true, 'edit' );
			$ours  = $owner === $c->id && (int) $item->has_backup === 1;

			if ( $ours ) {
				$existing = ( null === $item->orig_sale || '' === $item->orig_sale ) ? null : (float) $item->orig_sale;
			} else {
				$sale = (string) $product->get_sale_price( 'edit' );
				$from = $product->get_date_on_sale_from( 'edit' );
				$to   = $product->get_date_on_sale_to( 'edit' );
				$prev = $owner > 0 && $owner !== $c->id ? $this->repo->find_item( $owner, $product->get_id() ) : null;
				if ( $prev && (int) $prev->has_backup === 1 && PriceCalculator::same( $sale, $prev->new_sale ) ) {
					// A finished campaign never managed to restore this product (it still carries
					// that campaign's price). The true original is in that campaign's backup.
					$backup = array(
						'orig_regular'   => $prev->orig_regular,
						'orig_sale'      => (string) $prev->orig_sale,
						'orig_sale_from' => $prev->orig_sale_from,
						'orig_sale_to'   => $prev->orig_sale_to,
					);
				} else {
					$backup = array(
						'orig_regular'   => (string) $product->get_regular_price( 'edit' ),
						'orig_sale'      => $sale,
						'orig_sale_from' => $from ? $from->getTimestamp() : null,
						'orig_sale_to'   => $to ? $to->getTimestamp() : null,
					);
				}
				$sale     = $backup['orig_sale'];
				$existing = '' === $sale ? null : (float) $sale;
			}

			$new = PriceCalculator::calculate(
				$regular,
				$c->discount_type,
				$c->discount_value,
				array(
					'round_step' => $c->options['round_step'],
					'decimals'   => wc_get_price_decimals(),
					'min_price'  => $c->options['min_price'],
				)
			);
			if ( null === $new ) {
				return $this->skip( $item, 'price_invalid' );
			}

			$decision = PriceCalculator::decide( $existing, $regular, $new, $c->options['sale_policy'] );
			if ( ! $decision['apply'] ) {
				return $this->skip( $item, $decision['reason'] );
			}

			$new_str = wc_format_decimal( $new, wc_get_price_decimals() );
			$already = $ours
				&& PriceCalculator::same( $product->get_sale_price( 'edit' ), $new_str )
				&& null === $product->get_date_on_sale_to( 'edit' )
				&& null === $product->get_date_on_sale_from( 'edit' );

			if ( ! $already ) {
				if ( ! $ours ) {
					// Backup FIRST, together with the price we are about to write (one write).
					// If we die after this line, the re-run reads the same untouched values, and
					// the revert recognises the price as ours even if save() never returned.
					$this->repo->update_item( $item->id, array( 'has_backup' => 1, 'new_sale' => $new_str ) + $backup );
					$item->orig_sale  = $backup['orig_sale'];
					$item->has_backup = 1;
					$item->new_sale   = $new_str;
				} elseif ( (string) $item->new_sale !== (string) $new_str ) {
					$this->repo->update_item( $item->id, array( 'new_sale' => $new_str ) );
					$item->new_sale = $new_str;
				}
				if ( ! $ours ) {
					// Claim the product in its own write BEFORE touching prices. WooCommerce saves
					// prices before custom meta, so without this a crash in between would leave our
					// price without our lock — and the re-run would back up our price as "original".
					update_post_meta( $product->get_id(), '_dsd_campaign', $c->id );
				}
				$product->set_sale_price( $new_str );
				$product->set_date_on_sale_from( '' );
				$product->set_date_on_sale_to( '' );
				$product->update_meta_data( '_dsd_campaign', $c->id );
				$product->save();
			}
			$this->ensure_active_price( $product );

			$this->repo->update_item(
				$item->id,
				array(
					'status'   => Repository::ITEM_APPLIED,
					'new_sale' => $new_str,
					'note'     => '',
				)
			);
		} catch ( \Throwable $e ) {
			$this->fail( $item, $e, Repository::ITEM_PENDING );
		}
		return null;
	}

	/**
	 * Restore one product (only if the price is still the one we wrote).
	 *
	 * @param Campaign $c    Campaign.
	 * @param object   $item Item row.
	 */
	public function revert_item( Campaign $c, $item ) {
		try {
			$product = $this->fresh_product( (int) $item->product_id );
			if ( ! $product ) {
				$this->repo->update_item( $item->id, array( 'status' => Repository::ITEM_REVERTED, 'note' => 'not_found' ) );
				return;
			}
			$owner = (int) $product->get_meta( '_dsd_campaign', true, 'edit' );
			if ( 0 === $owner && PriceCalculator::same( $product->get_sale_price( 'edit' ), $item->new_sale ) ) {
				// Lock lost but our price is still there and nobody else claimed the product:
				// it is still ours to restore.
				$owner = $c->id;
			}
			if ( $owner !== $c->id ) {
				$orig_sale = null === $item->orig_sale ? '' : (string) $item->orig_sale;
				if ( 0 === $owner && PriceCalculator::same( $product->get_sale_price( 'edit' ), $orig_sale ) ) {
					// Our own restore was interrupted after WooCommerce dropped the lock but before it
					// recomputed _price (seen on variations). Finish it rather than walk away.
					$this->ensure_active_price( $product );
					$this->repo->update_item( $item->id, array( 'status' => Repository::ITEM_REVERTED, 'note' => '' ) );
					return;
				}
				if ( 0 === $owner ) {
					$this->ensure_active_price( $product );
				}
				$this->repo->update_item( $item->id, array( 'status' => Repository::ITEM_KEPT, 'note' => 'lock_changed' ) );
				return;
			}
			$current  = $product->get_sale_price( 'edit' );
			$orig     = null === $item->orig_sale ? '' : (string) $item->orig_sale;
			$ours     = PriceCalculator::same( $current, $item->new_sale );
			// A previous run died mid-save: the sale price is already back but the lock (and maybe
			// _price) are not. Finish that restore instead of mistaking it for a manual edit.
			$half_way = ! $ours && PriceCalculator::same( $current, $orig );

			if ( ! $ours && ! $half_way ) {
				// Someone edited the product during the campaign: keep their price, just release the lock.
				$product->delete_meta_data( '_dsd_campaign' );
				$product->save();
				$this->ensure_active_price( $product );
				$this->repo->update_item( $item->id, array( 'status' => Repository::ITEM_KEPT, 'note' => 'manual_change' ) );
				return;
			}

			$product->set_sale_price( $orig );
			$product->set_date_on_sale_from( $item->orig_sale_from ? (int) $item->orig_sale_from : '' );
			$product->set_date_on_sale_to( $item->orig_sale_to ? (int) $item->orig_sale_to : '' );
			$product->delete_meta_data( '_dsd_campaign' );
			$product->save();
			$this->ensure_active_price( $product );

			$this->repo->update_item( $item->id, array( 'status' => Repository::ITEM_REVERTED, 'note' => '' ) );
		} catch ( \Throwable $e ) {
			$this->fail( $item, $e, Repository::ITEM_APPLIED );
		}
	}

	/**
	 * Load a product straight from the database.
	 *
	 * Backup and restore decisions must never come from a cached object: WooCommerce 10+
	 * caches whole product instances, and with a persistent object cache (Redis/Memcached)
	 * a request that died between a DB write and the cache invalidation would leave a stale
	 * copy — which would make us back up our own discounted price as the "original".
	 *
	 * @param int $id Product ID.
	 * @return \WC_Product|false
	 */
	private function fresh_product( $id ) {
		clean_post_cache( $id ); // Fires WooCommerce's product-cache invalidation too.
		wp_cache_delete( $id, 'post_meta' );
		$cache_class = 'Automattic\WooCommerce\Internal\Caches\ProductCache';
		if ( function_exists( 'wc_get_container' ) && class_exists( $cache_class ) ) {
			try {
				wc_get_container()->get( $cache_class )->remove( $id );
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
				// Older WooCommerce: nothing to evict.
			}
		}
		return wc_get_product( $id );
	}

	/**
	 * Make the stored active price (`_price`) agree with sale/regular price.
	 *
	 * WooCommerce only recomputes `_price` when a price prop *changes* during save, so a
	 * save that was killed half-way and then re-run with identical values would leave a
	 * stale `_price` (what customers actually pay). This repairs that case.
	 *
	 * @param \WC_Product $product Product (already saved).
	 */
	private function ensure_active_price( $product ) {
		$expected = $product->is_on_sale( 'edit' ) ? $product->get_sale_price( 'edit' ) : $product->get_regular_price( 'edit' );
		$stored   = get_post_meta( $product->get_id(), '_price', true );
		if ( '' === (string) $expected || PriceCalculator::same( $stored, $expected ) ) {
			return;
		}
		$product->set_price( $expected );
		$product->save();
		if ( ! PriceCalculator::same( get_post_meta( $product->get_id(), '_price', true ), $expected ) ) {
			// Last resort: write the meta and refresh WooCommerce's caches/lookup row ourselves.
			update_post_meta( $product->get_id(), '_price', $expected );
			wc_delete_product_transients( $product->get_id() );
		}
	}

	/**
	 * Keep WooCommerce's product lookup table (price filters, price sorting) in step with
	 * the price meta. A batch killed between the meta write and WooCommerce's lookup update
	 * leaves a stale row; this corrects just the price columns of that row.
	 *
	 * @param object $r       Verify row (product_id, lk_min, lk_max, lk_onsale).
	 * @param mixed  $price   Active price that must be in the lookup.
	 * @param bool   $on_sale Expected on-sale flag.
	 */
	private function repair_lookup( $r, $price, $on_sale ) {
		global $wpdb;
		if ( null === $r->lk_min ) {
			return; // No lookup row (table missing or product not indexed yet): nothing to correct.
		}
		if ( PriceCalculator::same( $r->lk_min, $price ) && PriceCalculator::same( $r->lk_max, $price ) && (int) $r->lk_onsale === (int) $on_sale ) {
			return;
		}
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'wc_product_meta_lookup',
			array(
				'min_price' => wc_format_decimal( $price ),
				'max_price' => wc_format_decimal( $price ),
				'onsale'    => $on_sale ? 1 : 0,
			),
			array( 'product_id' => (int) $r->product_id )
		);
		wc_delete_product_transients( (int) $r->product_id );
	}

	/**
	 * Product types that carry their own price.
	 *
	 * @return string[]
	 */
	public static function supported_types() {
		return apply_filters( 'dsd_supported_product_types', array( 'simple', 'variation', 'external', 'subscription', 'subscription_variation' ) );
	}

	/**
	 * Mark skipped.
	 *
	 * @param object $item   Item.
	 * @param string $reason Reason code.
	 * @return null
	 */
	private function skip( $item, $reason ) {
		$this->repo->update_item(
			$item->id,
			array(
				'status' => Repository::ITEM_SKIPPED,
				'note'   => $reason,
			)
		);
		return null;
	}

	/**
	 * Record an exception; retry up to MAX_ATTEMPTS.
	 *
	 * @param object     $item        Item.
	 * @param \Throwable $e           Error.
	 * @param string     $retry_state State that means "try again".
	 */
	private function fail( $item, \Throwable $e, $retry_state ) {
		$attempts = (int) $item->attempts + 1;
		$this->repo->update_item(
			$item->id,
			array(
				'status'   => $attempts >= Repository::MAX_ATTEMPTS ? Repository::ITEM_FAILED : $retry_state,
				'attempts' => $attempts,
				'note'     => 'error: ' . $e->getMessage(),
			)
		);
	}

	/**
	 * Drop the in-memory object cache every 10 products so memory stays flat.
	 *
	 * @param ResourceGovernor $g Governor.
	 */
	private function maybe_free_memory( ResourceGovernor $g ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		static $n = 0;
		$n++;
		if ( 0 !== $n % 10 ) {
			return;
		}
		global $wpdb, $wp_object_cache;
		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_runtime' ) ) {
			wp_cache_flush_runtime();
		} elseif ( is_object( $wp_object_cache ) && 'WP_Object_Cache' === get_class( $wp_object_cache ) ) {
			$wp_object_cache->flush();
		}
		$wpdb->queries = array();
	}
}

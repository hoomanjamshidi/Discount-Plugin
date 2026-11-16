<?php
/**
 * Time-based transitions and stall recovery.
 *
 * Runs from: the exact start/end timers, the 2-minute watchdog, and the start of every batch.
 * Even if every timer is lost, the next batch or watchdog run catches up.
 *
 * @package SmartBulkDiscount
 */

namespace SmartBulkDiscount;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps campaigns moving.
 */
final class Watchdog {

	/** Seconds without a batch before a working queue counts as stalled. */
	const STALL_AFTER = 180;

	/**
	 * Recurring watchdog entry point.
	 */
	public static function run() {
		$repo = new Repository();
		self::check_times( $repo );
		self::recover_stall( $repo );
	}

	/**
	 * Start due campaigns, end expired ones.
	 *
	 * @param Repository $repo Repo.
	 * @return int Number of transitions.
	 */
	public static function check_times( Repository $repo ) {
		$now   = time();
		$moved = 0;

		foreach ( $repo->by_status( array( Campaign::SCHEDULED ) ) as $c ) {
			if ( ! Schedule::should_start( $c->starts_at, $now ) ) {
				continue;
			}
			if ( Schedule::should_end( $c->ends_at, $now ) ) {
				// The whole window passed while the site was asleep.
				$next = Schedule::next_window( $c->starts_at, $c->ends_at, $c->repeat_mode, $now, Clock::timezone() );
				if ( $next ) {
					$repo->patch( $c->id, array( 'starts_at' => $next[0], 'ends_at' => $next[1] ) );
					Queue::schedule_timer( $c->id, $next[0] );
					Queue::schedule_timer( $c->id, $next[1] );
					$repo->log( $c->id, 'missed_window', array( 'starts_at' => $next[0] ) );
				} elseif ( $repo->transition( $c->id, Campaign::SCHEDULED, Campaign::ENDED ) ) {
					$repo->log( $c->id, 'missed_window', array() );
				}
				$moved++;
				continue;
			}
			if ( $repo->transition( $c->id, Campaign::SCHEDULED, Campaign::PREPARING, array( 'cursor_id' => 0 ) ) ) {
				$repo->log( $c->id, 'started', array() );
				$moved++;
			}
		}

		$live = array( Campaign::PREPARING, Campaign::APPLYING, Campaign::VERIFYING, Campaign::ACTIVE, Campaign::PAUSED );
		foreach ( $repo->by_status( $live ) as $c ) {
			if ( Schedule::should_end( $c->ends_at, $now ) ) {
				if ( self::begin_revert( $repo, $c, 'time_up' ) ) {
					$moved++;
				}
			}
		}

		if ( $moved > 0 ) {
			Queue::schedule_pump( 0 );
		}
		return $moved;
	}

	/**
	 * Move a campaign into the revert phase.
	 *
	 * @param Repository $repo   Repo.
	 * @param Campaign   $c      Campaign.
	 * @param string     $reason time_up|stopped.
	 * @return bool
	 */
	public static function begin_revert( Repository $repo, Campaign $c, $reason ) {
		$extra = array(
			'cursor_id'     => 0,
			'paused_status' => '',
		);
		if ( 'stopped' === $reason ) {
			$extra['stats'] = array_merge( $c->stats, array( 'stopped_manually' => true ) );
			if ( null === $c->ends_at || $c->ends_at > time() ) {
				$extra['ends_at'] = Campaign::dt( time() );
			}
		}
		if ( ! $repo->transition( $c->id, $c->status, Campaign::REVERTING, $extra ) ) {
			return false;
		}
		// Products whose write failed after their original price was backed up may still
		// carry our price: give them to the revert phase (it restores only our own price).
		$repo->failed_with_backup_to_applied( $c->id );
		$repo->reset_attempts( $c->id, Repository::ITEM_APPLIED );
		$repo->log( $c->id, 'ending', array( 'reason' => $reason ) );
		return true;
	}

	/**
	 * If work is waiting but nothing ran for a while, restart the chain.
	 *
	 * @param Repository $repo Repo.
	 * @return bool True when a restart was triggered.
	 */
	public static function recover_stall( Repository $repo ) {
		if ( null === $repo->next_working() ) {
			return false;
		}
		$last = (int) get_option( 'dsd_heartbeat', 0 );
		if ( Queue::pump_pending() && ( time() - $last ) < self::STALL_AFTER ) {
			return false;
		}
		Queue::schedule_pump( 0 );
		Queue::kick();
		return true;
	}
}

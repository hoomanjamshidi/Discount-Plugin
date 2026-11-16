<?php
/**
 * Background scheduling. Prefers WooCommerce's Action Scheduler; falls back to WP-Cron.
 *
 * Three kinds of jobs:
 *  - dsd_pump      one governed batch; re-schedules itself while work remains.
 *  - dsd_timer     fires exactly at a campaign's start/end time.
 *  - dsd_watchdog  every 2 minutes: time transitions + restarts a stalled chain.
 *
 * @package SmartBulkDiscount
 */

namespace SmartBulkDiscount;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper over Action Scheduler / WP-Cron.
 */
final class Queue {

	const GROUP = 'smart-bulk-discount';

	/** Longest wait (seconds) a loopback request may do before its batch. */
	const MAX_KICK_DELAY = 10;

	/**
	 * Is Action Scheduler ready to use?
	 *
	 * @return bool
	 */
	public static function has_as() {
		return function_exists( 'as_schedule_single_action' )
			&& class_exists( '\ActionScheduler' )
			&& \ActionScheduler::is_initialized();
	}

	/**
	 * Make sure the recurring watchdog exists (cheap: checked at most every 10 min).
	 *
	 * @param bool $force Skip the transient.
	 */
	public static function ensure_watchdog( $force = false ) {
		if ( ! $force && get_transient( 'dsd_watchdog_ok' ) ) {
			return;
		}
		if ( self::has_as() ) {
			if ( ! as_has_scheduled_action( 'dsd_watchdog', array(), self::GROUP ) ) {
				as_schedule_recurring_action( time() + 30, 2 * MINUTE_IN_SECONDS, 'dsd_watchdog', array(), self::GROUP, true );
			}
		} elseif ( ! wp_next_scheduled( 'dsd_watchdog' ) ) {
			wp_schedule_event( time() + 30, 'dsd_two_minutes', 'dsd_watchdog' );
		}
		set_transient( 'dsd_watchdog_ok', 1, 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * Queue the next batch (deduplicated).
	 *
	 * @param int $delay Seconds.
	 */
	public static function schedule_pump( $delay = 0 ) {
		$delay = max( 0, (int) $delay );
		if ( self::has_as() ) {
			if ( ! as_has_scheduled_action( 'dsd_pump', array(), self::GROUP ) ) {
				if ( 0 === $delay ) {
					as_enqueue_async_action( 'dsd_pump', array(), self::GROUP, true );
				} else {
					as_schedule_single_action( time() + $delay, 'dsd_pump', array(), self::GROUP, true );
				}
			}
		} elseif ( ! wp_next_scheduled( 'dsd_pump' ) ) {
			wp_schedule_single_event( time() + $delay, 'dsd_pump' );
		}
	}

	/**
	 * Is a batch waiting in the queue?
	 *
	 * @return bool
	 */
	public static function pump_pending() {
		if ( self::has_as() ) {
			return (bool) as_has_scheduled_action( 'dsd_pump', array(), self::GROUP );
		}
		return (bool) wp_next_scheduled( 'dsd_pump' );
	}

	/**
	 * Fire exactly at a campaign boundary (start or end).
	 *
	 * @param int $campaign_id Campaign.
	 * @param int $timestamp   UTC.
	 */
	public static function schedule_timer( $campaign_id, $timestamp ) {
		$args = array( (int) $campaign_id, (int) $timestamp );
		if ( self::has_as() ) {
			if ( ! as_has_scheduled_action( 'dsd_timer', $args, self::GROUP ) ) {
				as_schedule_single_action( (int) $timestamp, 'dsd_timer', $args, self::GROUP );
			}
		} elseif ( ! wp_next_scheduled( 'dsd_timer', $args ) ) {
			wp_schedule_single_event( (int) $timestamp, 'dsd_timer', $args );
		}
	}

	/**
	 * Remove a campaign's boundary timers.
	 *
	 * @param Campaign $c Campaign.
	 */
	public static function clear_timers( Campaign $c ) {
		foreach ( array( $c->starts_at, $c->ends_at ) as $ts ) {
			if ( null === $ts ) {
				continue;
			}
			$args = array( (int) $c->id, (int) $ts );
			if ( self::has_as() ) {
				as_unschedule_all_actions( 'dsd_timer', $args, self::GROUP );
			} else {
				wp_clear_scheduled_hook( 'dsd_timer', $args );
			}
		}
	}

	/**
	 * Longest wait a loopback request may do before its batch: the request's whole wall
	 * time (wait + batch) must stay well inside max_execution_time and proxy timeouts.
	 *
	 * @return int
	 */
	public static function max_kick_delay() {
		$exec = (int) ini_get( 'max_execution_time' );
		if ( $exec <= 0 ) {
			return self::MAX_KICK_DELAY;
		}
		return (int) max( 0, min( self::MAX_KICK_DELAY, floor( $exec * 0.3 ) ) );
	}

	/**
	 * Non-blocking loopback request that runs one batch (after an optional wait)
	 * — keeps the chain moving on sites with little traffic / slow or disabled WP-Cron.
	 *
	 * Only ONE loopback chain lives at a time: each new chain gets a fresh token and any
	 * older chain stops when it sees that its token is no longer current. Without this,
	 * every start/stop/watchdog call could add another endless chain of requests.
	 *
	 * @param int         $delay Seconds the loopback request waits before working.
	 * @param string|null $token Continue this chain (null = start a new one).
	 */
	public static function kick( $delay = 0, $token = null ) {
		if ( ! Settings::get( 'loopback' ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		$current = (string) self::db_option( 'dsd_kick_token', '' );
		if ( null === $token || '' === $token || $token !== $current ) {
			$token = wp_generate_password( 12, false );
			update_option( 'dsd_kick_token', $token, false );
		}
		update_option( 'dsd_kick_sent', time(), false );
		$url = add_query_arg(
			array(
				'action' => 'dsd_kick',
				'key'    => (string) get_option( 'dsd_kick_secret', '' ),
				'token'  => $token,
				'delay'  => max( 0, min( self::max_kick_delay(), (int) $delay ) ),
			),
			admin_url( 'admin-ajax.php' )
		);
		$args = apply_filters(
			'dsd_kick_request_args',
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- core filter.
				'cookies'   => array(),
				'headers'   => array( 'Cache-Control' => 'no-cache' ),
			)
		);
		wp_remote_post( $url, $args );
	}

	/**
	 * Is this loopback chain still the current one?
	 *
	 * @param string $token Token.
	 * @return bool
	 */
	public static function token_current( $token ) {
		$current = (string) self::db_option( 'dsd_kick_token', '' );
		return '' !== $current && hash_equals( $current, (string) $token );
	}

	/**
	 * Read an option straight from the database. For values another request may have
	 * changed while this one was running or sleeping (the per-request option cache would
	 * still hold the old value).
	 *
	 * @param string $name    Option.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public static function db_option( $name, $default = false ) {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( null === $raw ) {
			return $default;
		}
		return maybe_unserialize( $raw );
	}

	/**
	 * Remove everything (deactivation).
	 */
	public static function unschedule_all() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), self::GROUP );
		}
		wp_clear_scheduled_hook( 'dsd_watchdog' );
		wp_clear_scheduled_hook( 'dsd_pump' );
		delete_transient( 'dsd_watchdog_ok' );
	}
}

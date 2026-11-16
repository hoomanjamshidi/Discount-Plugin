<?php
/**
 * Pure time-window logic. All timestamps are UTC unix seconds.
 *
 * @package SmartBulkDiscount
 */

namespace SmartBulkDiscount;

defined( 'ABSPATH' ) || exit;

/**
 * Decides when a campaign should start/end and computes repeat windows.
 */
final class Schedule {

	const REPEAT_NONE   = 'none';
	const REPEAT_DAILY  = 'daily';
	const REPEAT_WEEKLY = 'weekly';

	/**
	 * Validate a window. Returns '' when valid, otherwise an error code.
	 *
	 * @param int|null $start  Start (null = now).
	 * @param int|null $end    End (null = until stopped).
	 * @param string   $repeat Repeat mode.
	 * @param int      $now    Now.
	 * @return string
	 */
	public static function validate( $start, $end, $repeat, $now ) {
		if ( ! in_array( $repeat, array( self::REPEAT_NONE, self::REPEAT_DAILY, self::REPEAT_WEEKLY ), true ) ) {
			return 'invalid_repeat';
		}
		$effective_start = null === $start ? $now : $start;
		if ( null !== $end ) {
			if ( $end <= $effective_start ) {
				return 'end_before_start';
			}
			if ( $end <= $now ) {
				return 'end_in_past';
			}
		}
		if ( self::REPEAT_NONE !== $repeat ) {
			if ( null === $start || null === $end ) {
				return 'repeat_needs_start_and_end';
			}
			$len = $end - $start;
			if ( $len > self::interval( $repeat ) ) {
				return 'repeat_window_too_long';
			}
		}
		return '';
	}

	/**
	 * Seconds in one repeat interval.
	 *
	 * @param string $repeat Mode.
	 * @return int
	 */
	public static function interval( $repeat ) {
		if ( self::REPEAT_DAILY === $repeat ) {
			return DAY_IN_SECONDS;
		}
		if ( self::REPEAT_WEEKLY === $repeat ) {
			return WEEK_IN_SECONDS;
		}
		return 0;
	}

	/**
	 * Should a scheduled campaign start now?
	 *
	 * @param int|null $start Start.
	 * @param int      $now   Now.
	 * @return bool
	 */
	public static function should_start( $start, $now ) {
		return null === $start || $start <= $now;
	}

	/**
	 * Should a live campaign end now?
	 *
	 * @param int|null $end End.
	 * @param int      $now Now.
	 * @return bool
	 */
	public static function should_end( $end, $now ) {
		return null !== $end && $end <= $now;
	}

	/**
	 * Next window after the current one ended, or null when not repeating.
	 * Skips windows that are already entirely in the past (e.g. site was down).
	 *
	 * With a timezone, days/weeks are added on the site's wall clock, so "every Friday
	 * 08:00–23:59" stays 08:00 even across a daylight-saving change.
	 *
	 * @param int                $start  Current start.
	 * @param int                $end    Current end.
	 * @param string             $repeat Mode.
	 * @param int                $now    Now.
	 * @param \DateTimeZone|null $tz     Site timezone (null = plain seconds).
	 * @return array{0:int,1:int}|null
	 */
	public static function next_window( $start, $end, $repeat, $now, $tz = null ) {
		$step = self::interval( $repeat );
		if ( $step <= 0 || null === $start || null === $end ) {
			return null;
		}
		$days = self::REPEAT_DAILY === $repeat ? 1 : 7;
		// Whole intervals to skip so the next window ends in the future (at least one).
		$skip = 1;
		if ( $end + $step <= $now ) {
			$skip = (int) floor( ( $now - $end ) / $step ) + 1;
		}
		$next = array( self::shift( $start, $skip * $days, $tz ), self::shift( $end, $skip * $days, $tz ) );
		// Wall-clock days can be an hour shorter/longer than $step: settle the edge.
		$guard = 0;
		while ( $next[1] <= $now && $guard++ < 3 ) {
			$skip++;
			$next = array( self::shift( $start, $skip * $days, $tz ), self::shift( $end, $skip * $days, $tz ) );
		}
		return $next;
	}

	/**
	 * Timestamp + N calendar days (on the site's wall clock when a timezone is given).
	 *
	 * @param int                $ts   Timestamp.
	 * @param int                $days Days.
	 * @param \DateTimeZone|null $tz   Timezone.
	 * @return int
	 */
	public static function shift( $ts, $days, $tz = null ) {
		if ( ! $tz instanceof \DateTimeZone ) {
			return (int) $ts + (int) $days * 86400;
		}
		$local = ( new \DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( $tz );
		$moved = \DateTimeImmutable::createFromFormat(
			'!Y-m-d H:i:s',
			$local->modify( '+' . (int) $days . ' days' )->format( 'Y-m-d H:i:s' ),
			$tz
		);
		return $moved ? $moved->getTimestamp() : (int) $ts + (int) $days * 86400;
	}
}

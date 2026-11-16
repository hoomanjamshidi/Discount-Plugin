<?php
// phpcs:ignoreFile

use SmartBulkDiscount\Schedule as S;
use PHPUnit\Framework\TestCase;

class ScheduleTest extends TestCase {

	const NOW = 1763280000; // 2025-11-16 08:00 UTC.

	public function test_open_ended_now_is_valid() {
		$this->assertSame( '', S::validate( null, null, 'none', self::NOW ) );
	}

	public function test_end_must_follow_start() {
		$this->assertSame( 'end_before_start', S::validate( self::NOW + 100, self::NOW + 50, 'none', self::NOW ) );
		$this->assertSame( 'end_before_start', S::validate( self::NOW + 100, self::NOW + 100, 'none', self::NOW ) );
	}

	public function test_end_in_past_rejected() {
		$this->assertSame( 'end_before_start', S::validate( null, self::NOW - 10, 'none', self::NOW ) );
		$this->assertSame( 'end_in_past', S::validate( self::NOW - 1000, self::NOW - 10, 'none', self::NOW ) );
	}

	public function test_repeat_rules() {
		$this->assertSame( 'repeat_needs_start_and_end', S::validate( null, self::NOW + 100, 'daily', self::NOW ) );
		$this->assertSame( 'repeat_window_too_long', S::validate( self::NOW, self::NOW + 2 * DAY_IN_SECONDS, 'daily', self::NOW ) );
		$this->assertSame( '', S::validate( self::NOW, self::NOW + 2 * DAY_IN_SECONDS, 'weekly', self::NOW ) );
		$this->assertSame( 'invalid_repeat', S::validate( null, null, 'monthly', self::NOW ) );
	}

	public function test_should_start_and_end() {
		$this->assertTrue( S::should_start( null, self::NOW ) );
		$this->assertTrue( S::should_start( self::NOW, self::NOW ) );
		$this->assertFalse( S::should_start( self::NOW + 1, self::NOW ) );
		$this->assertFalse( S::should_end( null, self::NOW ) );
		$this->assertTrue( S::should_end( self::NOW, self::NOW ) );
	}

	public function test_next_window_daily() {
		$start = self::NOW - 3600;
		$end   = self::NOW;
		$this->assertSame( array( $start + DAY_IN_SECONDS, $end + DAY_IN_SECONDS ), S::next_window( $start, $end, 'daily', self::NOW ) );
	}

	public function test_next_window_skips_missed_windows() {
		// Site was down for 3.5 weeks: the next window must be in the future.
		$start = self::NOW - 25 * DAY_IN_SECONDS;
		$end   = $start + 3600;
		list( $s, $e ) = S::next_window( $start, $end, 'weekly', self::NOW );
		$this->assertGreaterThan( self::NOW, $e );
		$this->assertSame( 3600, $e - $s );
		$this->assertSame( 0, ( $s - $start ) % WEEK_IN_SECONDS );
		$this->assertLessThanOrEqual( self::NOW + WEEK_IN_SECONDS, $e );
	}

	public function test_no_repeat_returns_null() {
		$this->assertNull( S::next_window( self::NOW, self::NOW + 10, 'none', self::NOW ) );
	}

	public function test_daily_repeat_keeps_wall_clock_time_across_dst() {
		// Europe/Berlin switches to summer time on 2026-03-29: a 08:00 window must stay 08:00.
		$tz    = new \DateTimeZone( 'Europe/Berlin' );
		$start = ( new \DateTimeImmutable( '2026-03-28 08:00:00', $tz ) )->getTimestamp();
		$end   = ( new \DateTimeImmutable( '2026-03-28 10:00:00', $tz ) )->getTimestamp();
		list( $s, $e ) = S::next_window( $start, $end, 'daily', $end + 60, $tz );
		$this->assertSame( '2026-03-29 08:00', ( new \DateTimeImmutable( '@' . $s ) )->setTimezone( $tz )->format( 'Y-m-d H:i' ) );
		$this->assertSame( '2026-03-29 10:00', ( new \DateTimeImmutable( '@' . $e ) )->setTimezone( $tz )->format( 'Y-m-d H:i' ) );
		$this->assertSame( 23 * 3600, $s - $start, 'the DST day is 23 hours long' );
	}

	public function test_weekly_repeat_with_timezone_skips_missed_weeks() {
		$tz    = new \DateTimeZone( '+03:30' );
		$start = ( new \DateTimeImmutable( '2026-01-02 08:00:00', $tz ) )->getTimestamp(); // Friday.
		$end   = $start + 15 * 3600;
		$now   = $start + 40 * DAY_IN_SECONDS;
		list( $s, $e ) = S::next_window( $start, $end, 'weekly', $now, $tz );
		$this->assertGreaterThan( $now, $e );
		$this->assertLessThanOrEqual( $now + WEEK_IN_SECONDS, $e );
		$this->assertSame( 'Fri 08:00', ( new \DateTimeImmutable( '@' . $s ) )->setTimezone( $tz )->format( 'D H:i' ) );
	}
}

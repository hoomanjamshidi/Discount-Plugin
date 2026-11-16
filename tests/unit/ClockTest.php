<?php
// phpcs:ignoreFile

use SmartBulkDiscount\Clock as C;
use PHPUnit\Framework\TestCase;

class ClockTest extends TestCase {

	private function tehran() {
		return new \DateTimeZone( '+03:30' );
	}

	public function test_parses_site_wall_clock() {
		$ts = C::parse( '2026-11-20 08:30', $this->tehran() );
		$this->assertSame( '2026-11-20 05:00', gmdate( 'Y-m-d H:i', $ts ) );
	}

	public function test_accepts_persian_and_arabic_digits_and_slashes() {
		$expected = C::parse( '2026-11-20 08:30', $this->tehran() );
		$this->assertSame( $expected, C::parse( '۲۰۲۶-۱۱-۲۰ ۰۸:۳۰', $this->tehran() ) );
		$this->assertSame( $expected, C::parse( '٢٠٢٦/١١/٢٠ ٠٨:٣٠', $this->tehran() ) );
		$this->assertSame( $expected, C::parse( '2026-11-20T08:30:00', $this->tehran() ) );
	}

	public function test_a_jalali_date_is_converted_not_misread() {
		// What a Persian date plugin's wp_date() produced: must not become the year 1405 AD.
		$this->assertSame( C::parse( '2026-11-16 10:00', $this->tehran() ), C::parse( '۱۴۰۵-۰۸-۲۵ ۱۰:۰۰', $this->tehran() ) );
		$this->assertSame( C::parse( '2026-03-21 00:00', $this->tehran() ), C::parse( '1405/01/01 00:00', $this->tehran() ) );
	}

	public function test_rejects_garbage_and_impossible_dates() {
		foreach ( array( 'tomorrow', 'not a date', '2026-02-30 10:00', '2026-13-01', '2026-01-01 24:00', '1404-12-30 10:00', '1403-12-31' ) as $bad ) {
			$this->assertFalse( C::parse( $bad, $this->tehran() ), $bad );
		}
		$this->assertNull( C::parse( '' ) );
		$this->assertNull( C::parse( null ) );
	}

	public function test_esfand_30_only_in_leap_years() {
		$this->assertSame( 30, C::jalali_month_days( 1403, 12 ) ); // 1403 is leap.
		$this->assertSame( 29, C::jalali_month_days( 1404, 12 ) );
		$this->assertNotFalse( C::parse( '1403-12-30 10:00', $this->tehran() ) );
	}

	public function test_known_conversions() {
		$this->assertSame( array( 1405, 8, 25 ), C::gregorian_to_jalali( 2026, 11, 16 ) );
		$this->assertSame( array( 1405, 1, 1 ), C::gregorian_to_jalali( 2026, 3, 21 ) );
		$this->assertSame( array( 1403, 12, 30 ), C::gregorian_to_jalali( 2025, 3, 20 ) );
		$this->assertSame( array( 2025, 3, 21 ), C::jalali_to_gregorian( 1404, 1, 1 ) );
	}

	public function test_round_trip_every_day_for_30_years() {
		$d = new \DateTimeImmutable( '2010-01-01 12:00:00', new \DateTimeZone( 'UTC' ) );
		for ( $i = 0; $i < 365 * 30; $i++ ) {
			$y = (int) $d->format( 'Y' );
			$m = (int) $d->format( 'n' );
			$day = (int) $d->format( 'j' );
			list( $jy, $jm, $jd ) = C::gregorian_to_jalali( $y, $m, $day );
			$this->assertSame( array( $y, $m, $day ), C::jalali_to_gregorian( $jy, $jm, $jd ) );
			$d = $d->modify( '+1 day' );
		}
	}

	public function test_outdated_tehran_dst_rules_are_repaired() {
		$tz = C::repair( new \DateTimeZone( 'Asia/Tehran' ) );
		$summer = new \DateTimeImmutable( '2026-07-01 12:00:00', new \DateTimeZone( 'UTC' ) );
		$this->assertSame( 12600, $tz->getOffset( $summer ), 'Iran is +03:30 all year' );
		$this->assertSame( 'Europe/Berlin', C::repair( new \DateTimeZone( 'Europe/Berlin' ) )->getName() );
	}
}

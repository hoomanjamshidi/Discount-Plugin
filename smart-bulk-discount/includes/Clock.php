<?php
/**
 * Site time, immune to the things that broke dates on real Iranian shops.
 *
 *  - Persian date plugins (WP-Parsidate, wp-jalali, Persian WooCommerce …) filter
 *    wp_date() / date_i18n() and return Jalali dates and/or Persian digits. Our machine
 *    strings went through wp_date(), so the admin app received "۱۴۰۵-۰۸-۲۵ ۱۰:۰۰" and read
 *    it as a Gregorian date — every date on screen was wrong, and editing a campaign saved
 *    a date in the year 1405 AD. We never call those functions; we format with PHP's
 *    DateTimeImmutable directly.
 *  - Old PHP / OS timezone databases still apply daylight saving to Asia/Tehran (abolished
 *    in 2022), which shifts every time by one hour half of the year. Detected and replaced
 *    with the fixed +03:30 offset.
 *  - Input is parsed strictly (no silent roll-over of 31 Esfand / 30 Feb, no "garbage = now"),
 *    accepts Persian/Arabic digits, and also accepts a Jalali date if one ever arrives.
 *
 * @package SmartBulkDiscount
 */

namespace SmartBulkDiscount;

defined( 'ABSPATH' ) || exit;

/**
 * Timezone + formatting + parsing helpers.
 */
final class Clock {

	/**
	 * The site's timezone.
	 *
	 * @return \DateTimeZone
	 */
	public static function timezone() {
		static $tz = null;
		if ( null !== $tz ) {
			return $tz;
		}
		$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		$tz = self::repair( $tz );
		return $tz;
	}

	/**
	 * Replace a timezone whose rules are known to be outdated on old servers.
	 *
	 * @param \DateTimeZone $tz Timezone.
	 * @return \DateTimeZone
	 */
	public static function repair( \DateTimeZone $tz ) {
		if ( 'Asia/Tehran' === $tz->getName() ) {
			// Iran has no daylight saving since 2022: summer must be +03:30 like winter.
			$summer = new \DateTimeImmutable( '2025-07-01 12:00:00', new \DateTimeZone( 'UTC' ) );
			if ( 12600 !== $tz->getOffset( $summer ) ) {
				return new \DateTimeZone( '+03:30' );
			}
		}
		return $tz;
	}

	/**
	 * Unix timestamp → site wall clock string (Gregorian, Latin digits).
	 *
	 * @param int|null $ts     Timestamp.
	 * @param string   $format PHP date format.
	 * @return string|null
	 */
	public static function local( $ts, $format = 'Y-m-d H:i' ) {
		if ( null === $ts || '' === $ts ) {
			return null;
		}
		$dt = new \DateTimeImmutable( '@' . (int) $ts );
		return $dt->setTimezone( self::timezone() )->format( $format );
	}

	/**
	 * Parse a site wall-clock date ("2026-11-20 08:30", Persian digits allowed, a Jalali
	 * year such as 1405 is converted) or a unix timestamp.
	 *
	 * @param mixed              $value Value.
	 * @param \DateTimeZone|null $tz    Timezone (default: site).
	 * @return int|null|false Timestamp; null for "no date"; false when invalid.
	 */
	public static function parse( $value, $tz = null ) {
		if ( null === $value || false === $value ) {
			return null;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return $value > 0 ? (int) $value : false;
		}
		$value = trim( self::latin_digits( (string) $value ) );
		if ( '' === $value ) {
			return null;
		}
		if ( ctype_digit( $value ) && strlen( $value ) >= 9 ) {
			return (int) $value; // Unix timestamp.
		}
		$value = str_replace( array( '/', '.' ), '-', $value );
		if ( ! preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?)?$/', $value, $m ) ) {
			return false;
		}
		$y  = (int) $m[1];
		$mo = (int) $m[2];
		$d  = (int) $m[3];
		$h  = isset( $m[4] ) && '' !== $m[4] ? (int) $m[4] : 0;
		$i  = isset( $m[5] ) && '' !== $m[5] ? (int) $m[5] : 0;
		$s  = isset( $m[6] ) && '' !== $m[6] ? (int) $m[6] : 0;
		if ( $h > 23 || $i > 59 || $s > 59 ) {
			return false;
		}
		if ( $y >= 1300 && $y < 1500 ) {
			// A Jalali (Shamsi) date.
			if ( $mo < 1 || $mo > 12 || $d < 1 || $d > self::jalali_month_days( $y, $mo ) ) {
				return false;
			}
			list( $y, $mo, $d ) = self::jalali_to_gregorian( $y, $mo, $d );
		}
		if ( $y < 1900 || $y > 2200 || ! checkdate( $mo, $d, $y ) ) {
			return false;
		}
		$dt = \DateTimeImmutable::createFromFormat(
			'!Y-m-d H:i:s',
			sprintf( '%04d-%02d-%02d %02d:%02d:%02d', $y, $mo, $d, $h, $i, $s ),
			$tz instanceof \DateTimeZone ? $tz : self::timezone()
		);
		return $dt ? $dt->getTimestamp() : false;
	}

	/**
	 * Persian (۰-۹) and Arabic-Indic (٠-٩) digits → Latin.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	public static function latin_digits( $s ) {
		return strtr(
			(string) $s,
			array(
				'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
				'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
			)
		);
	}

	/**
	 * Jalali → Gregorian (33-year arithmetic rule; exact for 1300–1499).
	 *
	 * @param int $jy Year.
	 * @param int $jm Month 1-12.
	 * @param int $jd Day.
	 * @return int[] [y, m, d]
	 */
	public static function jalali_to_gregorian( $jy, $jm, $jd ) {
		$jy  += 1595;
		$days = -355668 + ( 365 * $jy ) + ( intdiv( $jy, 33 ) * 8 ) + intdiv( ( $jy % 33 ) + 3, 4 ) + $jd + ( $jm < 7 ? ( $jm - 1 ) * 31 : ( ( $jm - 7 ) * 30 ) + 186 );
		$gy   = 400 * intdiv( $days, 146097 );
		$days = $days % 146097;
		if ( $days > 36524 ) {
			$days--;
			$gy  += 100 * intdiv( $days, 36524 );
			$days = $days % 36524;
			if ( $days >= 365 ) {
				$days++;
			}
		}
		$gy  += 4 * intdiv( $days, 1461 );
		$days = $days % 1461;
		if ( $days > 365 ) {
			$gy  += intdiv( $days - 1, 365 );
			$days = ( $days - 1 ) % 365;
		}
		$gd    = $days + 1;
		$leap  = ( 0 === $gy % 4 && 0 !== $gy % 100 ) || 0 === $gy % 400;
		$month = array( 0, 31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 );
		$gm    = 0;
		for ( $gm = 0; $gm < 13 && $gd > $month[ $gm ]; $gm++ ) {
			$gd -= $month[ $gm ];
		}
		return array( $gy, $gm, $gd );
	}

	/**
	 * Gregorian → Jalali.
	 *
	 * @param int $gy Year.
	 * @param int $gm Month 1-12.
	 * @param int $gd Day.
	 * @return int[] [y, m, d]
	 */
	public static function gregorian_to_jalali( $gy, $gm, $gd ) {
		$g_d_m = array( 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334 );
		$gy2   = $gm > 2 ? $gy + 1 : $gy;
		$days  = 355666 + ( 365 * $gy ) + intdiv( $gy2 + 3, 4 ) - intdiv( $gy2 + 99, 100 ) + intdiv( $gy2 + 399, 400 ) + $gd + $g_d_m[ $gm - 1 ];
		$jy    = -1595 + ( 33 * intdiv( $days, 12053 ) );
		$days  = $days % 12053;
		$jy   += 4 * intdiv( $days, 1461 );
		$days  = $days % 1461;
		if ( $days > 365 ) {
			$jy  += intdiv( $days - 1, 365 );
			$days = ( $days - 1 ) % 365;
		}
		if ( $days < 186 ) {
			$jm = 1 + intdiv( $days, 31 );
			$jd = 1 + ( $days % 31 );
		} else {
			$jm = 7 + intdiv( $days - 186, 30 );
			$jd = 1 + ( ( $days - 186 ) % 30 );
		}
		return array( $jy, $jm, $jd );
	}

	/**
	 * Days in a Jalali month.
	 *
	 * @param int $jy Year.
	 * @param int $jm Month.
	 * @return int
	 */
	public static function jalali_month_days( $jy, $jm ) {
		if ( $jm <= 6 ) {
			return 31;
		}
		if ( $jm <= 11 ) {
			return 30;
		}
		// Esfand has 30 days in a leap year: the day after 29 Esfand is still Esfand.
		list( $gy, $gm, $gd ) = self::jalali_to_gregorian( $jy, 12, 29 );
		$next                 = self::add_day( $gy, $gm, $gd );
		$after                = self::gregorian_to_jalali( $next[0], $next[1], $next[2] );
		return 12 === $after[1] ? 30 : 29;
	}

	/**
	 * Gregorian date + 1 day.
	 *
	 * @param int $y Year.
	 * @param int $m Month.
	 * @param int $d Day.
	 * @return int[]
	 */
	private static function add_day( $y, $m, $d ) {
		$dt = new \DateTimeImmutable( sprintf( '%04d-%02d-%02d 12:00:00', $y, $m, $d ), new \DateTimeZone( 'UTC' ) );
		$dt = $dt->modify( '+1 day' );
		return array( (int) $dt->format( 'Y' ), (int) $dt->format( 'n' ), (int) $dt->format( 'j' ) );
	}
}

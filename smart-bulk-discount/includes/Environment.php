<?php
/**
 * Host resource readings. Swappable so the governor can be unit tested.
 *
 * Every reading here must be safe on any host: functions may be disabled
 * (disable_functions), /proc may be hidden (open_basedir) and limits may be odd.
 * Unknown values are reported as unknown (null / 0), never guessed.
 *
 * @package SmartBulkDiscount
 */

namespace SmartBulkDiscount;

defined( 'ABSPATH' ) || exit;

/**
 * Reads real PHP / OS limits.
 */
class Environment {

	/**
	 * Clock start override for long-running CLI loops.
	 *
	 * @var float|null
	 */
	private $clock_start = null;

	/**
	 * Treat "now" as the start of a fresh request. Only for the WP-CLI runner, which
	 * also resets PHP's own timer with set_time_limit() before every batch.
	 */
	public function restart_clock() {
		$this->clock_start = $this->now();
	}

	/**
	 * Now in seconds (float).
	 *
	 * @return float
	 */
	public function now() {
		return microtime( true );
	}

	/**
	 * When the current PHP request started.
	 *
	 * @return float
	 */
	public function request_start() {
		if ( null !== $this->clock_start ) {
			return $this->clock_start;
		}
		if ( isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ) {
			$start = (float) $_SERVER['REQUEST_TIME_FLOAT'];
			// Guard against clock skew / bogus values: never "older" than an hour or in the future.
			$now = $this->now();
			if ( $start > 0 && $start <= $now && $now - $start < 3600 ) {
				return $start;
			}
		}
		return $this->now();
	}

	/**
	 * Pause without using CPU (pacing between products).
	 *
	 * @param float $seconds Seconds.
	 */
	public function sleep( $seconds ) {
		self::pause( $seconds );
	}

	/**
	 * Can this host wait without using CPU?
	 *
	 * @return bool
	 */
	public function can_pause() {
		return function_exists( 'usleep' ) || function_exists( 'time_nanosleep' );
	}

	/**
	 * Sleep that works even when the host disabled usleep()/sleep() (disable_functions):
	 * then it falls back to time_nanosleep(), else it does not wait at all.
	 *
	 * @param float $seconds Seconds.
	 */
	public static function pause( $seconds ) {
		if ( $seconds <= 0 ) {
			return;
		}
		if ( function_exists( 'usleep' ) ) {
			usleep( (int) round( $seconds * 1000000 ) );
			return;
		}
		if ( function_exists( 'time_nanosleep' ) ) {
			$whole = (int) floor( $seconds );
			@time_nanosleep( $whole, (int) round( ( $seconds - $whole ) * 1000000000 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return;
		}
		// No way to wait without burning CPU: skip the pause (the batch is still time-boxed).
	}

	/**
	 * Bytes in use (allocated from the system — what memory_limit is enforced against).
	 *
	 * @return int
	 */
	public function memory_usage() {
		return memory_get_usage( true );
	}

	/**
	 * memory_limit in bytes; 0 = unlimited.
	 *
	 * @return int
	 */
	public function memory_limit() {
		$raw = (string) ini_get( 'memory_limit' );
		if ( '' === $raw || '-1' === trim( $raw ) ) {
			return 0;
		}
		$bytes = (int) self::to_bytes( $raw );
		return $bytes > 0 ? $bytes : 0;
	}

	/**
	 * max_execution_time in seconds; 0 = unlimited.
	 *
	 * @return int
	 */
	public function max_execution_time() {
		return max( 0, (int) ini_get( 'max_execution_time' ) );
	}

	/**
	 * 1-minute load average, or null when the host hides it.
	 *
	 * @return float|null
	 */
	public function load_average() {
		if ( ! function_exists( 'sys_getloadavg' ) ) {
			return null;
		}
		$load = @sys_getloadavg(); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- disabled on some hosts.
		return is_array( $load ) && isset( $load[0] ) && is_numeric( $load[0] ) ? (float) $load[0] : null;
	}

	/**
	 * Number of CPU cores, or 0 when it cannot be known (cached for a day).
	 *
	 * Unknown is NOT treated as "1 core": on shared hosts /proc is often hidden while the
	 * load average is that of the whole machine, and dividing it by 1 made the old engine
	 * believe the server was permanently overloaded (the job never started).
	 *
	 * @return int
	 */
	public function cpu_count() {
		static $count = null;
		if ( null !== $count ) {
			return $count;
		}
		$cached = function_exists( 'get_transient' ) ? get_transient( 'dsd_cpu_count' ) : false;
		if ( false !== $cached && '' !== $cached ) {
			$count = max( 0, (int) $cached );
			return $count;
		}
		$count = 0;
		if ( @is_readable( '/proc/cpuinfo' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- open_basedir.
			$info = (string) @file_get_contents( '/proc/cpuinfo' ); // phpcs:ignore
			$n    = preg_match_all( '/^processor\s*:/m', $info );
			if ( $n > 0 ) {
				$count = $n;
			}
		}
		if ( function_exists( 'set_transient' ) ) {
			set_transient( 'dsd_cpu_count', $count, DAY_IN_SECONDS );
		}
		return $count;
	}

	/**
	 * "128M" → bytes.
	 *
	 * @param string $value Shorthand.
	 * @return int
	 */
	public static function to_bytes( $value ) {
		$value = strtolower( trim( (string) $value ) );
		$num   = (float) $value;
		$unit  = substr( $value, -1 );
		switch ( $unit ) {
			case 'g':
				$num *= 1024;
				// fall through.
			case 'm':
				$num *= 1024;
				// fall through.
			case 'k':
				$num *= 1024;
		}
		return (int) $num;
	}
}

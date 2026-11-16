<?php
/**
 * Site-wide worker lock.
 *
 * Uses MySQL GET_LOCK, which the server releases automatically if PHP dies mid-batch
 * (timeout, fatal, killed worker) — so a crash can never leave the queue stuck.
 * Falls back to an expiring option lock on databases without GET_LOCK.
 *
 * @package SmartBulkDiscount
 */

namespace SmartBulkDiscount;

defined( 'ABSPATH' ) || exit;

/**
 * Non-blocking named lock.
 */
final class Lock {

	/**
	 * Held locks in this request: name => mode.
	 *
	 * @var array
	 */
	private static $held = array();

	/**
	 * Try to take the lock without waiting.
	 *
	 * @param string $name Name.
	 * @param int    $ttl  Fallback lock expiry in seconds.
	 * @return bool
	 */
	public static function acquire( $name, $ttl = 120 ) {
		global $wpdb;
		$key    = self::key( $name );
		$result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $key ) );
		if ( '1' === (string) $result ) {
			self::$held[ $name ] = 'mysql';
			return true;
		}
		if ( '0' === (string) $result ) {
			return false;
		}
		// GET_LOCK unavailable: option lock.
		$option = 'dsd_lock_' . $name;
		$now    = time();
		if ( add_option( $option, $now + $ttl, '', false ) ) {
			self::$held[ $name ] = 'option';
			return true;
		}
		$expires = (int) get_option( $option, 0 );
		if ( $expires < $now ) {
			delete_option( $option );
			if ( add_option( $option, $now + $ttl, '', false ) ) {
				self::$held[ $name ] = 'option';
				return true;
			}
		}
		return false;
	}

	/**
	 * Release.
	 *
	 * @param string $name Name.
	 */
	public static function release( $name ) {
		global $wpdb;
		if ( ! isset( self::$held[ $name ] ) ) {
			return;
		}
		if ( 'mysql' === self::$held[ $name ] ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::key( $name ) ) );
		} else {
			delete_option( 'dsd_lock_' . $name );
		}
		unset( self::$held[ $name ] );
	}

	/**
	 * Is it free right now (for status display)?
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	public static function is_free( $name ) {
		global $wpdb;
		$r = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', self::key( $name ) ) );
		return '0' !== (string) $r;
	}

	/**
	 * DB-unique key (max 64 chars).
	 *
	 * @param string $name Name.
	 * @return string
	 */
	private static function key( $name ) {
		global $wpdb;
		return substr( 'dsd_' . md5( DB_NAME . $wpdb->prefix ) . '_' . $name, 0, 64 );
	}
}

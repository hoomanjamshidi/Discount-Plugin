<?php
/**
 * Plugin settings (single option, not autoloaded).
 *
 * @package SmartBulkDiscount
 */

namespace SmartBulkDiscount;

defined( 'ABSPATH' ) || exit;

/**
 * Typed access to the `dsd_settings` option.
 */
final class Settings {

	const OPTION = 'dsd_settings';

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// auto = adapt to the host, gentle = shared/cheap host, fast = dedicated server.
			'profile'                  => 'auto',
			// Skip a batch entirely when the server is overloaded.
			'pause_on_high_load'       => true,
			// The open admin page helps push the queue when WP-Cron is slow.
			'admin_runner'             => true,
			// Self-chaining loopback requests (like WP background processing).
			'loopback'                 => true,
			// Page-cache plugins: no purge per product during our batches; one purge per phase.
			'cache_compat'             => true,
			// Default rounding for new campaigns (0 = none).
			'round_step'               => 0,
			'delete_data_on_uninstall' => false,
		);
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Validate and save a (partial) settings array.
	 *
	 * @param array $input Raw input.
	 * @return array Saved settings.
	 */
	public static function update( array $input ) {
		$current = self::all();
		if ( isset( $input['profile'] ) && in_array( $input['profile'], array( 'auto', 'gentle', 'fast' ), true ) ) {
			$current['profile'] = $input['profile'];
		}
		foreach ( array( 'pause_on_high_load', 'admin_runner', 'loopback', 'cache_compat', 'delete_data_on_uninstall' ) as $bool ) {
			if ( isset( $input[ $bool ] ) ) {
				$current[ $bool ] = (bool) $input[ $bool ];
			}
		}
		if ( isset( $input['round_step'] ) ) {
			$current['round_step'] = PriceCalculator::sanitize_step( $input['round_step'] );
		}
		update_option( self::OPTION, $current, false );
		return $current;
	}
}

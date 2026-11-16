<?php
/**
 * Uninstall: only removes data when the user opted in (Settings → delete data).
 *
 * @package SmartBulkDiscount
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$dsd_settings = get_option( 'dsd_settings', array() );
if ( empty( $dsd_settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

// Never drop the price backups while a campaign may still have discounted prices on products.
$dsd_live = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dsd_campaigns WHERE status IN ('preparing','applying','verifying','active','reverting','verifying_revert','paused')" ); // phpcs:ignore
if ( $dsd_live > 0 ) {
	return;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}dsd_items" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}dsd_campaigns" );
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_dsd_campaign'" );
// phpcs:enable

foreach ( array( 'dsd_settings', 'dsd_db_version', 'dsd_governor', 'dsd_heartbeat', 'dsd_on_index', 'dsd_on_version', 'dsd_kick_secret', 'dsd_last_skip', 'dsd_lock_worker', 'dsd_not_before', 'dsd_inflight', 'dsd_kick_token', 'dsd_kick_sent', 'dsd_last_purge' ) as $dsd_option ) {
	delete_option( $dsd_option );
}
delete_transient( 'dsd_watchdog_ok' );
delete_transient( 'dsd_cpu_count' );

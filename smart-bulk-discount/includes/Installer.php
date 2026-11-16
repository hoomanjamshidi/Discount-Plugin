<?php
/**
 * Activation, schema and deactivation.
 *
 * @package SmartBulkDiscount
 */

namespace SmartBulkDiscount;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the two plugin tables.
 */
final class Installer {

	/**
	 * Activation hook.
	 */
	public static function activate() {
		self::install();
		// The watchdog is (re)created on the next request, once Action Scheduler is initialised.
		delete_transient( 'dsd_watchdog_ok' );
		delete_transient( 'dsd_cpu_count' );
		delete_option( 'dsd_not_before' );
		delete_option( 'dsd_inflight' );
		// Timers are removed on deactivation (also by an older copy of the plugin): re-arm
		// exact start/end times of campaigns that are scheduled or running.
		self::rearm_timers();
	}

	/**
	 * Re-create start/end timers for scheduled and live campaigns.
	 */
	public static function rearm_timers() {
		$repo = new Repository();
		foreach ( $repo->by_status( array_merge( array( Campaign::SCHEDULED ), Campaign::LIVE ) ) as $c ) {
			if ( Campaign::SCHEDULED === $c->status && null !== $c->starts_at && $c->starts_at > time() ) {
				Queue::schedule_timer( $c->id, $c->starts_at );
			}
			if ( null !== $c->ends_at && $c->ends_at > time() ) {
				Queue::schedule_timer( $c->id, $c->ends_at );
			}
		}
	}

	/**
	 * Deactivation: stop timers. Data (and any live discount) is left intact;
	 * live campaigns resume when the plugin is re-activated.
	 */
	public static function deactivate() {
		Queue::unschedule_all();
	}

	/**
	 * Run dbDelta when the stored schema version is behind.
	 */
	public static function maybe_upgrade() {
		if ( (int) get_option( 'dsd_db_version', 0 ) < DSD_DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Create/upgrade tables.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset   = $wpdb->get_charset_collate();
		$campaigns = $wpdb->prefix . 'dsd_campaigns';
		$items     = $wpdb->prefix . 'dsd_items';

		$sql = "CREATE TABLE {$campaigns} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'draft',
  paused_status varchar(20) NOT NULL DEFAULT '',
  discount_type varchar(10) NOT NULL DEFAULT 'percent',
  discount_value decimal(19,4) NOT NULL DEFAULT 0,
  target_type varchar(20) NOT NULL DEFAULT 'products',
  target longtext NULL,
  options longtext NULL,
  starts_at datetime NULL DEFAULT NULL,
  ends_at datetime NULL DEFAULT NULL,
  repeat_mode varchar(10) NOT NULL DEFAULT 'none',
  cursor_id bigint(20) unsigned NOT NULL DEFAULT 0,
  stats longtext NULL,
  log longtext NULL,
  run_count int(10) unsigned NOT NULL DEFAULT 0,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY status (status)
) {$charset};
CREATE TABLE {$items} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  campaign_id bigint(20) unsigned NOT NULL,
  product_id bigint(20) unsigned NOT NULL,
  parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(12) NOT NULL DEFAULT 'pending',
  has_backup tinyint(1) NOT NULL DEFAULT 0,
  orig_regular varchar(32) NULL DEFAULT NULL,
  orig_sale varchar(32) NULL DEFAULT NULL,
  orig_sale_from bigint(20) NULL DEFAULT NULL,
  orig_sale_to bigint(20) NULL DEFAULT NULL,
  new_sale varchar(32) NULL DEFAULT NULL,
  attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
  note varchar(190) NOT NULL DEFAULT '',
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY campaign_product (campaign_id,product_id),
  KEY campaign_status (campaign_id,status,id),
  KEY product_id (product_id)
) {$charset};";

		dbDelta( $sql );
		update_option( 'dsd_db_version', DSD_DB_VERSION, false );

		if ( false === get_option( 'dsd_settings', false ) ) {
			add_option( 'dsd_settings', Settings::defaults(), '', false );
		}
		if ( false === get_option( 'dsd_kick_secret', false ) ) {
			add_option( 'dsd_kick_secret', wp_generate_password( 32, false ), '', false );
		}
	}
}

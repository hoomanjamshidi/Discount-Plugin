<?php
/**
 * Composition root: wires services to hooks.
 *
 * @package SmartBulkDiscount
 */

namespace SmartBulkDiscount;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton bootstrap.
 */
final class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	/** @var Repository */
	public $repo;
	/** @var TargetResolver */
	public $resolver;
	/** @var Engine */
	public $engine;
	/** @var CampaignService */
	public $service;

	/**
	 * Instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Boot after all plugins are loaded.
	 */
	public function boot() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'notice_missing_wc' ) );
			return;
		}

		$this->repo     = new Repository();
		$this->resolver = new TargetResolver();
		$this->engine   = new Engine( $this->repo, $this->resolver, new Environment() );
		$this->service  = new CampaignService( $this->repo, $this->resolver );

		Installer::maybe_upgrade();
		PriceGuard::register();
		CacheCompat::register();

		// Background jobs.
		add_action( 'dsd_pump', array( $this, 'run_pump' ) );
		add_action( 'dsd_watchdog', array( Watchdog::class, 'run' ) );
		add_action( 'dsd_timer', array( $this, 'on_timer' ) );
		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval
		add_action( 'action_scheduler_init', array( Queue::class, 'ensure_watchdog' ) );
		add_action( 'init', array( $this, 'ensure_cron_fallback' ), 20 );

		// Loopback runner (secret key, no login needed — it only runs a governed batch).
		add_action( 'wp_ajax_nopriv_dsd_kick', array( $this, 'handle_kick' ) );
		add_action( 'wp_ajax_dsd_kick', array( $this, 'handle_kick' ) );

		add_action(
			'rest_api_init',
			function () {
				( new RestController( $this->service, $this->repo, $this->engine ) )->register();
			}
		);

		if ( is_admin() ) {
			( new AdminPage() )->register();
			( new Export( $this->repo ) )->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$cli = new Cli( $this->service, $this->repo, $this->engine );
			\WP_CLI::add_command( 'dsd list', array( $cli, 'list_' ) );
			\WP_CLI::add_command( 'dsd create', array( $cli, 'create' ) );
			\WP_CLI::add_command( 'dsd campaign', array( $cli, 'campaign' ) );
			\WP_CLI::add_command( 'dsd run', array( $cli, 'run' ) );
			\WP_CLI::add_command( 'dsd watchdog', array( $cli, 'watchdog' ) );
			\WP_CLI::add_command( 'dsd status', array( $cli, 'status' ) );
			\WP_CLI::add_command( 'dsd export', array( $cli, 'export' ) );
		}
	}

	/**
	 * Queue callback.
	 */
	public function run_pump() {
		$this->engine->pump( array( 'source' => 'queue' ) );
	}

	/**
	 * Exact start/end timer.
	 */
	public function on_timer() {
		Watchdog::check_times( $this->repo );
	}

	/**
	 * Loopback endpoint.
	 */
	public function handle_kick() {
		$key    = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- shared-secret auth.
		$secret = (string) get_option( 'dsd_kick_secret', '' );
		if ( '' === $secret || ! hash_equals( $secret, $key ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}
		$token = isset( $_GET['token'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_GET['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		if ( ! Queue::token_current( $token ) ) {
			// A newer chain replaced this one: stop here so chains never pile up.
			wp_die( '', '', array( 'response' => 200 ) );
		}
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
		nocache_headers();

		// Wait out the rest between batches here, sleeping (no CPU). The caller never waits.
		$max   = Queue::max_kick_delay();
		$delay = isset( $_GET['delay'] ) ? absint( $_GET['delay'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$wait  = min( $max, max( $delay, Engine::cooling_left() ) );
		if ( $wait > 0 ) {
			Environment::pause( $wait );
			if ( ! Queue::token_current( $token ) ) {
				wp_die( '', '', array( 'response' => 200 ) );
			}
		}

		$r = $this->engine->pump(
			array(
				'source' => 'loopback',
				'token'  => $token,
			)
		);
		if ( empty( $r['ran'] ) && isset( $r['reason'] ) && in_array( $r['reason'], array( 'cooling', 'error' ), true ) ) {
			// Still resting: re-arm this same chain (one sleeping request at a time).
			Queue::kick( min( $max, max( 1, (int) $r['wait'] ) ), $token );
		}
		wp_die( '', '', array( 'response' => 200 ) );
	}

	/**
	 * Two-minute WP-Cron schedule for the no-Action-Scheduler fallback.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public function cron_schedules( $schedules ) {
		$schedules['dsd_two_minutes'] = array(
			'interval' => 2 * MINUTE_IN_SECONDS,
			'display'  => 'Every 2 minutes (Smart Bulk Discount)',
		);
		return $schedules;
	}

	/**
	 * When Action Scheduler is missing, make sure the WP-Cron watchdog exists.
	 */
	public function ensure_cron_fallback() {
		if ( ! Queue::has_as() ) {
			Queue::ensure_watchdog();
		}
	}

	/**
	 * Admin notice.
	 */
	public function notice_missing_wc() {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'افزونهٔ تخفیف گروهی برای کار به ووکامرس نیاز دارد.', 'smart-bulk-discount' ) . '</p></div>';
	}
}

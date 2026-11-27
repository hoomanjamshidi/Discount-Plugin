<?php
/**
 * Admin menu + React app mount.
 *
 * @package SmartBulkDiscount
 */

namespace SmartBulkDiscount;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the "تخفیف گروهی" admin page.
 */
final class AdminPage {

	const SLUG = 'smart-bulk-discount';

	/** @var string */
	private $hook = '';

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DSD_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Top-level menu — easy to find for non-technical users.
	 */
	public function menu() {
		$this->hook = add_menu_page(
			__( 'تخفیف گروهی', 'smart-bulk-discount' ),
			__( 'تخفیف گروهی', 'smart-bulk-discount' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-tag',
			56
		);
	}

	/**
	 * Mount point.
	 */
	public function render() {
		echo '<div class="wrap dsd-wrap"><div id="dsd-root" dir="rtl"><div class="dsd-boot">' . esc_html__( 'در حال بارگذاری…', 'smart-bulk-discount' ) . '</div></div></div>';
	}

	/**
	 * Enqueue the compiled app on our page only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function assets( $hook ) {
		if ( $hook !== $this->hook ) {
			return;
		}
		$js  = DSD_DIR . 'build/index.js';
		$css = DSD_DIR . 'build/index.css';
		$ver = file_exists( $js ) ? DSD_VERSION . '.' . filemtime( $js ) : DSD_VERSION;

		if ( file_exists( $css ) ) {
			wp_enqueue_style( 'dsd-admin', DSD_URL . 'build/index.css', array(), $ver );
		}
		wp_enqueue_script( 'dsd-admin', DSD_URL . 'build/index.js', array(), $ver, true );

		$tz = Clock::timezone();
		$boot = array(
			'restRoot'    => esc_url_raw( rest_url( RestController::NS . '/' ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'currency'    => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			'decimals'    => wc_get_price_decimals(),
			'timezone'    => $tz->getName(),
			'now'         => time(),
			'nowLocal'    => Clock::local( time() ),
			'productsUrl' => admin_url( 'edit.php?post_type=product' ),
			'version'     => DSD_VERSION,
			'fontUrl'     => DSD_URL . 'build/fonts/',
			'exportUrl'   => admin_url( 'admin-post.php' ),
			'exportNonce' => wp_create_nonce( Export::NONCE ),
		);
		wp_add_inline_script( 'dsd-admin', 'window.DSD_BOOT = ' . wp_json_encode( $boot ) . ';', 'before' );
	}

	/**
	 * "Open" link on the plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'باز کردن', 'smart-bulk-discount' ) . '</a>' );
		return $links;
	}
}

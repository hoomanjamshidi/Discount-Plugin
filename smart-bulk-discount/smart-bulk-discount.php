<?php
/**
 * Plugin Name:       Smart Bulk Discount — تخفیف گروهی هوشمند
 * Description:       تخفیف درصدی یا مبلغی روی محصولات منتخب، دسته‌بندی‌ها یا همهٔ محصولات موجود، با زمان‌بندی شمسی و اجرای آرام در پس‌زمینه بر اساس منابع هاست.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Hooman Jamshidi
 * Update URI:        false
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       smart-bulk-discount
 * Domain Path:       /languages
 * WC requires at least: 7.0
 * WC tested up to:   11.3
 *
 * @package SmartBulkDiscount
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'DSD_FILE' ) ) {
	// An older copy of this plugin (previous folder name) is still active. Both copies share
	// the same data, so only one may run: ask the admin to deactivate the old one.
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'نسخهٔ قدیمی افزونهٔ «تخفیف گروهی» هنوز فعال است. لطفاً آن را غیرفعال و حذف کنید؛ کمپین‌ها و قیمت‌های اصلی ذخیره‌شده حفظ می‌شوند.', 'smart-bulk-discount' ) . '</p></div>';
		}
	);
	return;
}

define( 'DSD_VERSION', '1.1.0' );
define( 'DSD_DB_VERSION', 1 );
define( 'DSD_FILE', __FILE__ );
define( 'DSD_DIR', plugin_dir_path( __FILE__ ) );
define( 'DSD_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'SmartBulkDiscount\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$relative = str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) );
		$file     = DSD_DIR . 'includes/' . $relative . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'SmartBulkDiscount\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SmartBulkDiscount\\Installer', 'deactivate' ) );

// Declare HPOS compatibility (we only touch products, which are still posts).
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain( 'smart-bulk-discount', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
		\SmartBulkDiscount\Plugin::instance()->boot();
	},
	20
);

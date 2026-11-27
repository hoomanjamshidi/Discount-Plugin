<?php
/**
 * Unit bootstrap: load only pure classes (no WordPress).
 *
 * @package SmartBulkDiscount\Tests
 */

// phpcs:ignoreFile

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );

require_once __DIR__ . '/../../vendor/autoload.php';

$dir = __DIR__ . '/../../smart-bulk-discount/includes/';
require_once $dir . 'PriceCalculator.php';
require_once $dir . 'Schedule.php';
require_once $dir . 'Environment.php';
require_once $dir . 'ResourceGovernor.php';
require_once $dir . 'Clock.php';
require_once $dir . 'CacheCompat.php';
require_once $dir . 'Zip.php';
require_once $dir . 'Xlsx.php';
require_once $dir . 'Report.php';
require_once __DIR__ . '/FakeEnvironment.php';

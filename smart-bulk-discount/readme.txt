=== Smart Bulk Discount — تخفیف گروهی هوشمند ===
Tags: woocommerce, discount, bulk sale, schedule, jalali
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bulk percent/fixed discounts on chosen products, categories or every in-stock product, with a Jalali scheduler and a background engine that adapts to the host's limits.

== Description ==

* Discount by percent or fixed amount, optional rounding down to a nice number.
* Targets: hand-picked products (search by name, SKU or ID; the selection basket survives every search), one or more categories (with sub-categories and exclusions), or all in-stock products.
* Timing: start now (open-ended or until a date), a date range, or a daily/weekly repeating window. Jalali (Shamsi) date picker, site timezone.
* Smart resource management: work runs in short, paced background batches that leave the CPU and database to shoppers (at most 50% busy in "auto", 30% in "gentle"). The engine measures congestion itself (product save latency, memory headroom after other plugins loaded) and slows down when the host struggles; the load average is only a hint and never stalls the job. Only one batch runs at a time site-wide.
* Dates are immune to Persian date plugins and to the browser's timezone; outdated Iran DST rules on old servers are corrected.
* Guaranteed completeness: after writing, every price is re-read from the database and any mismatch is retried (up to 3 times); the campaign becomes "active" only when nothing is left pending.
* Safe ending: original prices are backed up before the first write and restored exactly at the end. If an admin edited a product meanwhile, their price is kept. From the moment a campaign ends, the storefront and cart stop showing its price even before the last product is restored.
* One campaign per product: overlapping campaigns never overwrite each other.
* Campaign report: download every product of a campaign (ID, SKU, name, shop link, regular price, price before the campaign, campaign price, discount, status) as an Excel file or print it / save it as PDF.
* WP-CLI: `wp dsd list|create|campaign|run|watchdog|status`.

== Installation ==

1. Upload the `smart-bulk-discount` folder to `/wp-content/plugins/` and activate it (WooCommerce required).
2. Open "تخفیف گروهی" in the admin menu.
3. Optional on large catalogs: make sure WP-Cron runs (or a real server cron hits `wp-cron.php` every minute).

== Development ==

Complete PHP and React/TypeScript sources, build scripts, tests, screenshots and the
Persian installation guide are available at:
https://github.com/hoomanjamshidi/Discount-Plugin

The admin source is in `admin-app/`. Run `npm ci` and `npm run build` there to
regenerate `smart-bulk-discount/build/`. Run `bash docker/package.sh` at the
repository root to build the installable ZIP. See README.md for prerequisites.

== Frequently Asked Questions ==

= Can I deactivate the plugin while a campaign is active? =
Stop the campaign first and wait until it shows "تمام شده". Deactivation leaves discounted prices on products; they are restored when the plugin is re-activated and the campaign is stopped.

= What happens if the site goes down during a campaign? =
Nothing is lost: progress is stored per product. When the site comes back, the watchdog resumes the work; if the end time passed meanwhile, prices are restored.

== Changelog ==

= 1.2.0 =
* Campaign report export: Excel (.xlsx, right-to-left, filterable, clickable links) and a printable report for "Save as PDF", from the campaign page. Prices come from the backup taken before the first write, so the report stays exact after the campaign ended.
* New admin-only REST route `GET /campaigns/{id}/report` (paged, read-only).
* Investigation of "the regular price became the discount price": not caused by the plugin (it never writes the regular price); reproducible only with an external price writer. Read-only check: docs/diagnose-regular-price.sql. New test scenarios s20–s22.

= 1.1.0 =
* Resource governor rewritten: pacing between products, latency-based congestion detection, memory budget from real headroom, load average no longer blocks the job (fixes jobs stuck at start and 503 errors on shared hosts).
* One loopback chain at a time; admin page polling no longer piles up requests.
* Recovery from fatal errors raised by other plugins during a batch; the error is shown on the System page.
* Prepare phase reads products in fixed-size chunks (fast on large databases).
* Dates: no longer affected by Persian date plugins (wp_date/date_i18n filters), browser timezone or outdated Iran DST data; strict date parsing.
* Page-cache plugins (WP Rocket, LiteSpeed Cache, W3TC, WP Super Cache, WP Fastest Cache, SiteGround, Breeze, Hummingbird, Cache Enabler, WP-Optimize, Swift, Comet, Nginx Helper, Cloudflare): no purge per product during campaign batches; one purge when the discount is live, one when it ends, one when prices are restored.
* Countdown "end discount" button shows progress and no longer stalls; tooltips and dropdowns no longer clipped; date picker opens on today.
* Renamed to Smart Bulk Discount. Author: Hooman Jamshidi.

= 1.0.0 =
* First release.

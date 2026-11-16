#!/usr/bin/env bash
# One-time install of WordPress + WooCommerce + this plugin inside the Docker stack.
set -euo pipefail
cd "$(dirname "$0")"
export MSYS_NO_PATHCONV=1
DC="docker compose -f docker-compose.yml"
WP="$DC exec -T cli wp --path=/var/www/html"

echo "Waiting for WordPress files..."
for i in $(seq 1 60); do
  if $DC exec -T cli test -f /var/www/html/wp-config.php; then break; fi
  sleep 2
done

if ! $WP core is-installed 2>/dev/null; then
  $WP core install --url=http://localhost:8088 --title="Test Shop" \
    --admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email
fi

$WP option update timezone_string "Asia/Tehran"
$WP option update permalink_structure "/%postname%/"
$WP plugin is-installed woocommerce || $WP plugin install woocommerce
$WP plugin activate woocommerce
$WP option update woocommerce_currency IRT || true
$WP option update woocommerce_price_num_decimals 0
$WP option update woocommerce_coming_soon no || true
$WP plugin activate smart-bulk-discount
$WP plugin list --status=active
echo "Admin: http://localhost:8088/wp-admin  (admin / admin)"

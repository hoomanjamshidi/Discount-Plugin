#!/usr/bin/env bash
# Generate the test catalog (default 3000 products) and snapshot the DB so every
# test run can start from the exact same prices.
set -euo pipefail
cd "$(dirname "$0")"
export MSYS_NO_PATHCONV=1
N="${1:-3000}"
DC="docker compose -f docker-compose.yml"
$DC exec -T cli wp --path=/var/www/html eval-file /tests/integration/seed.php "$N"
$DC exec -T cli wp --path=/var/www/html db export /tmp/seed.sql --quiet
$DC exec -T cli sh -c 'mkdir -p /var/www/html/wp-content/dsd-snapshots && cp /tmp/seed.sql /var/www/html/wp-content/dsd-snapshots/seed.sql'
echo "Snapshot saved (wp-content/dsd-snapshots/seed.sql)"

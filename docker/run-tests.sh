#!/usr/bin/env bash
# Full test run: PHP unit (PHP 8.x + 7.4), frontend unit, admin build, integration + load.
set -uo pipefail
cd "$(dirname "$0")/.."
export MSYS_NO_PATHCONV=1
DC="docker compose -f docker/docker-compose.yml"
fail=0

echo "== PHP unit tests (container PHP)"
$DC exec -T phpunit sh -c 'composer install -q --no-interaction && vendor/bin/phpunit -c phpunit.xml.dist' || fail=1

echo "== PHP unit tests (PHP 7.4 compatibility)"
docker run --rm -v "$(pwd):/app" -w /app php:7.4-cli vendor/bin/phpunit -c phpunit.xml.dist || fail=1

echo "== PHP lint (7.4)"
docker run --rm -v "$(pwd)/smart-bulk-discount:/p" php:7.4-cli sh -c 'for f in /p/*.php /p/includes/*.php; do php -l "$f" >/dev/null || exit 1; done && echo ok' || fail=1

echo "== Admin app: typecheck, vitest, build"
(cd admin-app && npm run typecheck && npx vitest run && npm run build) || fail=1

echo "== Integration + load (3000 products)"
python3 tests/integration/run.py "$@" || fail=1

exit $fail

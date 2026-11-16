# Smart Bulk Discount — project rules

WooCommerce plugin that puts bulk, scheduled discounts (percent or fixed) on chosen
products, categories, or all in‑stock products, processed in governed background batches
so the host never hits 503/timeouts. The store has ~3000 products (plus variations).

## Layout

| Path | What |
|---|---|
| `smart-bulk-discount/` | The distributable plugin (zip this folder). PHP only + compiled `build/`. |
| `admin-app/` | React + Tailwind (Tarhak components) source. `npm run build` writes to `smart-bulk-discount/build/`. |
| `tests/unit/` | PHPUnit, no WordPress needed (pure logic: pricing, governor, schedule). |
| `tests/integration/` | WP‑CLI `eval-file` scenario scripts run against the Docker stack with real WooCommerce. |
| `docker/` | Constrained test host (128M memory, 30s exec time), seed + test runners. |
| `Codes/Discount/.claude/skills/` | Bundled WordPress agent skills (GPL, from WordPress/agent-skills) + project skills `dsd-*`. |

## Non‑negotiable rules

1. **PHP 7.4 compatible.** No `match`, enums, readonly, named args, union types, `str_contains`.
2. **Prefix everything** `dsd_` (functions/options/hooks/meta/tables), namespace `SmartBulkDiscount`.
3. **Security:** every REST route has `permission_callback` checking `manage_woocommerce`; sanitize in, escape out; all SQL through `$wpdb->prepare()` (no `%i`, WP < 6.2 support).
4. **Never do bulk work inside a page request without the governor.** Every loop over products
   calls `$g->tick()`, `if ( $g->should_yield() ) return;`, then `$g->pace()` (rests in proportion
   to the work so shoppers keep CPU/DB). Load average may only *slow* the job, never stop it.
   Every runner respects the site-wide rest `dsd_not_before`.
5. **Idempotent jobs.** Any batch can be killed at any line and re‑run; item status in
   `{prefix}dsd_items` is the source of truth. Never store progress only in memory.
6. **Never lose the original price.** Originals are backed up in `dsd_items` before the first
   write; revert only touches a product if its current sale price still equals what we wrote.
7. **One campaign per product.** A product locked by another live campaign is skipped, not overwritten.
8. **Times are stored in UTC** (`DATETIME`). Site time goes ONLY through `Clock` (`Clock::local()`,
   `Clock::parse()`, `Clock::timezone()`): never `wp_date()` / `date_i18n()` (Persian date plugins
   filter them into Jalali/Persian digits). The UI keeps wall-clock values as floating UTC Dates
   (`src/lib/format.ts`) and never reads the browser timezone. The UI shows Jalali.
9. **UI copy is Persian, RTL, plain words** — no WordPress jargon. Admin CSS must stay scoped to `#dsd-root`
   (Tailwind preflight must not leak into wp‑admin).
10. **A change is done only when** unit tests pass, the 3000‑product integration suite passes
    (`bash docker/run-tests.sh`), and the admin app builds.

## Name

Plugin: **Smart Bulk Discount** (تخفیف گروهی هوشمند), author **Hooman Jamshidi**, slug/text domain
`smart-bulk-discount`. The `dsd_` prefix stays (existing installs keep their data and backups).

## Commands

```bash
docker compose -f docker/docker-compose.yml up -d
bash docker/setup.sh          # WP + WooCommerce + plugin, once
bash docker/seed.sh 3000      # 3000 products (simple + variable), once
bash docker/run-tests.sh      # unit + integration + load suites
cd admin-app && npm run build # rebuild admin UI
```

## Skills to load

- Engine, scheduling, governor changes → `Codes/Discount/.claude/skills/dsd-campaign-engine`
- Admin UI work → `Codes/Discount/.claude/skills/dsd-admin-ui`
- Running/adding tests → `Codes/Discount/.claude/skills/dsd-testing`
- General WP practice → `wp-plugin-development`, `wp-rest-api`, `wp-performance`, `wp-wpcli-and-ops`

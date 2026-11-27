---
name: dsd-testing
description: "Use when running or adding tests for Smart Bulk Discount: PHPUnit unit tests, the Docker WooCommerce stack with a 3000-product catalog, integration/load scenarios (tests/integration/run.py), crash/kill tests, web-under-traffic tests, and frontend vitest."
---

# Smart Bulk Discount — testing

## Layers

| Layer | Where | Needs | What it proves |
|---|---|---|---|
| Unit (PHP) | `tests/unit/*Test.php` | `phpunit` container | price math, rounding, schedule windows, governor budgets/AIMD |
| Unit (TS) | `admin-app/src/**/*.test.ts` | node | Jalali formatting, digit parsing, wizard validation |
| Integration | `tests/integration/run.py` s01–s10 | Docker stack + snapshot | every product's price vs an **independent** expectation (`probe.php`) |
| Web/load | `run.py` s11–s12 | Docker stack | real Apache (8 workers, 128M, 30s) under shopper traffic: zero 5xx, batch time/memory caps |
| Difficult hosts | `run.py` s14–s17 | Docker stack + `host-sim.php` mu-plugin | 1 CPU + heavy site + slow save hooks + hidden /proc under traffic (no errors, p95 bounded); loopback blocked by firewall; fatal error in another plugin; Persian date plugin |
| Report export | `run.py` s20 | Docker stack | `/campaigns/{id}/report`: every item once, true "before" prices from the backup (also after the end), shop links, paging, 401/403/404 |
| Regular price | `run.py` s21–s22 | Docker stack + `host-sim.php` (`regular_from_price`) | admin edits / Quick Edit / REST round-trips during the campaign and after the end time never change the regular price; a buggy external sync that does is caught by the suite and by `docs/diagnose-regular-price.sql` |

## Setup (once)

```bash
docker compose -f docker/docker-compose.yml up -d
bash docker/setup.sh           # WordPress + WooCommerce + plugin (admin/admin)
bash docker/seed.sh 3000       # ~10 min; writes wp-content/dsd-snapshots/seed.sql
```

## Run

```bash
bash docker/run-tests.sh                 # everything
python tests/integration/run.py s05 s11  # selected scenarios (substring match)
```

Report: `tests/integration/.out/report.json`.

## Host simulator

`tests/integration/host-sim.php` is mounted as a must-use plugin. A scenario turns knobs on with
`set_sim(boot_mb=…, save_ms=…, hide_proc=True, block_loopback=True, no_cron=True, fatal_on=[ids],
jalali_dates=True, regular_from_price=True)` and limits the web container with `cpu_limit(1)`; `restore()` resets it.

## Rules for new scenarios

1. Start with `restore()` (fresh snapshot) and `snapshot(name)` (price state before).
2. Expectations come from `probe.php` (its own eligibility + price formula). **Never** import plugin
   classes into the probe's expectation logic, or a plugin bug will hide itself.
3. Assert both directions: eligible products have exactly the new price *and* everything else is untouched
   (`check_applied`), and after stop every price field equals the snapshot (`check_restored`).
4. Workers run with host limits (`run_worker(mem, exec_time)`). Note: WP-CLI lifts `memory_limit`,
   so memory caps are asserted in the web scenario (s11) via `/system` history.
5. Variable parents: their `_price` is WooCommerce's synced min-of-variations and is expected to move.

## Known timings (reference machine, 3000 products = 3339 priced rows)

Recorded in `report.json`; compare after engine changes. A regression of >2× wall time or any
batch over 75% of `max_execution_time` fails the suite.

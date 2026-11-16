---
name: dsd-campaign-engine
description: "Use when changing how Smart Bulk Discount applies, verifies, schedules or reverts discounts: Engine phases, ResourceGovernor budgets, Queue/Watchdog/Lock, PriceGuard, TargetResolver SQL, CampaignService lifecycle. Covers the invariants that keep 3000+ products safe on weak hosts."
---

# Smart Bulk Discount — campaign engine

## When to use

Any change under `smart-bulk-discount/includes/` that touches price writes, batching,
scheduling, product selection, or campaign states.

## Mental model (read first)

```
CampaignService (REST/CLI commands) ──► campaign row (status) ──► Queue (Action Scheduler / WP-Cron / loopback)
                                                                  │
Watchdog (timers + every 2 min) ──► time transitions ──────────────┤
                                                                  ▼
                                      Engine::pump()  ── Lock('worker') ── ResourceGovernor
                                         │ preparing → applying → verifying → active
                                         │ reverting → verifying_revert → ended | scheduled (repeat)
                                         ▼
                              dsd_items rows (source of truth) + product meta _dsd_campaign
PriceGuard: storefront filters hide our price for any product whose campaign is not "on".
```

- One `pump()` = one governed batch. It may cross phases and campaigns while budget remains.
- Priority: reverting > verifying_revert > verifying > applying > preparing (ending on time is money).
- `dsd_items.status`: pending → applied | skipped | failed ; applied → reverted | kept ; pending → cancelled.

## Invariants — never break these

1. **Backup before write.** `Engine::apply_item()` stores `orig_*` in `dsd_items` *before* `$product->save()`.
2. **Idempotency.** Every step must be safe to re-run after a kill at any line. Re-applying a product
   already carrying our price and lock is a no-op; re-preparing uses `INSERT IGNORE`.
3. **Revert only our own price.** If the current sale price ≠ `new_sale`, mark `kept`, release the lock, leave the price.
4. **Single worker.** `Lock::acquire('worker')` (MySQL `GET_LOCK`, auto-released if PHP dies). Never add a second concurrent writer.
5. **Governor after every item.** Loops call `$g->tick()` then `if ( $g->should_yield() ) return;`.
   Never extend `max_execution_time` or raise `memory_limit` in web requests.
6. **Verify phases read the DB with SQL**, not object caches. A campaign is `active` only when zero items are pending.
7. **PriceGuard** must stay O(1) per price read (in-memory index; early return when no `_dsd_campaign` meta).
8. **Category trees** come from `TargetResolver::with_descendants()` (direct SQL). Do not use
   `get_term_children()` — its cached hierarchy goes stale after bulk imports.
9. PHP 7.4 syntax only; all SQL via `$wpdb->prepare()` or integer-cast lists.
10. **Load products with `fresh_product()`**, never plain `wc_get_product()`, inside apply/revert.
    WooCommerce 10+ caches product instances; with Redis/Memcached a crashed request can leave a
    stale copy, and a stale "sale price" silently corrupts the backup or skips the restore.
11. **Claim before write:** `apply_item()` writes `_dsd_campaign` with `update_post_meta()` *before*
    `$product->save()` (WooCommerce saves prices before custom meta).
12. **Crash-shaped states are handled, not guessed** (all found by `s05`, kill -9 every 3s):
    - lock gone + sale == `new_sale` → still ours, full restore;
    - lock gone/ours + sale == `orig_sale` → restore was half done, finish it;
    - after every save `ensure_active_price()` repairs a stale `_price` (WooCommerce only
      recomputes it when a price prop *changes*; variations drop the lock before recomputing);
    - verify phases call `repair_lookup()` for `wc_product_meta_lookup` (shop filters/sorting).
    Any new write path must keep these four properties; re-run `s05` three times after changing them.

## Governor (ResourceGovernor) — protects the batch AND the shoppers

Version 1.0 only protected the batch request; on real shared hosts it either never started
(load/cores with a hidden CPU count = "always busy"; 75%-of-memory_limit ceiling already used by
heavy sites) or ran 20 s of 100% CPU back to back → visitors got 503. Now:

| profile | batch wall time | share of max_execution_time | duty (busy share) | memory share of free headroom | max batch | base cooldown |
|---|---|---|---|---|---|---|
| gentle | 5 s | 25% | 30% | 40% | 40 | 4 s |
| auto | 10 s | 40% | 50% | 60% | 120 | 2 s |
| fast | 25 s | 55% | 85% | 75% | 400 | 0 s |

- **Pacing:** `pace()` after each product rests `item_time × (1-duty)/duty × pace`.
- **Congestion signals** (any → batch ×½, `pace` ×2–3, longer cooldown): a product slower than
  `slow_item` (stop at once), batch ms/product > 3× the learned `base_ms`, memory stop, load
  above *this host's normal* (`load_base`). Healthy batches: +25% size, `pace` ×0.7 back to 1.
- **Load average is only a hint:** unknown CPU count (`cpu_count()` = 0) ⇒ ignored; never blocks.
- **Memory** = headroom left after WordPress booted (`limit - usage`), never a fixed share.
- **Site-wide rest** `dsd_not_before` (set to +30 s before each batch, real cooldown after) is
  respected by every runner except WP-CLI (which sleeps its own cooldown).
- **One loopback chain**: `Queue::kick()` tokens; a request sleeping out the cooldown re-arms itself.
- **Crashes:** `dsd_inflight` marks the product being written → counted as an attempt after a
  fatal; `Engine::on_shutdown()` records the error (`dsd_last_skip.reason = crashed`) and re-arms.
- **Page caches:** `CacheCompat::suspend()` (in `run_locked`, restored in `pump()`'s finally) detaches
  callbacks of known cache plugins from post/product hooks during OUR batch; `CacheCompat::on_transition`
  purges once at verifying→active, →reverting and verifying_revert→ended/scheduled. Any new write path
  must run inside a batch (or call suspend/resume itself). Test: `run.py s18`.
- `TargetResolver::next_chunk()` reads 500 product IDs by PK, then filters just those (cost does
  not grow with orders/postmeta).

## Procedure for a change

1. Read the relevant class + this file's invariants.
2. If you add a state or item status: update `Campaign::WORKING/LIVE/EDITABLE`, `Repository::empty_stats()`,
   `PriceGuard::rebuild()`, the UI labels (`admin-app/src/lib/labels.ts`, `progress.ts`) and the probe.
3. Pure logic (prices, schedules, governor) → add PHPUnit cases in `tests/unit/`.
4. Anything touching products → add/extend a scenario in `tests/integration/run.py` (see `dsd-testing`).
5. Run `bash docker/run-tests.sh`.

## Failure modes

- Campaign stuck in a working state → System page (or `/system`): `last_skip` (reason + note, e.g. a
  fatal error from another plugin), `cooling`, `pace`, `headroom_mb`, loopback check; Action Scheduler
  "Pending" for group `smart-bulk-discount`.
- Many `skipped/other_campaign` → overlapping live campaigns (by design).
- `failed` items after verify → another plugin rewrote the price on save; see item `note`.

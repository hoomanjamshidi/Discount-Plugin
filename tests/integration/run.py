#!/usr/bin/env python3
"""
Integration + load suite against the Docker stack (real WordPress + WooCommerce,
3000-product catalog). Every scenario starts from the same DB snapshot.

    python tests/integration/run.py              # all scenarios
    python tests/integration/run.py crash web    # selected scenarios (substring match)

Workers run with shared-host limits (128M memory, 30s max_execution_time) unless a
scenario says otherwise. Results are written to tests/integration/.out/report.json.
"""
import base64
import json
import os
import re
import statistics
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
COMPOSE = os.path.join(ROOT, "docker", "docker-compose.yml")
OUT_HOST = os.path.join(ROOT, "tests", "integration", ".out")
OUT_CLI = "/tests/integration/.out"
SNAP_SQL = "/var/www/html/wp-content/dsd-snapshots/seed.sql"
SITE = "http://localhost:8088"
DC = ["docker", "compose", "-f", COMPOSE]

os.makedirs(OUT_HOST, exist_ok=True)
# The WP-CLI container writes snapshots here as the web user (uid 33): the folder must be writable
# for it, or every snapshot is lost (probe.php now fails loudly instead of comparing against nothing).
os.chmod(OUT_HOST, 0o777)
RESULTS = []


# ----------------------------------------------------------------------------- helpers

class Fail(Exception):
    pass


def check(cond, msg):
    if not cond:
        raise Fail(msg)


def sh(args, timeout=900, ok_codes=(0,)):
    p = subprocess.run(args, capture_output=True, text=True, encoding="utf-8", errors="replace", timeout=timeout)
    if p.returncode not in ok_codes:
        raise Fail(f"command failed ({p.returncode}): {' '.join(args[-6:])}\n{p.stdout[-2000:]}\n{p.stderr[-2000:]}")
    return p


def wp(*args, mem="512M", exec_time="0", timeout=900, ok_codes=(0,)):
    cmd = DC + ["exec", "-T", "cli", "php", "-d", f"memory_limit={mem}", "-d", f"max_execution_time={exec_time}",
                "/usr/local/bin/wp", "--path=/var/www/html", *args]
    return sh(cmd, timeout=timeout, ok_codes=ok_codes)


def probe(*args):
    out = wp("eval-file", "/tests/integration/probe.php", *[str(a) for a in args]).stdout.strip().splitlines()
    return json.loads(out[-1])


def restore(loopback=False):
    """Fresh copy of the 3000-product catalog. CLI scenarios turn the web loopback off so
    the only runner is the one the test drives (web scenarios turn it on)."""
    wp("db", "import", SNAP_SQL, "--quiet")
    wp("cache", "flush", "--quiet")
    wp("option", "update", "dsd_settings",
       json.dumps({"profile": "auto", "pause_on_high_load": True, "admin_runner": True, "loopback": loopback,
                   "round_step": 0, "delete_data_on_uninstall": False}), "--format=json", "--quiet")
    wp("option", "delete", "dsd_governor", "--quiet", ok_codes=(0, 1))


def snapshot(name):
    path = f"{OUT_CLI}/{name}.json"
    probe("snapshot", path)
    return path


BATCH_RE = re.compile(r"batch (\d+): (\d+) items in ([\d.]+)s, peak ([\d.]+)MB, stop=(\w+), next=(\d+)")


def run_worker(mem="128M", exec_time="30", budget=None, max_batches=None, kill_after=None):
    """Run `wp dsd run` with host-like limits. Returns parsed batch summaries."""
    args = ["dsd", "run"]
    if budget:
        args.append(f"--budget={budget}")
    if max_batches:
        args.append(f"--max-batches={max_batches}")
    if kill_after:
        inner = (f"timeout -s KILL {kill_after} php -d memory_limit={mem} -d max_execution_time={exec_time} "
                 f"/usr/local/bin/wp --path=/var/www/html {' '.join(args)}")
        p = sh(DC + ["exec", "-T", "cli", "sh", "-c", inner], ok_codes=(0, 1, 124, 137, 143, 255))
    else:
        p = wp(*args, mem=mem, exec_time=exec_time, timeout=1800)
    if "Fatal error" in p.stdout + p.stderr or "Allowed memory size" in p.stdout + p.stderr:
        raise Fail("PHP fatal in worker:\n" + (p.stdout + p.stderr)[-1500:])
    batches = []
    for m in BATCH_RE.finditer(p.stdout):
        batches.append({"items": int(m.group(2)), "secs": float(m.group(3)), "mem_mb": float(m.group(4)),
                        "why": m.group(5), "next": int(m.group(6))})
    return batches


def wait_status(cid, want, worker=True, timeout=600, **kw):
    t0 = time.time()
    while time.time() - t0 < timeout:
        st = probe("status", cid)
        if st["status"] in want:
            return st
        if worker:
            run_worker(**kw)
        else:
            time.sleep(2)
    raise Fail(f"campaign {cid} did not reach {want}; last={st}")


def assert_applied(cid, snap):
    rep = probe("check_applied", cid, snap)
    short = {k: rep[k] for k in ("eligible", "applied_ok", "kept_better_sale", "wrong", "untouched_ok",
                                 "untouched_changed", "lookup_mismatch", "counts", "status")}
    check(rep["wrong"] == 0, f"{rep['wrong']} products have a wrong price: {rep['errors'][:3]}")
    check(rep["untouched_changed"] == 0, f"{rep['untouched_changed']} ineligible products changed: {rep['errors'][:3]}")
    check(rep["lookup_mismatch"] == 0, f"{rep['lookup_mismatch']} rows in wc_product_meta_lookup disagree")
    check(rep["counts"]["pending"] == 0 and rep["counts"]["failed"] == 0, f"pending/failed left: {rep['counts']}")
    check(rep["eligible"] == rep["applied_ok"] + rep["kept_better_sale"], "eligible != applied + kept")
    return short


def assert_restored(snap):
    rep = probe("check_restored", snap)
    if rep["differences"]:
        ids = sorted({d["id"] for d in rep["samples"]})[:3]
        rep["debug"] = {i: probe("item_rows", i) for i in ids}
        with open(os.path.join(OUT_HOST, "restore-debug.json"), "a", encoding="utf-8") as f:
            f.write(json.dumps(rep, ensure_ascii=False) + "\n")
    check(rep["differences"] == 0, f"{rep['differences']} price fields differ from the original: {rep['samples'][:5]}")
    check(rep.get("lookup_mismatch", 0) == 0, f"{rep.get('lookup_mismatch')} wc_product_meta_lookup rows disagree with _price after restore")
    return rep


def batch_stats(batches, exec_limit=30):
    if not batches:
        return {}
    secs = [b["secs"] for b in batches]
    mem = [b["mem_mb"] for b in batches]
    check(max(secs) < exec_limit * 0.75, f"a batch ran {max(secs)}s, too close to max_execution_time={exec_limit}")
    return {"batches": len(batches), "items": sum(b["items"] for b in batches), "max_batch_secs": max(secs),
            "avg_batch_secs": round(statistics.mean(secs), 2), "peak_mem_mb": max(mem),
            "final_batch_size": batches[-1]["next"]}


def scenario(fn):
    fn.scenario = True
    return fn


# ----------------------------------------------------------------------------- scenarios

@scenario
def s01_all_in_stock_percent():
    """All in-stock products, 20% off, rounded down to 1,000; then stop and restore everything."""
    restore()
    snap = snapshot("s01")
    cid = probe("create", json.dumps({"name": "همه موجودها ۲۰٪", "discount_type": "percent", "discount_value": 20,
                                      "target_type": "all_in_stock", "options": {"round_step": 1000, "sale_policy": "best"},
                                      "start": True}))["id"]
    t0 = time.time()
    batches = []
    while probe("status", cid)["status"] != "active":
        batches += run_worker()
    apply_secs = round(time.time() - t0, 1)
    applied = assert_applied(cid, snap)
    stats = batch_stats(batches)

    check(probe("cmd", "stop", cid).get("status") == "reverting", "stop did not start reverting")
    t1 = time.time()
    rb = []
    while probe("status", cid)["status"] != "ended":
        rb += run_worker()
    revert_secs = round(time.time() - t1, 1)
    assert_restored(snap)
    return {"apply": applied, "apply_wall_secs": apply_secs, "apply_batches": stats,
            "revert_wall_secs": revert_secs, "revert_batches": batch_stats(rb)}


@scenario
def s02_categories_fixed_with_exclusions():
    """3 parent categories (+children), fixed 15,000 off, override existing sales, exclude 5 products."""
    restore()
    snap = snapshot("s02")
    cats = [c["id"] for c in probe("categories")][:3]
    exclude = probe("pick", 5, "simple")
    cid = probe("create", json.dumps({"name": "دسته‌ها", "discount_type": "fixed", "discount_value": 15000,
                                      "target_type": "categories",
                                      "target": {"category_ids": cats, "exclude_product_ids": exclude},
                                      "options": {"sale_policy": "override", "include_children": True, "only_in_stock": True},
                                      "start": True}))["id"]
    wait_status(cid, ["active"])
    applied = assert_applied(cid, snap)
    check(applied["eligible"] > 600, f"expected several hundred products in 3 categories, got {applied['eligible']}")
    probe("cmd", "stop", cid)
    wait_status(cid, ["ended"])
    assert_restored(snap)
    return {"apply": applied}


@scenario
def s03_selected_products_with_variations():
    """Hand-picked 30 simple + 10 variable products (variations expanded), 35% off, keep existing sales."""
    restore()
    snap = snapshot("s03")
    ids = probe("pick", 30, "simple") + probe("pick", 10, "variable")
    cid = probe("create", json.dumps({"discount_type": "percent", "discount_value": 35, "target_type": "products",
                                      "target": {"product_ids": ids}, "options": {"sale_policy": "keep"},
                                      "start": True}))["id"]
    wait_status(cid, ["active"])
    applied = assert_applied(cid, snap)
    check(applied["counts"]["total"] >= 30 + 10 * 2, "variable products were not expanded to variations")
    probe("cmd", "stop", cid)
    wait_status(cid, ["ended"])
    assert_restored(snap)
    return {"apply": applied}


@scenario
def s04_overlapping_campaigns_never_fight():
    """Campaign A on one category, then B on everything: B must skip A's products; both revert cleanly."""
    restore()
    snap0 = snapshot("s04_0")
    cat = probe("categories")[0]["id"]
    a = probe("create", json.dumps({"discount_type": "percent", "discount_value": 10, "target_type": "categories",
                                    "target": {"category_ids": [cat]}, "start": True}))["id"]
    wait_status(a, ["active"])
    check(probe("status", a)["counts"]["applied"] > 50, "campaign A must cover real products for this test to mean anything")
    assert_applied(a, snap0)
    snap1 = snapshot("s04_1")
    b = probe("create", json.dumps({"discount_type": "percent", "discount_value": 30, "target_type": "all_in_stock",
                                    "start": True}))["id"]
    wait_status(b, ["active"])
    st_a = probe("status", a)["counts"]
    st_b = probe("status", b)["counts"]
    # A's prices untouched by B.
    rep_a = probe("check_applied", a, snap0)
    check(rep_a["wrong"] == 0, f"campaign B overwrote A's products: {rep_a['errors'][:2]}")
    check(st_b["skipped"] >= st_a["applied"], "B did not skip A's locked products")
    probe("cmd", "stop", b)
    wait_status(b, ["ended"])
    assert_restored(snap1)  # back to "only A applied"
    probe("cmd", "stop", a)
    wait_status(a, ["ended"])
    assert_restored(snap0)
    return {"a": st_a, "b": st_b}


@scenario
def s05_crash_recovery_kill_mid_batch():
    """Kill -9 the worker every 3s while applying and while reverting; result must still be exact."""
    restore()
    snap = snapshot("s05")
    cid = probe("create", json.dumps({"discount_type": "percent", "discount_value": 25, "target_type": "all_in_stock",
                                      "start": True}))["id"]
    kills = 0
    for _ in range(6):
        run_worker(kill_after=3)
        kills += 1
    mid = probe("status", cid)
    wait_status(cid, ["active"])
    applied = assert_applied(cid, snap)
    probe("cmd", "stop", cid)
    for _ in range(4):
        run_worker(kill_after=3)
        kills += 1
    wait_status(cid, ["ended"])
    assert_restored(snap)
    return {"kills": kills, "state_after_kills": mid["counts"], "apply": applied}


@scenario
def s06_scheduled_window_and_price_guard():
    """Starts in +20s, ends in +75s. Before start nothing changes; after the end time the storefront
    shows the regular price even before the revert batch runs (PriceGuard)."""
    restore()
    snap = snapshot("s06")
    ids = probe("pick", 40, "simple")
    now = int(time.time())
    cid = probe("create", json.dumps({"discount_type": "percent", "discount_value": 50, "target_type": "products",
                                      "target": {"product_ids": ids}, "starts_at": now + 20, "ends_at": now + 75,
                                      "start": True}))["id"]
    check(probe("status", cid)["status"] == "scheduled", "not scheduled")
    run_worker()
    check(probe("status", cid)["counts"]["total"] == 0, "worked before start time")
    while time.time() < now + 21:
        time.sleep(1)
    wp("dsd", "watchdog")
    wait_status(cid, ["active"])
    assert_applied(cid, snap)
    view = probe("view_price", ids[0])
    check(view["on_sale"] and float(view["price"]) < float(view["regular"]), f"discount not visible: {view}")
    while time.time() < now + 77:
        time.sleep(1)
    # End passed; no worker has run yet → DB still holds the sale price, storefront must not.
    view_after = probe("view_price", ids[0])
    check(view_after["db_sale"] != "", "DB already reverted (test needs the gap)")
    wp("dsd", "watchdog")
    view_guard = probe("view_price", ids[0])
    check(not view_guard["on_sale"] and float(view_guard["price"]) == float(view_guard["regular"]),
          f"PriceGuard did not hide an expired discount: {view_guard}")
    wait_status(cid, ["ended"])
    assert_restored(snap)
    return {"guard_view": view_guard}


@scenario
def s07_manual_edit_is_respected():
    """An admin edits one product during the campaign; the revert keeps the admin's price."""
    restore()
    snap = snapshot("s07")
    ids = probe("pick", 20, "simple")
    cid = probe("create", json.dumps({"discount_type": "percent", "discount_value": 10, "target_type": "products",
                                      "target": {"product_ids": ids}, "start": True}))["id"]
    wait_status(cid, ["active"])
    probe("set_sale", ids[0], 12345)
    probe("cmd", "stop", cid)
    wait_status(cid, ["ended"])
    st = probe("status", cid)
    check(st["counts"]["kept"] == 1, f"expected 1 kept item, got {st['counts']}")
    view = probe("view_price", ids[0])
    check(view["db_sale"] == "12345", f"admin price lost: {view}")
    rep = probe("check_restored", snap)
    check(rep["differences"] <= 2, f"only the edited product may differ: {rep}")
    return {"counts": st["counts"]}


@scenario
def s08_daily_repeat_rolls_forward():
    """A daily window: after it ends, prices are restored and the campaign is rescheduled +1 day."""
    restore()
    snap = snapshot("s08")
    ids = probe("pick", 15, "simple")
    now = int(time.time())
    cid = probe("create", json.dumps({"discount_type": "percent", "discount_value": 15, "target_type": "products",
                                      "target": {"product_ids": ids}, "starts_at": now + 5, "ends_at": now + 40,
                                      "repeat_mode": "daily", "start": True}))["id"]
    while time.time() < now + 6:
        time.sleep(1)
    wp("dsd", "watchdog")
    wait_status(cid, ["active"])
    while time.time() < now + 41:
        time.sleep(1)
    wp("dsd", "watchdog")
    st = wait_status(cid, ["scheduled"])
    check(st["starts_at"] == now + 5 + 86400 and st["ends_at"] == now + 40 + 86400, f"wrong next window: {st}")
    check(st["run_count"] == 1 and st["counts"]["total"] == 0, f"repeat bookkeeping wrong: {st}")
    assert_restored(snap)
    return {"next_window": [st["starts_at"], st["ends_at"]]}


@scenario
def s09_pause_and_resume():
    restore()
    snap = snapshot("s09")
    cid = probe("create", json.dumps({"discount_type": "percent", "discount_value": 12, "target_type": "all_in_stock",
                                      "start": True}))["id"]
    run_worker(max_batches=2)
    probe("cmd", "pause", cid)
    before = probe("status", cid)["counts"]
    run_worker()
    after = probe("status", cid)
    check(after["status"] == "paused" and after["counts"] == before, "worker kept going while paused")
    probe("cmd", "resume", cid)
    wait_status(cid, ["active"])
    applied = assert_applied(cid, snap)
    probe("cmd", "stop", cid)
    wait_status(cid, ["ended"])
    assert_restored(snap)
    return {"paused_at": before, "apply": applied}


@scenario
def s10_tiny_host_gentle_profile():
    """20s max_execution_time + 'gentle' profile: must finish, no batch over 8s.
    (WP-CLI lifts memory_limit, so memory caps are verified in the web scenario s11.)"""
    restore()
    wp("option", "patch", "update", "dsd_settings", "profile", "gentle")
    snap = snapshot("s10")
    cid = probe("create", json.dumps({"discount_type": "fixed", "discount_value": 5000, "target_type": "all_in_stock",
                                      "start": True}))["id"]
    batches = []
    while probe("status", cid)["status"] != "active":
        batches += run_worker(mem="96M", exec_time="20")
    stats = batch_stats(batches, exec_limit=20)
    check(stats["max_batch_secs"] <= 8.5, f"gentle batch ran {stats['max_batch_secs']}s")
    check(max(b["items"] for b in batches) <= 40, "gentle profile exceeded its 40-item batch cap")
    assert_applied(cid, snap)
    probe("cmd", "stop", cid)
    while probe("status", cid)["status"] != "ended":
        run_worker(mem="96M", exec_time="20")
    assert_restored(snap)
    return {"batches": stats}


@scenario
def s13_inherits_backup_from_unrestored_campaign():
    """Campaign A ends without restoring (simulated). The storefront must hide A's price at once,
    and a new campaign B on the same products must restore the TRUE original, not A's price."""
    restore()
    snap = snapshot("s13")
    ids = probe("pick", 25, "simple")
    a = probe("create", json.dumps({"discount_type": "percent", "discount_value": 30, "target_type": "products",
                                    "target": {"product_ids": ids}, "start": True}))["id"]
    wait_status(a, ["active"])
    probe("force_end", a)
    v = probe("view_price", ids[0])
    check(not v["on_sale"] and float(v["price"]) == float(v["regular"]), f"guard did not hide an orphaned price: {v}")
    b = probe("create", json.dumps({"discount_type": "percent", "discount_value": 10, "target_type": "products",
                                    "target": {"product_ids": ids}, "start": True}))["id"]
    wait_status(b, ["active"])
    check(probe("status", b)["counts"]["applied"] == len(ids), "B did not take over the orphaned products")
    probe("cmd", "stop", b)
    wait_status(b, ["ended"])
    assert_restored(snap)
    return {"products": len(ids)}


# ---- web scenarios: no CLI worker at all; only what a real host would run ------------

def app_password():
    p = wp("user", "application-password", "create", "admin", f"dsd-test-{int(time.time())}", "--porcelain")
    token = base64.b64encode(f"admin:{p.stdout.strip()}".encode()).decode()
    return {"Authorization": f"Basic {token}"}


def rest(method, route, auth, body=None, timeout=60):
    url = f"{SITE}/?rest_route=/dsd/v1/{route.lstrip('/')}"
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(url, data=data, method=method, headers={**auth, "Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return json.loads(r.read().decode())


class Traffic:
    """Simulated shoppers: N threads hitting storefront pages for the whole test."""

    def __init__(self, urls, threads=6):
        self.urls, self.threads, self.stop = urls, threads, False
        self.lat, self.codes, self.lock = [], {}, threading.Lock()

    def _loop(self, k):
        i = k
        while not self.stop:
            url = self.urls[i % len(self.urls)]
            i += self.threads
            t0 = time.time()
            try:
                with urllib.request.urlopen(url, timeout=30) as r:
                    r.read()
                    code = r.status
            except urllib.error.HTTPError as e:
                code = e.code
            except Exception:
                code = "timeout/error"
            with self.lock:
                self.lat.append(time.time() - t0)
                self.codes[code] = self.codes.get(code, 0) + 1

    def __enter__(self):
        self.ts = [threading.Thread(target=self._loop, args=(k,), daemon=True) for k in range(self.threads)]
        [t.start() for t in self.ts]
        return self

    def __exit__(self, *a):
        self.stop = True
        [t.join(timeout=40) for t in self.ts]

    def report(self):
        lat = sorted(self.lat)
        p = lambda q: round(lat[min(len(lat) - 1, int(len(lat) * q))], 2) if lat else None
        return {"requests": len(lat), "codes": self.codes, "p50": p(0.5), "p95": p(0.95), "max": p(1.0)}


def debug_log_fatals():
    p = sh(DC + ["exec", "-T", "wordpress", "sh", "-c",
                 "grep -c 'PHP Fatal' /var/www/html/wp-content/debug.log 2>/dev/null || true"])
    return int((p.stdout.strip() or "0").splitlines()[-1])


def storefront_urls():
    ids = probe("pick", 20, "simple") + probe("pick", 5, "variable")
    return [f"{SITE}/?post_type=product&p={i}" for i in ids] + [f"{SITE}/?post_type=product", SITE + "/"]


@scenario
def s11_web_background_under_traffic():
    """The real path: REST start, then only WP-Cron/Action Scheduler/loopback inside Apache
    (8 workers, 128M, 30s) while 6 shoppers browse. No 5xx and no timeouts allowed."""
    restore(loopback=True)
    snap = snapshot("s11")
    fatals0 = debug_log_fatals()
    auth = app_password()
    urls = storefront_urls()
    with Traffic(urls) as traffic:
        c = rest("POST", "campaigns", auth, {"name": "web", "discount_type": "percent", "discount_value": 20,
                                             "target_type": "all_in_stock", "start": True})
        cid = c["id"]
        t0 = time.time()
        status = c["status"]
        while status != "active" and time.time() - t0 < 900:
            time.sleep(3)
            status = rest("GET", f"campaigns/{cid}", auth)["status"]
        apply_secs = round(time.time() - t0, 1)
        check(status == "active", f"web runner did not finish in 15 min (status={status})")
        rest("POST", f"campaigns/{cid}/stop", auth)
        t1 = time.time()
        while status != "ended" and time.time() - t1 < 900:
            time.sleep(3)
            status = rest("GET", f"campaigns/{cid}", auth)["status"]
        revert_secs = round(time.time() - t1, 1)
        check(status == "ended", "web runner did not finish reverting")
    tr = traffic.report()
    bad = {k: v for k, v in tr["codes"].items() if k != 200}
    check(not bad, f"shoppers saw errors: {tr}")
    check(debug_log_fatals() == fatals0, "new PHP fatal errors in debug.log")
    assert_restored(snap)
    sysinfo = rest("GET", "system", auth)
    web_batches = sysinfo["history"]
    check(web_batches, "no web batches recorded")
    peak = max(b["mem_mb"] for b in web_batches)
    longest = max(b["secs"] for b in web_batches)
    check(peak < 128 * 0.75, f"web batch memory {peak}MB too close to the 128M limit")
    check(longest < 15.5, f"web batch ran {longest}s (budget is 15s of the 30s limit)")
    return {"web_batch_peak_mem_mb": peak, "web_batch_longest_secs": longest, "apply_wall_secs": apply_secs, "revert_wall_secs": revert_secs, "traffic": tr,
            "learned_batch_size": sysinfo["batch_size"], "ms_per_item": sysinfo["ms_per_item"]}


@scenario
def s12_admin_page_runner_only():
    """Loopback disabled and no shoppers (cron never fires): the open admin page's /tick alone finishes the job."""
    restore(loopback=False)
    snap = snapshot("s12")
    auth = app_password()
    ids = probe("pick", 200, "simple")
    c = rest("POST", "campaigns", auth, {"discount_type": "percent", "discount_value": 10, "target_type": "products",
                                         "target": {"product_ids": ids}, "start": True})
    cid, ticks, t0 = c["id"], 0, time.time()
    longest = 0.0
    while rest("GET", f"campaigns/{cid}", auth)["status"] != "active" and time.time() - t0 < 600:
        s = time.time()
        rest("POST", "tick", auth)
        longest = max(longest, time.time() - s)
        ticks += 1
        time.sleep(1)
    check(rest("GET", f"campaigns/{cid}", auth)["status"] == "active", "admin ticks alone did not finish")
    check(longest < 15, f"a /tick request took {longest:.1f}s")
    assert_applied(cid, snap)
    return {"ticks": ticks, "longest_tick_secs": round(longest, 2)}


def rest_status(method, route, headers):
    """HTTP status of a REST call (for permission checks)."""
    url = f"{SITE}/?rest_route=/dsd/v1/{route.lstrip('/')}"
    req = urllib.request.Request(url, method=method, headers=headers)
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            return r.status
    except urllib.error.HTTPError as e:
        return e.code


def fetch_report(cid, auth, per_page):
    rows, after, total = [], 0, None
    for _ in range(1000):
        page = rest("GET", f"campaigns/{cid}/report&after={after}&per_page={per_page}", auth)
        if "total" in page:
            total = page["total"]
        rows += page["rows"]
        if page["next"] is None:
            break
        after = page["next"]
    return rows, total


def load_snap(name):
    with open(os.path.join(OUT_HOST, f"{name}.json"), encoding="utf-8") as f:
        return {int(k): v for k, v in json.loads(f.read()).items()}


def same_price(a, b):
    if a in (None, "") or b in (None, ""):
        return (a in (None, "")) and (b in (None, ""))
    return abs(float(a) - float(b)) < 0.001


@scenario
def s20_report_export():
    """Campaign report (Excel/PDF export source): every product with ID, link, price before and
    campaign price; identical page by page; still exact after the campaign ended; admins only."""
    restore()
    snapshot("s20")
    snap = load_snap("s20")
    simple, own, variable = probe("pick", 20, "simple"), probe("pick_own_sale", 4), probe("pick", 4, "variable")
    check(len(own) == 4, "need products with their own sale price")
    cid = probe("create", json.dumps({"name": "گزارش", "discount_type": "percent", "discount_value": 20, "target_type": "products",
                                      "target": {"product_ids": simple + own + variable},
                                      "options": {"sale_policy": "override", "round_step": 1000}, "start": True}))["id"]
    wait_status(cid, ["active"])
    auth = app_password()
    counts = probe("status", cid)["counts"]

    rows, total = fetch_report(cid, auth, 7)
    big, total_big = fetch_report(cid, auth, 500)
    check(rows == big and total == total_big, "paged report differs from the one-page report")
    ids = [r["product_id"] for r in rows]
    check(len(ids) == len(set(ids)) == total == counts["total"], f"rows={len(ids)} unique={len(set(ids))} total={total} items={counts['total']}")
    links = probe("permalinks", ",".join(str(r["parent_id"] or r["product_id"]) for r in rows))
    for r in rows:
        old = snap[r["product_id"]]["meta"]
        check(r["name"] != "" and r["url"] == links[str(r["parent_id"] or r["product_id"])], f"name/link wrong: {r}")
        if r["has_backup"]:
            check(same_price(r["orig_regular"], old.get("_regular_price")) and same_price(r["orig_sale"], old.get("_sale_price")),
                  f"price before is not the real one: {r} vs {old}")
            check(same_price(r["sale_now"], r["new_sale"]), f"campaign price is not what the product carries: {r}")
        else:
            check(same_price(r["regular_now"], old.get("_regular_price")), f"untouched row has a changed price: {r}")
    applied = [r for r in rows if r["status"] == "applied"]
    check(len(applied) == counts["applied"] and any(r["orig_sale"] for r in applied), "expected applied rows incl. own-sale products")

    probe("cmd", "stop", cid)
    wait_status(cid, ["ended"])
    after_rows, _ = fetch_report(cid, auth, 500)
    for a, b in zip(rows, after_rows):
        check(a["product_id"] == b["product_id"] and same_price(a["orig_regular"], b["orig_regular"]) and same_price(a["new_sale"], b["new_sale"]),
              f"report changed after the end: {a} vs {b}")
        if b["has_backup"]:
            check(same_price(b["regular_now"], b["orig_regular"]) and same_price(b["sale_now"], b["orig_sale"]), f"price not back: {b}")

    anon = rest_status("GET", f"campaigns/{cid}/report", {})
    login = f"shopper{int(time.time())}"
    uid = wp("user", "create", login, f"{login}@example.test", "--role=customer", "--porcelain").stdout.strip()
    pw = wp("user", "application-password", "create", uid, "dsd-test", "--porcelain").stdout.strip()
    customer = rest_status("GET", f"campaigns/{cid}/report", {"Authorization": "Basic " + base64.b64encode(f"{login}:{pw}".encode()).decode()})
    missing = rest_status("GET", "campaigns/999999/report", auth)
    check(anon == 401 and customer == 403 and missing == 404, f"permissions: anon={anon} customer={customer} missing={missing}")
    return {"rows": len(rows), "applied": len(applied), "own_sale_rows": sum(1 for r in applied if r["orig_sale"]),
            "anon": anon, "customer": customer, "missing": missing}


@scenario
def s21_regular_price_survives_edits_and_sync_reads():
    """The "regular price became the discount" report: a campaign that ends BY TIME while an admin
    saves products (edit screen, Quick Edit) and an external app reads/writes prices through the
    REST API — during the campaign and in the gap after the end time, before the restore ran.
    Every price of every product must come back; the storefront must show what it showed before."""
    restore()
    snap = snapshot("s21")
    simple, variable = probe("pick", 24, "simple"), probe("pick", 3, "variable")
    shown = simple[:2] + variable[:2]
    html_before = probe("price_html", ",".join(map(str, shown)))
    now = int(time.time())
    cid = probe("create", json.dumps({"name": "s21", "discount_type": "percent", "discount_value": 15, "target_type": "products",
                                      "target": {"product_ids": simple + variable}, "options": {"round_step": 1000},
                                      "ends_at": now + 150, "start": True}))["id"]
    wait_status(cid, ["active"])
    for pid in simple[0:3]:
        probe("full_edit", pid)
    for pid in simple[3:6]:
        probe("quick_edit", pid)
    for pid in simple[6:9]:
        probe("rest_roundtrip", pid)
    check(probe("check_applied", cid, snap)["wrong"] == 0, "admin edits during the campaign disturbed our prices")
    while time.time() < now + 152:
        time.sleep(1)
    for pid in simple[9:12]:
        probe("quick_edit", pid)
    for pid in simple[12:15]:
        probe("rest_roundtrip", pid)
    wp("dsd", "watchdog")
    wait_status(cid, ["ended"])
    assert_restored(snap)
    html_after = probe("price_html", ",".join(map(str, shown)))
    check(html_after == html_before, f"storefront differs after the campaign: {html_before} vs {html_after}")
    return {"counts": probe("status", cid)["counts"], "storefront_same": True}


@scenario
def s22_external_price_writer_is_detected():
    """Control for s21: a buggy price-sync plugin copies the selling price into the regular price on
    every save. The suite and docs/diagnose-regular-price.sql MUST catch it, and the plugin's backup
    must still hold the true regular price (so it can be repaired)."""
    restore()
    snapshot("s22")
    snap = load_snap("s22")
    simple, variable = probe("pick", 10, "simple"), probe("pick", 2, "variable")
    set_sim(regular_from_price=True)
    try:
        cid = probe("create", json.dumps({"name": "s22", "discount_type": "percent", "discount_value": 15, "target_type": "products",
                                          "target": {"product_ids": simple + variable}, "options": {"round_step": 1000}, "start": True}))["id"]
        wait_status(cid, ["active"])
        probe("cmd", "stop", cid)
        wait_status(cid, ["ended"])
    finally:
        set_sim()
    snapshot("s22_after")
    after = load_snap("s22_after")
    changed = {i for i in snap if snap[i]["meta"].get("_regular_price") != after[i]["meta"].get("_regular_price")}
    check(changed, "the external writer changed nothing: the control is invalid")
    rep = probe("check_restored", f"{OUT_CLI}/s22.json")
    check(rep["differences"] > 0, "check_restored did not notice the overwritten regular prices")
    sh(["docker", "cp", os.path.join(ROOT, "docs", "diagnose-regular-price.sql"), "dsd-cli-1:/tmp/diagnose.sql"])
    diag = probe("diagnose", "/tmp/diagnose.sql")
    check(diag["error"] == "", f"diagnostic SQL failed: {diag['error']}")
    flagged = {int(r["product_id"]): r for r in diag["rows"] if r["problem"] == "REGULAR_EQUALS_DISCOUNT"}
    check(set(flagged) == changed, f"diagnostic flagged {sorted(flagged)} but the regular price changed on {sorted(changed)}")
    for pid, r in flagged.items():
        check(same_price(r["orig_regular"], snap[pid]["meta"]["_regular_price"]), f"backup lost the true regular price: {r}")
        check(same_price(r["regular_now"], r["new_sale"]), f"not the reported symptom: {r}")
    return {"regular_overwritten": len(changed), "flagged": len(flagged), "backup_intact": True,
            "fingerprint": sorted({f"{r['item_status']}/{r['item_note'] or '-'}" for r in flagged.values()})}


# ---- difficult hosts --------------------------------------------------------------

WP_CONTAINER = "dsd-wordpress-1"


def set_sim(**knobs):
    """Turn on host-simulator knobs (tests/integration/host-sim.php); no args = off."""
    if knobs:
        wp("option", "update", "dsd_host_sim", json.dumps(knobs), "--quiet")
    else:
        wp("option", "delete", "dsd_host_sim", "--quiet", ok_codes=(0, 1))


def cpu_limit(cpus):
    """Limit the web server container to N CPUs (0 = all CPUs of the Docker host), like a 1-vCPU VPS / LVE.
    (`--cpus=0` does not clear an existing limit, so "unlimited" means every CPU Docker has.)"""
    if not cpus:
        cpus = int(sh(["docker", "info", "--format", "{{.NCPU}}"]).stdout.strip() or 1)
    sh(["docker", "update", f"--cpus={cpus}", WP_CONTAINER])


def wait_rest_status(cid, auth, want, timeout):
    t0, status = time.time(), None
    while time.time() - t0 < timeout:
        try:
            status = rest("GET", f"campaigns/{cid}", auth)["status"]
        except Exception:  # noqa: BLE001 - a slow admin request is not what we measure here
            pass
        if status in want:
            return status, round(time.time() - t0, 1)
        time.sleep(5)
    raise Fail(f"campaign {cid} did not reach {want} in {timeout}s (status={status})")


@scenario
def s14_hostile_shared_host_under_traffic():
    """1 CPU, a heavy site that already uses most of its memory, slow product-save hooks,
    /proc hidden, shoppers browsing all the time, only web runners: the job must finish and
    shoppers must never get an error or a timeout."""
    restore(loopback=True)
    snap = snapshot("s14")
    ids = probe("pick", 400, "simple") + probe("pick", 20, "variable")
    auth = app_password()
    urls = storefront_urls()
    set_sim(boot_mb=45, save_ms=80, hide_proc=True)
    wp("transient", "delete", "dsd_cpu_count", "--quiet", ok_codes=(0, 1))
    fatals0 = debug_log_fatals()
    cpu_limit(1)
    try:
        with Traffic(urls, threads=4) as base:
            time.sleep(30)
        baseline = base.report()
        with Traffic(urls, threads=4) as traffic:
            c = rest("POST", "campaigns", auth, {"discount_type": "percent", "discount_value": 15, "target_type": "products",
                                                 "target": {"product_ids": ids}, "start": True})
            cid = c["id"]
            _, apply_secs = wait_rest_status(cid, auth, ["active"], 2400)
            applied = assert_applied(cid, snap)
            rest("POST", f"campaigns/{cid}/stop", auth)
            _, revert_secs = wait_rest_status(cid, auth, ["ended"], 2400)
        sysinfo = rest("GET", "system", auth)
    finally:
        cpu_limit(0)
        set_sim()
    tr = traffic.report()
    bad = {k: v for k, v in tr["codes"].items() if k != 200}
    check(not bad, f"shoppers saw errors: {tr} (baseline {baseline})")
    limit = max(baseline["p95"] * 2.5, baseline["p95"] + 2.0)
    check(tr["p95"] <= limit, f"shoppers slowed down too much: p95 {tr['p95']}s vs baseline {baseline['p95']}s (limit {limit:.2f}s)")
    check(debug_log_fatals() == fatals0, "new PHP fatal errors in debug.log")
    assert_restored(snap)
    hist = sysinfo["history"]
    check(max(b["secs"] for b in hist) < 30 * 0.75, "a web batch ran too close to max_execution_time")
    return {"baseline": baseline, "during": tr, "apply_secs": apply_secs, "revert_secs": revert_secs, "applied": applied,
            "cpu_count_seen": sysinfo["cpu_count"], "headroom_mb": sysinfo["headroom_mb"], "pace": sysinfo["pace"],
            "stress_batches": sum(1 for b in hist if b.get("stress")), "peak_mem_mb": max(b["mem_mb"] for b in hist)}


@scenario
def s15_loopback_blocked_by_firewall():
    """The host firewall blocks the site's request to itself: Action Scheduler on WP-Cron
    (fired by normal visits) must finish the job alone."""
    restore(loopback=True)
    snap = snapshot("s15")
    ids = probe("pick", 150, "simple")
    auth = app_password()
    urls = storefront_urls()
    set_sim(block_loopback=True)
    try:
        with Traffic(urls, threads=2):
            c = rest("POST", "campaigns", auth, {"discount_type": "fixed", "discount_value": 3000, "target_type": "products",
                                                 "target": {"product_ids": ids}, "start": True})
            cid = c["id"]
            _, apply_secs = wait_rest_status(cid, auth, ["active"], 1200)
            assert_applied(cid, snap)
            rest("POST", f"campaigns/{cid}/stop", auth)
            _, revert_secs = wait_rest_status(cid, auth, ["ended"], 1200)
    finally:
        set_sim()
    assert_restored(snap)
    return {"apply_secs": apply_secs, "revert_secs": revert_secs}


@scenario
def s16_fatal_error_in_another_plugin():
    """Another plugin's save hook kills PHP (fatal error) on one product. No visitors, no cron:
    the loopback chain must recover by itself, the job must finish, the error must be shown."""
    restore(loopback=True)
    snap = snapshot("s16")
    ids = probe("pick", 30, "simple")
    bad_id = ids[5]
    auth = app_password()
    set_sim(fatal_on=[bad_id], no_cron=True)
    try:
        c = rest("POST", "campaigns", auth, {"discount_type": "percent", "discount_value": 10, "target_type": "products",
                                             "target": {"product_ids": ids}, "start": True})
        cid = c["id"]
        _, apply_secs = wait_rest_status(cid, auth, ["active"], 900)
        rows = probe("item_rows", bad_id)
        sysinfo = rest("GET", "system", auth)
    finally:
        set_sim(no_cron=True)
    counts = probe("status", cid)["counts"]
    check(counts["pending"] == 0, f"items left pending: {counts}")
    check(counts["applied"] >= len(ids) - 1, f"the other products were not all applied: {counts}")
    skip = sysinfo.get("last_skip") or {}
    check(skip.get("reason") == "crashed" and "dsd_sim_undefined_function_to_crash" in (skip.get("note") or ""),
          f"the crash was not recorded for the System page: {skip}")
    rest("POST", f"campaigns/{cid}/stop", auth)
    _, revert_secs = wait_rest_status(cid, auth, ["ended"], 900)
    set_sim()
    assert_restored(snap)
    return {"apply_secs": apply_secs, "revert_secs": revert_secs, "crashed_item": rows, "last_skip": skip}


@scenario
def s17_dates_with_persian_date_plugin():
    """A Persian date plugin rewrites wp_date()/date_i18n() into Persian digits. Dates sent by
    the admin app must round-trip exactly, Persian digits and Jalali input must be understood,
    impossible dates must be refused."""
    import calendar
    restore()
    ids = probe("pick", 3, "simple")
    auth = app_password()
    set_sim(jalali_dates=True)
    try:
        body = {"discount_type": "percent", "discount_value": 10, "target_type": "products",
                "target": {"product_ids": ids}, "starts_at": "2099-01-10 08:30", "ends_at": "2099-01-12 23:59", "start": True}
        a = rest("POST", "campaigns", auth, body)
        check(a["status"] == "scheduled", f"not scheduled: {a['status']}")
        check(a["starts_local"] == "2099-01-10 08:30" and a["ends_local"] == "2099-01-12 23:59",
              f"dates changed on the way: {a['starts_local']} / {a['ends_local']}")
        # Asia/Tehran is +03:30 all year.
        check(a["starts_at"] == calendar.timegm((2099, 1, 10, 5, 0, 0)), f"wrong UTC time: {a['starts_at']}")
        b = rest("POST", "campaigns", auth, {**body, "starts_at": "۲۰۹۹-۰۱-۱۰ ۰۸:۳۰", "ends_at": "۲۰۹۹-۰۱-۱۲ ۲۳:۵۹"})
        check(b["starts_at"] == a["starts_at"] and b["ends_at"] == a["ends_at"], "Persian digits not understood")
        j = rest("POST", "campaigns", auth, {**body, "starts_at": "1477/10/20 08:30", "ends_at": "1477/10/22 23:59"})
        check(j["starts_local"] == "2099-01-10 08:30", f"Jalali input misread: {j['starts_local']}")
        code = None
        try:
            rest("POST", "campaigns", auth, {**body, "starts_at": "2099-02-30 10:00"})
        except urllib.error.HTTPError as e:
            code = (e.code, json.loads(e.read().decode()).get("code"))
        check(code == (400, "dsd_invalid_date"), f"impossible date accepted: {code}")
        listed = rest("GET", "campaigns", auth)["campaigns"]
        check(all(re.fullmatch(r"\d{4}-\d{2}-\d{2} \d{2}:\d{2}", x["starts_local"] or "2000-01-01 00:00") for x in listed),
              "a non-Latin date string reached the admin app")
        sysinfo = rest("GET", "system", auth)
        check(re.fullmatch(r"\d{4}-\d{2}-\d{2} \d{2}:\d{2}", sysinfo["site_now"]) is not None, f"site_now: {sysinfo['site_now']}")
        for x in (a, b, j):
            rest("POST", f"campaigns/{x['id']}/stop", auth)
    finally:
        set_sim()
    return {"starts_local": a["starts_local"], "jalali_input": j["starts_local"], "site_now": sysinfo["site_now"],
            "timezone": sysinfo["site_timezone"]}


@scenario
def s18_page_cache_plugins_are_not_flooded():
    """With a page-cache plugin (WP Rocket/LiteSpeed-like): a campaign must not purge per
    product (thousands of purges + re-crawls = 503 after the campaign); the cache is cleared
    exactly once when the discount is live, once when it ends, once when prices are back."""
    restore()
    ids = probe("pick", 60, "simple")
    set_sim(fake_cache=True)

    def counters():
        out = {}
        for k in ("dsd_sim_post_purges", "dsd_sim_full_purges", "dsd_sim_ls_purges"):
            p = wp("option", "get", k, ok_codes=(0, 1))
            out[k] = int(p.stdout.strip() or 0) if p.returncode == 0 else 0
        return out

    try:
        snap = snapshot("s18")
        cid = probe("create", json.dumps({"discount_type": "percent", "discount_value": 10, "target_type": "products",
                                          "target": {"product_ids": ids}, "start": True}))["id"]
        wait_status(cid, ["active"])
        applied = counters()
        probe("cmd", "stop", cid)
        wait_status(cid, ["ended"])
        done = counters()
        assert_restored(snap)
        # Control: with the compatibility switched off the same fake cache IS purged per product.
        wp("option", "patch", "update", "dsd_settings", "cache_compat", "0")
        cid2 = probe("create", json.dumps({"discount_type": "percent", "discount_value": 10, "target_type": "products",
                                           "target": {"product_ids": ids[:5]}, "start": True}))["id"]
        wait_status(cid2, ["active"])
        control = counters()
    finally:
        set_sim()
    check(done["dsd_sim_post_purges"] == 0, f"per-product purges during the campaign: {done}")
    check(applied["dsd_sim_full_purges"] == 1 and applied["dsd_sim_ls_purges"] == 1, f"expected one purge when applied: {applied}")
    check(done["dsd_sim_full_purges"] == 3 and done["dsd_sim_ls_purges"] == 3, f"expected 3 purges in total: {done}")
    check(control["dsd_sim_post_purges"] >= 5, f"control run did not purge per product (fake cache not wired?): {control}")
    return {"after_apply": applied, "after_end": done, "control_off": control}


@scenario
def s19_real_wp_rocket():
    """Real WP Rocket 3.24 (page cache on): no purge per product during a campaign, exactly
    one full purge when the discount is live / when it ends / when prices are back, and the
    cached product pages never show a stale price."""
    restore()
    rocket_zip = "/tests/integration/.out/third-party/wp-rocket.zip"
    check(os.path.exists(os.path.join(OUT_HOST, "third-party", "wp-rocket.zip")), "wp-rocket.zip missing (tests/integration/.out/third-party)")
    sh(DC + ["exec", "-T", "cli", "sh", "-c",
             f"test -d /var/www/html/wp-content/plugins/wp-rocket || (cd /var/www/html/wp-content/plugins && unzip -q {rocket_zip})"])
    ids = probe("pick", 40, "simple")
    watched = ids[:4]
    set_sim(count_rocket=True)

    def counters():
        out = {}
        for k in ("dsd_sim_rocket_post", "dsd_sim_rocket_domain"):
            p = wp("option", "get", k, ok_codes=(0, 1))
            out[k] = int(p.stdout.strip() or 0) if p.returncode == 0 else 0
        return out

    def url_of(pid):
        return wp("post", "list", "--post_type=product", f"--include={pid}", "--field=url").stdout.strip()

    def fetch(url):
        with urllib.request.urlopen(url, timeout=60) as r:
            return r.read().decode("utf-8", "replace")

    def cache_file(url):
        path = url.split("://", 1)[1].split("/", 1)[1].strip("/")
        p = sh(DC + ["exec", "-T", "cli", "sh", "-c",
                     f"ls /var/www/html/wp-content/cache/wp-rocket/localhost/{path}/ 2>/dev/null | head -3"], ok_codes=(0, 1, 2))
        return p.stdout.strip()

    def money(v):
        return f"{int(round(float(v))):,}"

    try:
        wp("plugin", "activate", "wp-rocket")
        urls = {pid: url_of(pid) for pid in watched}
        for u in urls.values():
            fetch(u)
            fetch(u)
        cached_before = {pid: cache_file(u) for pid, u in urls.items()}
        check(all(cached_before.values()), f"WP Rocket did not cache the product pages: {cached_before}")

        cid = probe("create", json.dumps({"discount_type": "percent", "discount_value": 20, "target_type": "products",
                                          "target": {"product_ids": ids}, "start": True}))["id"]
        wait_status(cid, ["active"])
        after_apply = counters()
        stale = {pid: cache_file(u) for pid, u in urls.items()}
        check(not any(stale.values()), f"old cached pages survived the 'applied' purge: {stale}")
        for pid, u in urls.items():
            v = probe("view_price", pid)
            html = fetch(u)
            check(money(v["db_sale"]) in html, f"product {pid}: cached page lacks the discounted price {v['db_sale']}")

        probe("cmd", "stop", cid)
        wait_status(cid, ["ended"])
        after_end = counters()
        for pid, u in urls.items():
            v = probe("view_price", pid)
            html = fetch(u)
            check(v["db_sale"] == "" and money(v["regular"]) in html, f"product {pid}: page after the end is wrong: {v}")
            check("<del" not in html.split("related")[0], f"product {pid}: page still shows a crossed-out price after the end")

        # Control: with the compatibility off, WP Rocket purges (and re-queues) per product.
        wp("option", "patch", "update", "dsd_settings", "cache_compat", "0")
        cid2 = probe("create", json.dumps({"discount_type": "percent", "discount_value": 10, "target_type": "products",
                                           "target": {"product_ids": ids[:6]}, "start": True}))["id"]
        wait_status(cid2, ["active"])
        control = counters()
    finally:
        wp("plugin", "deactivate", "wp-rocket", ok_codes=(0, 1))
        set_sim()

    check(after_end["dsd_sim_rocket_post"] == 0, f"WP Rocket purged per product during the campaign: {after_end}")
    check(after_apply["dsd_sim_rocket_domain"] == 1, f"expected 1 full purge once applied: {after_apply}")
    check(after_end["dsd_sim_rocket_domain"] == 3, f"expected 3 full purges in total: {after_end}")
    check(control["dsd_sim_rocket_post"] - after_end["dsd_sim_rocket_post"] >= 6,
          f"control (compat off) should purge per product: {control}")
    return {"after_apply": after_apply, "after_end": after_end, "control_compat_off": control}


# ----------------------------------------------------------------------------- main

def main():
    wanted = sys.argv[1:]
    fns = [f for n, f in sorted(globals().items()) if getattr(f, "scenario", False)]
    if wanted:
        fns = [f for f in fns if any(w in f.__name__ for w in wanted)]
    failed = 0
    for fn in fns:
        t0 = time.time()
        print(f"▶ {fn.__name__} — {(fn.__doc__ or '').strip().splitlines()[0] if fn.__doc__ else ''}", flush=True)
        try:
            data = fn()
            RESULTS.append({"name": fn.__name__, "ok": True, "secs": round(time.time() - t0, 1), "data": data})
            print(f"  ✔ passed in {time.time() - t0:.0f}s  {json.dumps(data, ensure_ascii=False)[:600]}", flush=True)
        except Exception as e:  # noqa: BLE001
            failed += 1
            RESULTS.append({"name": fn.__name__, "ok": False, "secs": round(time.time() - t0, 1), "error": str(e)[:3000]})
            print(f"  ✘ FAILED: {str(e)[:2500]}", flush=True)
    with open(os.path.join(OUT_HOST, "report.json"), "w", encoding="utf-8") as f:
        json.dump(RESULTS, f, ensure_ascii=False, indent=2)
    print(f"\n{len(fns) - failed}/{len(fns)} scenarios passed")
    sys.exit(1 if failed else 0)


if __name__ == "__main__":
    sys.stdout.reconfigure(encoding="utf-8")
    main()

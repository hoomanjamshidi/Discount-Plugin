import { useCallback, useEffect, useState } from "react";
import { AnimatePresence, motion } from "motion/react";
import { HugeiconsIcon } from "@hugeicons/react";
import { ArrowRight01Icon, PauseIcon, PlayIcon, StopIcon, Copy01Icon, Edit02Icon, RefreshIcon, LinkSquare02Icon, InformationCircleIcon } from "@hugeicons/core-free-icons";
import { usePoll } from "@/lib/poll";
import { api } from "@/lib/api";
import type { Campaign, Item, Worker } from "@/lib/types";
import { WORKING } from "@/lib/types";
import { ITEM_STATUS, LOG, REPEAT, STATUS, discountText, noteLabel, targetText } from "@/lib/labels";
import { ago, fa, jalali, money, num, parseLocal, serverNow } from "@/lib/format";
import { StatusMark } from "@/components/status-mark";
import { NumberTicker } from "@/components/number-ticker";
import DeleteButton from "@/components/delete-button";
import { SaveButton } from "@/components/save-button";
import { Badge, Btn, Card, ProgressBar, Segmented, Skeleton } from "./ui";
import { campaignProgress, markStatus } from "./progress";
import { useToast } from "./Toaster";
import { cn } from "@/lib/utils";

type Detail = Campaign & { worker: Worker };

export function CampaignDetail({ id, onBack, onEdit, onChanged }: { id: number; onBack: () => void; onEdit: (c: Campaign) => void; onChanged: () => void }) {
  const toast = useToast();
  const [c, setC] = useState<Detail | null>(null);
  const [error, setError] = useState("");

  const fetchDetail = useCallback(
    () =>
      api<Detail>(`campaigns/${id}`).then((d) => {
        setC(d);
        setError("");
      }),
    [id],
  );
  const load = useCallback(() => {
    fetchDetail().catch((e: Error) => setError(e.message));
  }, [fetchDetail]);

  useEffect(() => {
    load();
  }, [load]);

  // Poll faster while something is happening (never overlapping, backs off on errors).
  const busy = !!c && (WORKING.includes(c.status) || c.status === "scheduled");
  usePoll(fetchDetail, busy ? 4000 : 20000, !!c);

  const run = async (command: string, okTitle: string, okText?: string) => {
    try {
      const r = await api<Campaign>(`campaigns/${id}/${command}`, { method: "POST" });
      toast("success", okTitle, okText);
      onChanged();
      if (command === "duplicate") onEdit(r);
      else load();
    } catch (e) {
      toast("error", "انجام نشد", (e as Error).message);
      throw e;
    }
  };

  if (error && !c) return <Card className="text-center text-rose-600">{error}</Card>;
  if (!c) return <Skeleton className="h-72" />;

  const st = STATUS[c.status];
  const p = campaignProgress(c);
  const working = WORKING.includes(c.status);
  const canEdit = ["draft", "scheduled", "ended"].includes(c.status);
  const start = parseLocal(c.starts_local);
  const end = parseLocal(c.ends_local);

  return (
    <div className="flex flex-col gap-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <Btn variant="ghost" icon={ArrowRight01Icon} onClick={onBack}>
          همهٔ کمپین‌ها
        </Btn>
        <div className="flex flex-wrap items-center gap-2">
          {canEdit ? (
            <Btn icon={Edit02Icon} onClick={() => onEdit(c)}>
              ویرایش
            </Btn>
          ) : null}
          <Btn icon={Copy01Icon} onClick={() => run("duplicate", "یک کپی ساخته شد", "تنظیماتش را بررسی و شروع کنید.")}>
            کپی
          </Btn>
          {["draft", "ended"].includes(c.status) ? (
            <DeleteButton
              size="sm"
              label="حذف"
              cancelLabel="لغو حذف"
              countdown={4}
              workingLabel="در حال حذف…"
              onConfirm={() =>
                api(`campaigns/${id}`, { method: "DELETE" })
                  .then(() => (toast("success", "حذف شد"), onChanged(), onBack()))
                  .catch((e: Error) => {
                    toast("error", "حذف نشد", e.message);
                    throw e;
                  })
              }
            />
          ) : null}
        </div>
      </div>

      <Card className="p-6">
        <div className="flex flex-wrap items-start gap-4">
          <StatusMark status={markStatus(c)} progress={working ? p.ratio : undefined} size={44} strokeWidth={2.2} className="text-primary" />
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-2">
              <h1 className="m-0 text-xl font-black">{c.name}</h1>
              <Badge tone={st.tone}>{st.label}</Badge>
              {c.repeat_mode !== "none" ? <Badge tone="violet">{REPEAT[c.repeat_mode]}</Badge> : null}
            </div>
            <p className="m-0 mt-1 text-[14px] text-muted-foreground">
              <b className="text-foreground">{discountText(c)}</b> تخفیف روی {targetText(c, c.stats.total || undefined)}
            </p>
            <p className="m-0 mt-1 text-[13px] text-muted-foreground">{st.hint}</p>
          </div>
          <Actions c={c} run={run} />
        </div>

        {working || c.status === "paused" ? (
          <div className="mt-6">
            <ProgressBar value={p.ratio} tone={c.status.includes("revert") || c.paused_status.includes("revert") ? "amber" : "primary"} />
            <div className="mt-2 flex flex-wrap items-center justify-between gap-2 text-[13px] text-muted-foreground">
              <span>{p.text}</span>
              <WorkerLine worker={c.worker} c={c} />
            </div>
          </div>
        ) : null}

        <div className="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
          <Stat label="شروع" text={start ? jalali(start) : "—"} />
          <Stat label="پایان" text={end ? jalali(end) : "تا توقف دستی"} />
          <Stat label="با تخفیف" value={c.stats.applied} tone="primary" />
          <Stat label={c.status === "ended" || c.status.includes("revert") ? "برگشت داده شده" : "رد شده"} value={c.status === "ended" || c.status.includes("revert") ? c.stats.reverted + c.stats.kept : c.stats.skipped} />
        </div>

        {c.stats.failed > 0 ? (
          <div className="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-rose-50 px-4 py-3 text-[13px] text-rose-700">
            <span>{num(c.stats.failed)} محصول بعد از ۳ بار تلاش تغییر نکرد. فهرست را پایین ببینید.</span>
            {c.status === "active" ? <SaveButton variant="soft" label="تلاش دوباره" onClick={() => run("retry", "دوباره در صف قرار گرفتند")} /> : null}
          </div>
        ) : null}
      </Card>

      <div className="grid gap-5 lg:grid-cols-[1fr_320px]">
        <Items id={id} total={c.stats.total} refreshKey={c.updated_at} />
        <Timeline c={c} />
      </div>
    </div>
  );
}

function Actions({ c, run }: { c: Campaign; run: (cmd: string, ok: string, text?: string) => Promise<void> }) {
  return (
    <div className="flex flex-wrap items-center gap-2">
      {c.status === "draft" || c.status === "ended" ? <SaveButton label={c.starts_at && c.starts_at > serverNow() ? "زمان‌بندی کن" : "شروع تخفیف"} onClick={() => run("start", "شروع شد")} /> : null}
      {["preparing", "applying", "verifying"].includes(c.status) ? (
        <SaveButton variant="soft" label="توقف موقت" loadingLabel="…" doneLabel="متوقف شد" onClick={() => run("pause", "متوقف موقت شد", "قیمت‌هایی که تا الان تغییر کرده‌اند، می‌مانند.")} />
      ) : null}
      {c.status === "paused" ? <SaveButton label="ادامه" onClick={() => run("resume", "ادامه پیدا کرد")} /> : null}
      {c.status === "scheduled" ? <SaveButton variant="soft" label="لغو زمان‌بندی" onClick={() => run("stop", "زمان‌بندی لغو شد")} /> : null}
      {["preparing", "applying", "verifying", "active", "paused"].includes(c.status) ? (
        <DeleteButton label="پایان تخفیف" cancelLabel="منصرف شدم" countdown={5} color="#D97706" softColor="#FFF4E5" workingLabel="در حال پایان دادن…" onConfirm={() => run("stop", "پایان تخفیف آغاز شد", "قیمت‌ها کم‌کم به حالت قبل برمی‌گردند.")} />
      ) : null}
    </div>
  );
}

function Stat({ label, value, text, tone }: { label: string; value?: number; text?: string; tone?: "primary" }) {
  return (
    <div className="rounded-xl bg-muted/60 px-4 py-3">
      <p className="m-0 text-xs text-muted-foreground">{label}</p>
      <p className={cn("m-0 mt-1 text-[15px] font-bold", tone === "primary" && "text-primary")}>{value !== undefined ? <NumberTicker value={value} /> : text}</p>
    </div>
  );
}

function WorkerLine({ worker, c }: { worker: Worker; c: Campaign }) {
  const lb = c.stats.last_batch;
  if (c.status === "paused") return <span>متوقف است</span>;
  // Only "slow" if nothing happened for 3 minutes since this campaign last moved.
  const stale = worker.now - Math.max(worker.heartbeat || 0, c.updated_at || 0) > 180;
  return (
    <span className="flex items-center gap-1.5">
      <span className={cn("size-2 rounded-full", stale ? "bg-amber-500" : "animate-pulse bg-emerald-500")} />
      {worker.heartbeat ? `آخرین دسته ${ago(worker.heartbeat, worker.now)}` : "در انتظار اولین دسته"}
      {lb ? ` · ${fa(lb.items)} محصول در ${fa(lb.secs)} ثانیه` : ""}
      {stale ? " · کمی کند است؛ صفحه را باز نگه دارید تا کمک کند" : ""}
    </span>
  );
}

const FILTERS = [
  { value: "", label: "همه" },
  { value: "applied", label: "اعمال‌شده" },
  { value: "skipped", label: "رد‌شده" },
  { value: "failed", label: "ناموفق" },
  { value: "reverted", label: "برگشته" },
] as const;

function Items({ id, total, refreshKey }: { id: number; total: number; refreshKey: number }) {
  const [status, setStatus] = useState<string>("");
  const [page, setPage] = useState(1);
  const [data, setData] = useState<{ items: Item[]; total: number } | null>(null);

  useEffect(() => setPage(1), [status]);
  useEffect(() => {
    api<{ items: Item[]; total: number }>(`campaigns/${id}/items`, { query: { status, page, per_page: 25 } }).then(setData);
  }, [id, status, page, refreshKey]);

  const pages = data ? Math.max(1, Math.ceil(data.total / 25)) : 1;

  return (
    <Card className="p-0">
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border p-4">
        <p className="m-0 font-bold">محصولات کمپین</p>
        <Segmented value={status as (typeof FILTERS)[number]["value"]} onChange={(v) => setStatus(v)} options={FILTERS.map((f) => ({ ...f }))} />
      </div>
      {total === 0 && !data?.items.length ? (
        <p className="m-0 flex items-center justify-center gap-2 p-10 text-[13px] text-muted-foreground">
          <HugeiconsIcon icon={InformationCircleIcon} size={18} /> فهرست محصولات بعد از شروع کمپین ساخته می‌شود.
        </p>
      ) : null}
      <div className="overflow-x-auto">
        <table className="w-full border-collapse text-[13px]">
          <thead>
            {data?.items.length ? (
              <tr className="text-xs text-muted-foreground">
                <th className="px-4 py-2 text-start font-medium">محصول</th>
                <th className="px-2 py-2 text-start font-medium">قیمت اصلی</th>
                <th className="px-2 py-2 text-start font-medium">قیمت تخفیفی</th>
                <th className="px-4 py-2 text-start font-medium">وضعیت</th>
              </tr>
            ) : null}
          </thead>
          <tbody>
            <AnimatePresence initial={false}>
              {data?.items.map((it) => (
                <motion.tr key={it.id} initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="border-t border-border/60">
                  <td className="max-w-[260px] px-4 py-2.5">
                    <a href={it.edit_url} target="_blank" rel="noreferrer" className="flex items-center gap-1 truncate text-foreground no-underline hover:text-primary">
                      <span className="truncate">{it.name}</span>
                      <HugeiconsIcon icon={LinkSquare02Icon} size={13} className="shrink-0 opacity-40" />
                    </a>
                  </td>
                  <td className="px-2 tabular-nums text-muted-foreground">{it.orig_regular ? money(Number(it.orig_regular)) : "—"}</td>
                  <td className="px-2 font-medium tabular-nums">{it.new_sale ? money(Number(it.new_sale)) : "—"}</td>
                  <td className="px-4">
                    <span className="flex flex-col">
                      <span>{ITEM_STATUS[it.status] ?? it.status}</span>
                      {it.note ? <span className="text-[11px] text-muted-foreground">{noteLabel(it.note)}</span> : null}
                    </span>
                  </td>
                </motion.tr>
              ))}
            </AnimatePresence>
          </tbody>
        </table>
      </div>
      {data && data.total > 25 ? (
        <div className="flex items-center justify-between border-t border-border p-3 text-[13px]">
          <Btn onClick={() => setPage((p) => Math.max(1, p - 1))} disabled={page <= 1}>
            قبلی
          </Btn>
          <span className="text-muted-foreground">
            صفحهٔ {fa(page)} از {fa(pages)} ({num(data.total)} مورد)
          </span>
          <Btn onClick={() => setPage((p) => Math.min(pages, p + 1))} disabled={page >= pages}>
            بعدی
          </Btn>
        </div>
      ) : null}
    </Card>
  );
}

function Timeline({ c }: { c: Campaign }) {
  const items = [...c.log].reverse();
  return (
    <Card>
      <p className="m-0 mb-3 flex items-center gap-2 font-bold">
        <HugeiconsIcon icon={RefreshIcon} size={18} className="text-primary" /> رویدادها
      </p>
      <ol className="m-0 list-none space-y-3 p-0">
        {items.map((l, i) => (
          <li key={`${l.t}-${i}`} className="m-0 flex gap-3">
            <span className="mt-1.5 size-2 shrink-0 rounded-full bg-primary/50" />
            <span className="flex-1">
              <span className="block text-[13px]">
                {LOG[l.code] ?? l.code}
                {typeof l.ctx?.total === "number" ? ` (${num(l.ctx.total as number)} قلم)` : ""}
                {typeof l.ctx?.applied === "number" && l.code !== "prepared" ? ` — ${num(l.ctx.applied as number)} با تخفیف` : ""}
              </span>
              <span className="block text-[11px] text-muted-foreground">{ago(l.t)}</span>
            </span>
          </li>
        ))}
      </ol>
      {c.status === "paused" || c.status === "active" ? (
        <p className="m-0 mt-4 flex items-start gap-1.5 rounded-xl bg-muted/60 p-3 text-[12px] leading-5 text-muted-foreground">
          <HugeiconsIcon icon={c.status === "paused" ? PauseIcon : PlayIcon} size={14} className="mt-0.5 shrink-0" />
          {c.status === "paused" ? "در حالت توقف موقت، اگر زمان پایان برسد، قیمت‌ها باز هم خودکار برمی‌گردند." : "اگر قیمت محصولی را دستی عوض کنید، در پایان کمپین قیمت شما حفظ می‌شود."}
        </p>
      ) : null}
      {c.status === "reverting" ? (
        <p className="m-0 mt-4 flex items-start gap-1.5 rounded-xl bg-amber-50 p-3 text-[12px] leading-5 text-amber-800">
          <HugeiconsIcon icon={StopIcon} size={14} className="mt-0.5 shrink-0" />
          از همین لحظه قیمت تخفیفی به مشتری نشان داده نمی‌شود، حتی پیش از اینکه همهٔ قیمت‌ها در دیتابیس برگردند.
        </p>
      ) : null}
    </Card>
  );
}

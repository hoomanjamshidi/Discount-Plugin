import { useEffect, useState } from "react";
import { HugeiconsIcon } from "@hugeicons/react";
import { CpuIcon, DatabaseIcon, Clock01Icon, CheckmarkCircle02Icon, Alert02Icon } from "@hugeicons/core-free-icons";
import { usePoll } from "@/lib/poll";
import { api } from "@/lib/api";
import type { SystemInfo } from "@/lib/types";
import { ago, fa, num } from "@/lib/format";
import { SloshGauge } from "@/components/slosh-gauge";
import AnimatedSparkChart from "@/components/animated-spark-chart";
import { Card, SectionTitle, Skeleton } from "./ui";
import { cn } from "@/lib/utils";

const SKIP: Record<string, string> = {
  request_time_low: "زمان درخواست کافی نبود؛ دستهٔ بعدی در درخواست تازه انجام شد",
  memory_high: "حافظهٔ آزاد این درخواست کم بود؛ در درخواست بعدی ادامه داده شد",
  server_busy: "سرور شلوغ بود؛ کار آهسته‌تر ادامه پیدا کرد",
  error: "یک خطا رخ داد؛ کار پس از ۳۰ ثانیه دوباره ادامه پیدا کرد",
  crashed: "PHP وسط کار متوقف شد (خطای یک افزونهٔ دیگر یا محدودیت هاست)؛ کار پس از ۳۰ ثانیه خودش ادامه پیدا کرد",
};

const STRESS: Record<string, string> = {
  latency: "سرور کُند شده بود",
  slow: "یک محصول خیلی طول کشید",
  memory: "حافظه رو به اتمام بود",
  load: "بار پردازنده بالا بود",
};

export function SystemPanel() {
  const [s, setS] = useState<SystemInfo | null>(null);
  useEffect(() => {
    api<SystemInfo>("system").then(setS).catch(() => {});
  }, []);
  usePoll(() => api<SystemInfo>("system").then(setS), 8000);

  if (!s) return <Skeleton className="h-80" />;

  const loadPct = s.load_ratio === null ? null : Math.min(100, Math.round(s.load_ratio * 100));
  const hist = s.history.slice(-14);
  const series = hist.map((h) => (h.secs > 0 ? Math.round((h.items / h.secs) * 60) : 0));
  const perMin = s.ms_per_item > 0 ? Math.round(60000 / s.ms_per_item) : null;
  const memPeak = hist.length ? Math.max(...hist.map((h) => h.mem_mb)) : null;

  const checks = [
    { ok: s.action_scheduler, text: s.action_scheduler ? "صف پس‌زمینهٔ ووکامرس فعال است" : "صف پس‌زمینهٔ ووکامرس در دسترس نیست؛ از WP-Cron استفاده می‌شود" },
    { ok: !s.wp_cron_disabled, text: s.wp_cron_disabled ? "WP-Cron غیرفعال است؛ مطمئن شوید کران سرور تنظیم شده" : "زمان‌بند وردپرس فعال است" },
    { ok: s.max_execution_time === 0 || s.max_execution_time >= 20, text: `حداکثر زمان هر درخواست: ${s.max_execution_time ? `${fa(s.max_execution_time)} ثانیه` : "نامحدود"} (افزونه فقط ${fa(s.time_budget)} ثانیه از آن را استفاده می‌کند)` },
    { ok: s.memory_limit_mb === 0 || s.memory_limit_mb >= 128, text: `حافظهٔ PHP: ${s.memory_limit_mb ? `${fa(s.memory_limit_mb)} مگابایت` : "نامحدود"} (آزاد در این لحظه: ${fa(s.headroom_mb)} مگابایت)` },
    {
      ok: !s.loopback_enabled || !s.worker.has_work || !s.last_kick || s.worker.heartbeat >= s.last_kick - 60,
      text: !s.loopback_enabled
        ? "اجرای خودکار از طریق درخواست داخلی خاموش است؛ کار با صف پس‌زمینه و صفحهٔ باز افزونه جلو می‌رود"
        : s.worker.has_work && s.last_kick && s.worker.heartbeat < s.last_kick - 60
          ? "درخواست داخلی سایت به خودش ظاهراً مسدود است (فایروال/CDN)؛ کار با صف پس‌زمینه و صفحهٔ باز افزونه جلو می‌رود"
          : "درخواست داخلی سایت به خودش کار می‌کند",
    },
    {
      ok: true,
      text: s.caches?.length
        ? `افزونهٔ کش: ${s.caches.join("، ")} — ${s.cache_compat ? "کش فقط در پایان هر مرحلهٔ کمپین یک بار پاک می‌شود" : "سازگاری با کش خاموش است (کش برای هر محصول جدا پاک می‌شود)"}${s.last_purge ? ` · آخرین پاک‌سازی ${ago(s.last_purge.t, s.worker.now)}` : ""}`
        : "افزونهٔ کش صفحه پیدا نشد",
    },
    { ok: true, text: `منطقهٔ زمانی سایت: ${s.site_timezone} — ساعت سایت: ${fa(s.site_now ?? "")}` },
  ];

  return (
    <div className="flex flex-col gap-5">
      <div className="grid gap-5 lg:grid-cols-3">
        <Card className="flex flex-col items-center gap-3">
          <SectionTitle title="فشار روی سرور" hint="بار پردازنده نسبت به تعداد هسته‌ها" />
          {loadPct === null ? (
            <p className="text-[13px] text-muted-foreground">هاست شما این عدد را گزارش نمی‌دهد؛ افزونه فقط با زمان و حافظه خودش را تنظیم می‌کند.</p>
          ) : (
            <SloshGauge value={loadPct} interactive={false} unit="٪" width={120} height={170} liquidColor={loadPct > 120 ? "#E5484D" : loadPct > 70 ? "#D97706" : "#0F8A7E"} glassColor="#E9F1EF" ariaLabel="بار سرور" />
          )}
          <p className="m-0 text-xs text-muted-foreground">{s.cpu_count > 0 ? `${fa(s.cpu_count)} هستهٔ پردازنده` : "تعداد هسته‌ها را هاست نشان نمی‌دهد"}</p>
        </Card>

        <Card className="lg:col-span-2">
          <SectionTitle title="سرعت خودتنظیم" hint="اندازهٔ هر دسته بر اساس رفتار سرور خودکار کم و زیاد می‌شود." />
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <Mini icon={CpuIcon} label="اندازهٔ دسته" value={`${num(s.batch_size)} محصول`} />
            <Mini icon={Clock01Icon} label="هر محصول" value={s.ms_per_item ? `${num(Math.round(s.ms_per_item))} میلی‌ثانیه` : "—"} />
            <Mini icon={DatabaseIcon} label="بیشترین حافظه" value={memPeak ? `${num(memPeak)} مگ` : "—"} />
            <Mini icon={CheckmarkCircle02Icon} label="کل پردازش" value={num(s.total_items)} />
          </div>
          <p className="m-0 mt-3 text-[13px] leading-6 text-muted-foreground">
            افزونه حداکثر {fa(Math.round(s.duty * 100))}٪ از وقت یک پردازهٔ PHP را مشغول می‌کند و بقیه را برای بازدیدکنندگان آزاد می‌گذارد
            {s.pace > 1.05 ? `؛ چون سرور اخیراً تحت فشار بوده، ${fa(Math.round(s.pace * 10) / 10)} برابر آهسته‌تر کار می‌کند` : ""}.
            {s.cooling > 0 ? ` دستهٔ بعدی تا ${fa(s.cooling)} ثانیهٔ دیگر.` : ""}
          </p>
          {series.length > 2 ? (
            <div className="mt-4">
              <AnimatedSparkChart label="محصول در دقیقه (دسته‌های اخیر)" value={series[series.length - 1]} series={series} />
            </div>
          ) : (
            <p className="m-0 mt-4 text-[13px] text-muted-foreground">بعد از اولین کمپین، نمودار سرعت اینجا نمایش داده می‌شود.{perMin ? ` تخمین فعلی: ${num(perMin)} محصول در دقیقه.` : ""}</p>
          )}
        </Card>
      </div>

      <Card>
        <SectionTitle title="وضعیت هاست" />
        <ul className="m-0 grid list-none gap-2 p-0 md:grid-cols-2">
          {checks.map((c) => (
            <li key={c.text} className={cn("m-0 flex items-start gap-2 rounded-xl px-3 py-2.5 text-[13px]", c.ok ? "bg-emerald-50 text-emerald-800" : "bg-amber-50 text-amber-800")}>
              <HugeiconsIcon icon={c.ok ? CheckmarkCircle02Icon : Alert02Icon} size={18} className="mt-0.5 shrink-0" />
              {c.text}
            </li>
          ))}
        </ul>
        <p className="m-0 mt-4 text-xs leading-6 text-muted-foreground">
          آخرین فعالیت: {s.worker.heartbeat ? ago(s.worker.heartbeat, s.worker.now) : "هنوز کاری انجام نشده"}
          {s.last_skip ? ` · ${SKIP[s.last_skip.reason] ?? s.last_skip.reason} (${ago(s.last_skip.t, s.worker.now)})` : ""}
          {s.last_skip?.note ? <span className="mt-1 block break-all font-mono text-[11px]" dir="ltr">{s.last_skip.note}</span> : null}
          {` · PHP ${s.php_version} · ووکامرس ${s.wc_version ?? "—"}`}
        </p>
      </Card>

      {hist.length ? (
        <Card className="p-0">
          <p className="m-0 border-b border-border px-5 py-3 font-bold">دسته‌های اخیر</p>
          <div className="overflow-x-auto">
            <table className="w-full border-collapse text-[13px]">
              <thead>
                <tr className="text-xs text-muted-foreground">
                  <th className="px-5 py-2 text-start font-medium">زمان</th>
                  <th className="px-2 py-2 text-start font-medium">محصول</th>
                  <th className="px-2 py-2 text-start font-medium">مدت</th>
                  <th className="px-2 py-2 text-start font-medium">استراحت</th>
                  <th className="px-2 py-2 text-start font-medium">حافظه</th>
                  <th className="px-2 py-2 text-start font-medium">دستهٔ بعد</th>
                  <th className="px-5 py-2 text-start font-medium">وضعیت سرور</th>
                </tr>
              </thead>
              <tbody>
                {[...hist].reverse().map((h) => (
                  <tr key={h.t + "-" + h.items} className="border-t border-border/60">
                    <td className="px-5 py-2 text-muted-foreground">{ago(h.t, s.worker.now)}</td>
                    <td className="px-2 tabular-nums">{num(h.items)}</td>
                    <td className="px-2 tabular-nums">{fa(h.secs)} ث</td>
                    <td className="px-2 tabular-nums">{h.rest !== undefined ? `${fa(h.rest)} ث` : "—"}</td>
                    <td className="px-2 tabular-nums">{fa(h.mem_mb)} مگ</td>
                    <td className="px-2 tabular-nums">{num(h.next)}</td>
                    <td className="px-5 text-muted-foreground">{h.stress ? h.stress.split(",").map((k) => STRESS[k] ?? k).join("، ") : "عادی"}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      ) : null}
    </div>
  );
}

function Mini({ icon, label, value }: { icon: typeof CpuIcon; label: string; value: string }) {
  return (
    <div className="rounded-xl bg-muted/60 px-3 py-3">
      <p className="m-0 flex items-center gap-1.5 text-xs text-muted-foreground">
        <HugeiconsIcon icon={icon} size={14} /> {label}
      </p>
      <p className="m-0 mt-1 text-[15px] font-bold">{value}</p>
    </div>
  );
}

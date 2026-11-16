import { motion } from "motion/react";
import { HugeiconsIcon } from "@hugeicons/react";
import { Add01Icon, Store01Icon, Folder01Icon, CheckListIcon, RepeatIcon, DiscountTag01Icon, ArrowLeft01Icon } from "@hugeicons/core-free-icons";
import type { Campaign } from "@/lib/types";
import { WORKING } from "@/lib/types";
import { STATUS, discountText, targetText, REPEAT } from "@/lib/labels";
import { jalaliShort, num, parseLocal } from "@/lib/format";
import { cn } from "@/lib/utils";
import { StatusMark } from "@/components/status-mark";
import { Badge, Btn, Card, Empty, ProgressBar, Skeleton } from "./ui";
import type { WizardPreset } from "./Wizard";
import { campaignProgress, markStatus } from "./progress";

const SCENARIOS: { preset: WizardPreset; icon: typeof Store01Icon; title: string; text: string }[] = [
  { preset: { target_type: "all_in_stock", timing: "now_until" }, icon: Store01Icon, title: "حراج کل فروشگاه", text: "تخفیف روی همهٔ محصولات موجود، از الان تا تاریخی که می‌گویید." },
  { preset: { target_type: "categories", timing: "window" }, icon: Folder01Icon, title: "تخفیف یک دسته‌بندی", text: "مثلاً «کتاب کودک» از پنجشنبه تا جمعه." },
  { preset: { target_type: "products", timing: "now_open" }, icon: CheckListIcon, title: "محصولات منتخب", text: "چند محصول را جستجو و انتخاب کنید." },
  { preset: { target_type: "all_in_stock", timing: "repeat" }, icon: RepeatIcon, title: "جمعه‌های تخفیف", text: "یک بازهٔ تکرارشونده هر هفته یا هر روز." },
];

export function CampaignList({
  campaigns,
  loading,
  onOpen,
  onNew,
}: {
  campaigns: Campaign[] | null;
  loading: boolean;
  onOpen: (c: Campaign) => void;
  onNew: (preset?: WizardPreset) => void;
}) {
  if (loading && !campaigns) {
    return (
      <div className="grid gap-4 md:grid-cols-2">
        {[0, 1, 2, 3].map((i) => (
          <Skeleton key={i} className="h-40" />
        ))}
      </div>
    );
  }

  const live = (campaigns ?? []).filter((c) => !["draft", "ended"].includes(c.status));
  const drafts = (campaigns ?? []).filter((c) => c.status === "draft");
  const ended = (campaigns ?? []).filter((c) => c.status === "ended");

  return (
    <div className="flex flex-col gap-8">
      <section>
        <div className="mb-3 flex items-center justify-between">
          <h2 className="m-0 text-[15px] font-bold">شروع سریع</h2>
        </div>
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          {SCENARIOS.map((s, i) => (
            <motion.button
              key={s.title}
              type="button"
              initial={{ opacity: 0, y: 10 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ delay: i * 0.05 }}
              whileHover={{ y: -2 }}
              whileTap={{ scale: 0.98 }}
              onClick={() => onNew(s.preset)}
              className="group flex flex-col items-start gap-2 rounded-2xl border border-border bg-card p-4 text-start shadow-[0_1px_2px_rgba(0,0,0,0.03)] hover:border-primary/40"
            >
              <span className="flex size-10 items-center justify-center rounded-xl bg-primary/10 text-primary">
                <HugeiconsIcon icon={s.icon} size={22} />
              </span>
              <span className="font-bold">{s.title}</span>
              <span className="text-[13px] leading-6 text-muted-foreground">{s.text}</span>
              <span className="mt-auto flex items-center gap-1 text-[13px] font-medium text-primary opacity-0 transition-opacity group-hover:opacity-100">
                شروع <HugeiconsIcon icon={ArrowLeft01Icon} size={14} />
              </span>
            </motion.button>
          ))}
        </div>
      </section>

      {campaigns && campaigns.length === 0 ? (
        <Card>
          <Empty
            icon={DiscountTag01Icon}
            title="هنوز تخفیفی نساخته‌اید"
            text="با یکی از گزینه‌های بالا شروع کنید یا یک تخفیف دلخواه بسازید. قیمت اصلی همهٔ محصولات نگه‌داری می‌شود و در پایان دقیقاً برمی‌گردد."
            action={
              <Btn variant="primary" icon={Add01Icon} onClick={() => onNew()}>
                ساخت تخفیف جدید
              </Btn>
            }
          />
        </Card>
      ) : null}

      {live.length ? <Group title="در جریان" items={live} onOpen={onOpen} /> : null}
      {drafts.length ? <Group title="پیش‌نویس‌ها" items={drafts} onOpen={onOpen} /> : null}
      {ended.length ? <Group title="تمام‌شده‌ها" items={ended} onOpen={onOpen} muted /> : null}
    </div>
  );
}

function Group({ title, items, onOpen, muted }: { title: string; items: Campaign[]; onOpen: (c: Campaign) => void; muted?: boolean }) {
  return (
    <section>
      <h2 className={cn("m-0 mb-3 text-[15px] font-bold", muted && "text-muted-foreground")}>
        {title}
        {muted ? <span className="ms-2 text-xs font-normal">({num(items.length)})</span> : null}
      </h2>
      <div className="grid gap-4 md:grid-cols-2">
        {items.map((c, i) => (
          <CampaignCard key={c.id} c={c} index={i} onOpen={() => onOpen(c)} />
        ))}
      </div>
    </section>
  );
}

function CampaignCard({ c, index, onOpen }: { c: Campaign; index: number; onOpen: () => void }) {
  const st = STATUS[c.status];
  const p = campaignProgress(c);
  const working = WORKING.includes(c.status);
  // A finished campaign stays readable but steps back visually: faded, desaturated,
  // dashed border, no shadow — it comes back to full colour on hover/focus.
  const done = c.status === "ended";
  return (
    <motion.button
      type="button"
      layout
      initial={{ opacity: 0, y: 12 }}
      animate={{ opacity: done ? 0.62 : 1, y: 0 }}
      transition={{ delay: index * 0.04 }}
      onClick={onOpen}
      whileHover={{ y: -2, opacity: 1 }}
      whileFocus={{ opacity: 1 }}
      className={cn(
        "flex flex-col gap-3 rounded-2xl border p-5 text-start outline-none transition-[filter,background-color,border-color] duration-200 focus-visible:ring-2 focus-visible:ring-ring",
        done
          ? "border-dashed border-border bg-muted/40 grayscale-[70%] hover:bg-card hover:grayscale-0 focus-visible:grayscale-0"
          : "border-border bg-card shadow-[0_1px_2px_rgba(0,0,0,0.03)] hover:border-primary/40",
      )}
    >
      <div className="flex w-full items-start gap-3">
        <StatusMark status={markStatus(c)} progress={working ? p.ratio : undefined} size={26} className="mt-0.5 text-primary" />
        <div className="min-w-0 flex-1">
          <p className={cn("m-0 truncate text-[15px]", done ? "font-semibold text-muted-foreground" : "font-bold")}>{c.name}</p>
          <p className="m-0 mt-0.5 text-[13px] text-muted-foreground">
            {discountText(c)} · {targetText(c)}
          </p>
        </div>
        <Badge tone={st.tone}>{st.label}</Badge>
      </div>
      {working || c.status === "paused" ? (
        <div className="w-full">
          <ProgressBar value={p.ratio} tone={c.status.includes("revert") ? "amber" : "primary"} />
          <p className="m-0 mt-1.5 text-xs text-muted-foreground">{p.text}</p>
        </div>
      ) : null}
      <div className="flex w-full flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
        {c.status === "active" ? <span>{num(c.stats.applied)} محصول با تخفیف</span> : null}
        {done && c.ends_local ? <span>پایان یافته: {jalaliShort(parseLocal(c.ends_local))}</span> : null}
        {done && c.stats.reverted > 0 ? <span>قیمت {num(c.stats.reverted)} محصول برگشت</span> : null}
        {c.starts_local && c.status === "scheduled" ? <span>شروع: {jalaliShort(parseLocal(c.starts_local))}</span> : null}
        {c.ends_local && !["ended", "draft"].includes(c.status) ? <span>پایان: {jalaliShort(parseLocal(c.ends_local))}</span> : null}
        {c.repeat_mode !== "none" ? <span>{REPEAT[c.repeat_mode]}</span> : null}
        {c.stats.failed > 0 ? <span className="text-rose-600">{num(c.stats.failed)} مورد ناموفق</span> : null}
      </div>
    </motion.button>
  );
}

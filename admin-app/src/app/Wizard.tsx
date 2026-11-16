import { useEffect, useMemo, useState, type ReactNode } from "react";
import { AnimatePresence, motion } from "motion/react";
import { HugeiconsIcon } from "@hugeicons/react";
import {
  PercentCircleIcon,
  Money03Icon,
  Store01Icon,
  Folder01Icon,
  CheckListIcon,
  FlashIcon,
  Calendar03Icon,
  CalendarCheckOut01Icon,
  RepeatIcon,
  ArrowLeft01Icon,
  ArrowRight01Icon,
  Alert02Icon,
  Tick02Icon,
  Settings02Icon,
} from "@hugeicons/core-free-icons";
import { api, ApiError, boot } from "@/lib/api";
import type { Campaign, CampaignInput, Category, Preview, RepeatMode, SalePolicy } from "@/lib/types";
import { fa, jalali, money, num, parseLocal, siteNow, toLatin, toLocalString, duration } from "@/lib/format";
import { useBasket } from "@/lib/basket";
import { GlideSelect } from "@/components/glide-select";
import { SoftSwitch } from "@/components/animated-switches";
import { SaveButton } from "@/components/save-button";
import { ChoiceCard, Field, Segmented, TextInput, Btn, Badge, Skeleton } from "./ui";
import { ProductPicker } from "./ProductPicker";
import { CategoryPicker } from "./CategoryPicker";
import { DateTimeField } from "./DateTimeField";
import { useToast } from "./Toaster";
import { cn } from "@/lib/utils";

export type TimingMode = "now_open" | "now_until" | "window" | "repeat";

export interface WizardPreset {
  target_type?: CampaignInput["target_type"];
  timing?: TimingMode;
}

const STEPS = ["تخفیف", "محصولات", "زمان", "تأیید"] as const;

const GLIDE = { accentColor: "var(--foreground)", surfaceColor: "var(--card)", highlightColor: "var(--accent)", textColor: "var(--foreground)" };

function emptyInput(): CampaignInput {
  return {
    name: "",
    discount_type: "percent",
    discount_value: 10,
    target_type: "all_in_stock",
    target: { product_ids: [], category_ids: [], exclude_product_ids: [], exclude_category_ids: [] },
    options: { only_in_stock: true, sale_policy: "best", round_step: 0, min_price: 0, include_children: true },
    starts_at: null,
    ends_at: null,
    repeat_mode: "none",
  };
}

function fromCampaign(c: Campaign): CampaignInput {
  return {
    name: c.name,
    discount_type: c.discount_type,
    discount_value: c.discount_value,
    target_type: c.target_type,
    target: c.target,
    options: c.options,
    starts_at: c.starts_local,
    ends_at: c.ends_local,
    repeat_mode: c.repeat_mode,
  };
}

function guessTiming(c: CampaignInput): TimingMode {
  if (c.repeat_mode !== "none") return "repeat";
  if (c.starts_at && parseLocal(c.starts_at)! > siteNow()) return "window";
  return c.ends_at ? "now_until" : "now_open";
}

export function Wizard({
  editing,
  preset,
  categories,
  onDone,
  onCancel,
}: {
  editing?: Campaign | null;
  preset?: WizardPreset;
  categories: Category[];
  onDone: (c: Campaign) => void;
  onCancel: () => void;
}) {
  const toast = useToast();
  const [step, setStep] = useState(0);
  const [dir, setDir] = useState(1);
  const [form, setForm] = useState<CampaignInput>(() => {
    const base = editing ? fromCampaign(editing) : emptyInput();
    if (preset?.target_type) base.target_type = preset.target_type;
    return base;
  });
  const [timing, setTiming] = useState<TimingMode>(() => preset?.timing ?? (editing ? guessTiming(fromCampaign(editing)) : "now_open"));
  const basket = useBasket(editing ? editing.target.product_ids : []);
  const [preview, setPreview] = useState<Preview | null>(null);
  const [previewing, setPreviewing] = useState(false);
  const [error, setError] = useState("");

  const set = <K extends keyof CampaignInput>(k: K, v: CampaignInput[K]) => setForm((f) => ({ ...f, [k]: v }));
  const setOpt = <K extends keyof CampaignInput["options"]>(k: K, v: CampaignInput["options"][K]) => setForm((f) => ({ ...f, options: { ...f.options, [k]: v } }));
  const setTarget = <K extends keyof CampaignInput["target"]>(k: K, v: CampaignInput["target"][K]) => setForm((f) => ({ ...f, target: { ...f.target, [k]: v } }));

  // Basket → target product ids.
  const productIds = useMemo(() => basket.items.map((i) => i.id), [basket.items]);
  const payload = useMemo<CampaignInput>(() => {
    const p: CampaignInput = { ...form, target: { ...form.target, product_ids: productIds } };
    if (timing === "now_open") {
      p.starts_at = null;
      p.ends_at = null;
      p.repeat_mode = "none";
    } else if (timing === "now_until") {
      p.starts_at = null;
      p.repeat_mode = "none";
    } else if (timing === "window") {
      p.repeat_mode = "none";
    }
    return p;
  }, [form, productIds, timing]);

  // Live preview (debounced).
  useEffect(() => {
    if (step < 1) return;
    const t = setTimeout(() => {
      setPreviewing(true);
      api<Preview>("preview", { method: "POST", body: payload })
        .then((r) => (setPreview(r), setError("")))
        .catch((e: ApiError) => (setPreview(null), setError(e.message)))
        .finally(() => setPreviewing(false));
    }, 400);
    return () => clearTimeout(t);
  }, [payload, step]);

  const stepError = useMemo(() => validateStep(step, payload, timing), [step, payload, timing]);

  const go = (to: number) => {
    setDir(to > step ? 1 : -1);
    setStep(to);
    window.scrollTo({ top: 0, behavior: "smooth" });
  };

  const submit = async (start: boolean) => {
    try {
      const body = { ...payload, start };
      const c = editing
        ? await api<Campaign>(`campaigns/${editing.id}`, { method: "PUT", body })
        : await api<Campaign>("campaigns", { method: "POST", body });
      let result = c;
      if (editing && start && (c.status === "draft" || c.status === "ended")) {
        result = await api<Campaign>(`campaigns/${c.id}/start`, { method: "POST" });
      }
      basket.clear();
      toast("success", start ? (result.status === "scheduled" ? "زمان‌بندی شد" : "تخفیف شروع شد") : "ذخیره شد", start ? "پیشرفت کار را در صفحهٔ کمپین ببینید." : "هر وقت خواستید آن را شروع کنید.");
      onDone(result);
    } catch (e) {
      toast("error", "ذخیره نشد", (e as Error).message);
      throw e;
    }
  };

  return (
    <div className="mx-auto max-w-5xl">
      <Stepper step={step} onJump={(i) => i < step && go(i)} />

      <div className="relative mt-6">
        <AnimatePresence mode="popLayout" custom={dir} initial={false}>
          <motion.div
            key={step}
            custom={dir}
            initial={{ opacity: 0, x: dir * -24 }}
            animate={{ opacity: 1, x: 0 }}
            exit={{ opacity: 0, x: dir * 24 }}
            transition={{ type: "spring", bounce: 0, duration: 0.35 }}
          >
            {step === 0 && <StepDiscount form={form} set={set} setOpt={setOpt} />}
            {step === 1 && (
              <StepTarget form={form} set={set} setOpt={setOpt} setTarget={setTarget} categories={categories} basket={basket} preview={preview} previewing={previewing} />
            )}
            {step === 2 && <StepTiming form={form} set={set} timing={timing} setTiming={setTiming} />}
            {step === 3 && <StepReview payload={payload} timing={timing} preview={preview} previewing={previewing} categories={categories} />}
          </motion.div>
        </AnimatePresence>
      </div>

      {error && step > 0 && !stepError ? (
        <p className="mt-4 flex items-center gap-2 rounded-xl bg-rose-50 px-4 py-3 text-[13px] text-rose-700">
          <HugeiconsIcon icon={Alert02Icon} size={18} /> {error}
        </p>
      ) : null}

      <div className="sticky bottom-0 z-10 mt-6 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-border bg-card/95 p-3 shadow-[0_-8px_24px_-18px_rgba(0,0,0,0.25)] backdrop-blur">
        <div className="flex items-center gap-2">
          {step > 0 ? (
            <Btn icon={ArrowRight01Icon} onClick={() => go(step - 1)}>
              مرحلهٔ قبل
            </Btn>
          ) : (
            <Btn variant="ghost" onClick={onCancel}>
              انصراف
            </Btn>
          )}
          {stepError ? <span className="text-[13px] text-amber-700">{stepError}</span> : null}
        </div>
        {step < 3 ? (
          <Btn variant="primary" onClick={() => go(step + 1)} disabled={!!stepError} className="px-6">
            مرحلهٔ بعد
            <HugeiconsIcon icon={ArrowLeft01Icon} size={18} />
          </Btn>
        ) : (
          <div className="flex items-center gap-2">
            <SaveButton variant="soft" label="ذخیره بدون شروع" onClick={() => submit(false)} disabled={!!stepError || !!error} />
            <SaveButton
              label={timing === "window" || timing === "repeat" ? "زمان‌بندی کن" : "همین الان شروع کن"}
              loadingLabel="در حال ثبت…"
              doneLabel="ثبت شد"
              onClick={() => submit(true)}
              disabled={!!stepError || !!error || (preview?.count ?? 0) === 0}
            />
          </div>
        )}
      </div>
    </div>
  );
}

export function validateStep(step: number, p: CampaignInput, timing: TimingMode): string {
  if (step >= 0) {
    if (!(p.discount_value > 0)) return "مقدار تخفیف را وارد کنید.";
    if (p.discount_type === "percent" && p.discount_value >= 100) return "درصد باید کمتر از ۱۰۰ باشد.";
  }
  if (step >= 1) {
    if (p.target_type === "products" && p.target.product_ids.length === 0) return "حداقل یک محصول به سبد اضافه کنید.";
    if (p.target_type === "categories" && p.target.category_ids.length === 0) return "حداقل یک دسته‌بندی انتخاب کنید.";
  }
  if (step >= 2) {
    const now = siteNow();
    const s = parseLocal(p.starts_at);
    const e = parseLocal(p.ends_at);
    if (timing !== "now_open" && !e) return "زمان پایان را انتخاب کنید.";
    if ((timing === "window" || timing === "repeat") && !s) return "زمان شروع را انتخاب کنید.";
    if (e && e <= now) return "زمان پایان گذشته است.";
    if (s && e && e <= s) return "پایان باید بعد از شروع باشد.";
    if (timing === "repeat" && s && e) {
      const len = (e.getTime() - s.getTime()) / 1000;
      if (len > (p.repeat_mode === "daily" ? 86400 : 604800)) return "بازه از فاصلهٔ تکرار طولانی‌تر است.";
    }
  }
  return "";
}

// ---------------------------------------------------------------------------

function Stepper({ step, onJump }: { step: number; onJump: (i: number) => void }) {
  return (
    <ol className="m-0 flex list-none items-center gap-2 p-0">
      {STEPS.map((label, i) => (
        <li key={label} className="m-0 flex flex-1 items-center gap-2">
          <button type="button" onClick={() => onJump(i)} className={cn("flex items-center gap-2", i < step ? "cursor-pointer" : "cursor-default")}>
            <motion.span
              animate={{ scale: i === step ? 1 : 0.9 }}
              className={cn(
                "flex size-8 items-center justify-center rounded-full text-sm font-bold transition-colors",
                i < step && "bg-primary text-primary-foreground",
                i === step && "bg-primary/15 text-primary ring-2 ring-primary",
                i > step && "bg-muted text-muted-foreground",
              )}
            >
              {i < step ? <HugeiconsIcon icon={Tick02Icon} size={16} /> : fa(i + 1)}
            </motion.span>
            <span className={cn("hidden text-sm font-medium sm:inline", i === step ? "text-foreground" : "text-muted-foreground")}>{label}</span>
          </button>
          {i < STEPS.length - 1 ? (
            <span className="h-0.5 flex-1 overflow-hidden rounded-full bg-muted">
              <motion.span className="block h-full bg-primary" initial={false} animate={{ width: i < step ? "100%" : "0%" }} />
            </span>
          ) : null}
        </li>
      ))}
    </ol>
  );
}

function StepShell({ title, hint, children }: { title: string; hint: string; children: ReactNode }) {
  return (
    <section>
      <h2 className="m-0 text-xl font-bold">{title}</h2>
      <p className="m-0 mt-1 mb-5 text-[13px] text-muted-foreground">{hint}</p>
      {children}
    </section>
  );
}

type SetFn = <K extends keyof CampaignInput>(k: K, v: CampaignInput[K]) => void;
type SetOptFn = <K extends keyof CampaignInput["options"]>(k: K, v: CampaignInput["options"][K]) => void;

function StepDiscount({ form, set, setOpt }: { form: CampaignInput; set: SetFn; setOpt: SetOptFn }) {
  const [advanced, setAdvanced] = useState(form.options.round_step > 0 || form.options.sale_policy !== "best" || form.options.min_price > 0);
  const [raw, setRaw] = useState(form.discount_value ? String(form.discount_value) : "");
  const sample = 250000;
  const value = form.discount_value;
  let after = form.discount_type === "percent" ? (sample * (100 - value)) / 100 : sample - value;
  if (form.options.round_step > 0) after = Math.floor(after / form.options.round_step) * form.options.round_step;

  return (
    <StepShell title="چه تخفیفی می‌خواهید بدهید؟" hint="نوع و مقدار تخفیف را مشخص کنید. نمونهٔ قیمت پایین صفحه بلافاصله به‌روز می‌شود.">
      <div className="grid gap-5 lg:grid-cols-[1fr_300px]">
        <div className="flex flex-col gap-5 rounded-2xl border border-border bg-card p-5">
          <div className="grid gap-3 sm:grid-cols-2">
            <ChoiceCard selected={form.discount_type === "percent"} onSelect={() => set("discount_type", "percent")} icon={PercentCircleIcon} title="درصدی" description="مثلاً ۲۰٪ از قیمت هر محصول کم شود." />
            <ChoiceCard selected={form.discount_type === "fixed"} onSelect={() => set("discount_type", "fixed")} icon={Money03Icon} title="مبلغ ثابت" description={`مثلاً ۵۰٬۰۰۰ ${boot.currency} از قیمت هر محصول کم شود.`} />
          </div>
          <Field label={form.discount_type === "percent" ? "درصد تخفیف" : `مبلغ تخفیف (${boot.currency})`}>
            <div className="relative">
              <TextInput
                inputMode="decimal"
                value={fa(raw)}
                onChange={(e) => {
                  const v = toLatin(e.target.value).replace(/[^\d.]/g, "");
                  setRaw(v);
                  set("discount_value", Number(v) || 0);
                }}
                className="h-14 pe-16 text-2xl font-bold tabular-nums"
                placeholder={form.discount_type === "percent" ? "۲۰" : "۵۰۰۰۰"}
              />
              <span className="pointer-events-none absolute inset-y-0 left-4 flex items-center text-lg text-muted-foreground">{form.discount_type === "percent" ? "٪" : boot.currency}</span>
            </div>
            {form.discount_type === "percent" ? (
              <div className="mt-2 flex flex-wrap gap-2">
                {[5, 10, 15, 20, 25, 30, 40, 50].map((p) => (
                  <button
                    key={p}
                    type="button"
                    onClick={() => (setRaw(String(p)), set("discount_value", p))}
                    className={cn("rounded-full border px-3 py-1 text-[13px] transition-colors", value === p ? "border-primary bg-primary text-primary-foreground" : "border-border hover:bg-accent")}
                  >
                    {fa(p)}٪
                  </button>
                ))}
              </div>
            ) : null}
          </Field>
          <Field label="نام کمپین (اختیاری)" hint="فقط برای خودتان؛ مشتری آن را نمی‌بیند.">
            <TextInput value={form.name} onChange={(e) => set("name", e.target.value)} placeholder="مثلاً حراج آخر پاییز" />
          </Field>

          <button type="button" onClick={() => setAdvanced((a) => !a)} className="flex items-center gap-2 self-start text-[13px] font-medium text-primary">
            <HugeiconsIcon icon={Settings02Icon} size={16} />
            {advanced ? "بستن تنظیمات بیشتر" : "تنظیمات بیشتر (رُند کردن، تخفیف‌های قبلی)"}
          </button>
          <AnimatePresence initial={false}>
            {advanced ? (
              // Fade only (no height animation): an overflow:hidden wrapper clipped the
              // tooltips and dropdown menus inside this panel.
              <motion.div initial={{ opacity: 0, y: -6 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: -6 }} transition={{ duration: 0.2 }}>
                <div className="grid gap-4 rounded-xl bg-muted/50 p-4 sm:grid-cols-2">
                  <Field label="رُند کردن قیمت جدید" help="قیمت نهایی به پایین گرد می‌شود تا عدد قشنگ‌تری داشته باشد؛ مشتری هیچ‌وقت بیشتر از تخفیف اعلام‌شده نمی‌پردازد.">
                    <GlideSelect
                      {...GLIDE}
                      fullWidth
                      size="lg"
                      menuWidth={220}
                      ariaLabel="رُند کردن قیمت جدید"
                      showTags={false}
                      value={String(form.options.round_step)}
                      onChange={(v) => setOpt("round_step", Number(v))}
                      options={[
                        { value: "0", label: "رُند نکن" },
                        { value: "100", label: `به ۱۰۰ ${boot.currency}` },
                        { value: "1000", label: `به ۱٬۰۰۰ ${boot.currency}` },
                        { value: "5000", label: `به ۵٬۰۰۰ ${boot.currency}` },
                        { value: "10000", label: `به ۱۰٬۰۰۰ ${boot.currency}` },
                      ]}
                    />
                  </Field>
                  <Field label="اگر محصول از قبل تخفیف داشت" help="بعد از پایان کمپین، تخفیف قبلی محصول دوباره برمی‌گردد.">
                    <GlideSelect
                      {...GLIDE}
                      fullWidth
                      size="lg"
                      menuWidth={260}
                      ariaLabel="اگر محصول از قبل تخفیف داشت"
                      showTags={false}
                      value={form.options.sale_policy}
                      onChange={(v) => setOpt("sale_policy", v as SalePolicy)}
                      options={[
                        { value: "best", label: "هر کدام ارزان‌تر بود" },
                        { value: "override", label: "تخفیف جدید جایگزین شود" },
                        { value: "keep", label: "به آن محصول دست نزن" },
                      ]}
                    />
                  </Field>
                  <Field label="کمترین قیمت مجاز (اختیاری)" hint="اگر قیمت بعد از تخفیف کمتر از این شد، آن محصول رد می‌شود.">
                    <TextInput inputMode="numeric" value={form.options.min_price ? fa(form.options.min_price) : ""} onChange={(e) => setOpt("min_price", Number(toLatin(e.target.value)) || 0)} placeholder="۰" />
                  </Field>
                </div>
              </motion.div>
            ) : null}
          </AnimatePresence>
        </div>

        <aside className="flex flex-col justify-center gap-3 rounded-2xl border border-dashed border-primary/30 bg-primary/[0.03] p-5 text-center">
          <span className="text-[13px] text-muted-foreground">نمونه: محصولی به قیمت</span>
          <span className="text-lg text-muted-foreground line-through decoration-rose-400">{money(sample)}</span>
          <motion.span key={after} initial={{ scale: 0.9, opacity: 0 }} animate={{ scale: 1, opacity: 1 }} className="text-3xl font-black text-primary tabular-nums">
            {after > 0 && after < sample ? money(after) : "—"}
          </motion.span>
          {after > 0 && after < sample ? <Badge tone="red" className="self-center">{fa(Math.round(((sample - after) / sample) * 100))}٪ ارزان‌تر</Badge> : null}
        </aside>
      </div>
    </StepShell>
  );
}

function StepTarget({
  form,
  set,
  setOpt,
  setTarget,
  categories,
  basket,
  preview,
  previewing,
}: {
  form: CampaignInput;
  set: SetFn;
  setOpt: SetOptFn;
  setTarget: <K extends keyof CampaignInput["target"]>(k: K, v: CampaignInput["target"][K]) => void;
  categories: Category[];
  basket: ReturnType<typeof useBasket>;
  preview: Preview | null;
  previewing: boolean;
}) {
  const [showExclude, setShowExclude] = useState(form.target.exclude_category_ids.length > 0);
  return (
    <StepShell title="تخفیف روی کدام محصولات اعمال شود؟" hint="یکی از سه حالت را انتخاب کنید. تعداد دقیق محصولات پایین صفحه نشان داده می‌شود.">
      <div className="grid gap-3 md:grid-cols-3">
        <ChoiceCard selected={form.target_type === "all_in_stock"} onSelect={() => set("target_type", "all_in_stock")} icon={Store01Icon} title="همهٔ محصولات موجود" description="هر محصولی که الان موجود است (ناموجودها دست نمی‌خورند)." />
        <ChoiceCard selected={form.target_type === "categories"} onSelect={() => set("target_type", "categories")} icon={Folder01Icon} title="یک یا چند دسته‌بندی" description="همهٔ محصولات دسته‌های انتخابی و زیرمجموعه‌هایشان." />
        <ChoiceCard selected={form.target_type === "products"} onSelect={() => set("target_type", "products")} icon={CheckListIcon} title="محصولات دلخواه" description="جستجو کنید و محصولات را یکی‌یکی به سبد اضافه کنید." />
      </div>

      <div className="mt-5">
        {form.target_type === "products" ? <ProductPicker basket={basket} categories={categories} onlyInStock={form.options.only_in_stock} /> : null}
        {form.target_type === "categories" ? (
          <div className="grid gap-4 md:grid-cols-2">
            <CategoryPicker categories={categories} selected={form.target.category_ids} onChange={(ids) => setTarget("category_ids", ids)} includeChildren={form.options.include_children} />
            <div className="flex flex-col gap-3 rounded-2xl border border-border bg-card p-4">
              <SwitchRow label="زیرمجموعه‌ها هم حساب شوند" checked={form.options.include_children} onChange={(v) => setOpt("include_children", v)} />
              <SwitchRow label="فقط محصولات موجود" checked={form.options.only_in_stock} onChange={(v) => setOpt("only_in_stock", v)} />
            </div>
          </div>
        ) : null}
        {form.target_type === "products" ? (
          <div className="mt-3">
            <SwitchRow label="محصولات ناموجود سبد را رد کن" checked={form.options.only_in_stock} onChange={(v) => setOpt("only_in_stock", v)} />
          </div>
        ) : null}

        {form.target_type !== "products" ? (
          <div className="mt-4">
            <button type="button" className="text-[13px] font-medium text-primary" onClick={() => setShowExclude((s) => !s)}>
              {showExclude ? "بستن استثناها" : "+ چند دسته‌بندی را مستثنا کن"}
            </button>
            {showExclude ? (
              <div className="mt-3 max-w-xl">
                <CategoryPicker tone="danger" categories={categories} selected={form.target.exclude_category_ids} onChange={(ids) => setTarget("exclude_category_ids", ids)} includeChildren />
              </div>
            ) : null}
          </div>
        ) : null}
      </div>

      <PreviewBar preview={preview} previewing={previewing} />
    </StepShell>
  );
}

function SwitchRow({ label, checked, onChange }: { label: string; checked: boolean; onChange: (v: boolean) => void }) {
  return (
    <div className="flex items-center justify-between gap-3">
      <span className="text-[14px]">{label}</span>
      <SoftSwitch checked={checked} onCheckedChange={onChange} aria-label={label} />
    </div>
  );
}

function PreviewBar({ preview, previewing }: { preview: Preview | null; previewing: boolean }) {
  return (
    <div className="mt-5 flex flex-wrap items-center gap-3 rounded-2xl bg-primary/[0.06] px-5 py-4">
      <span className="text-[14px]">تعداد محصولاتی که تخفیف می‌گیرند:</span>
      {previewing && !preview ? <Skeleton className="h-6 w-16" /> : <span className={cn("text-xl font-black text-primary tabular-nums transition-opacity", previewing && "opacity-50")}>{preview ? num(preview.count) : "—"}</span>}
      <span className="text-xs text-muted-foreground">(هر تنوعِ محصولات متغیر جداگانه شمرده می‌شود)</span>
      {preview && preview.conflicts > 0 ? <Badge tone="violet">{num(preview.conflicts)} مورد در کمپین دیگری است و رد می‌شود</Badge> : null}
    </div>
  );
}

function StepTiming({ form, set, timing, setTiming }: { form: CampaignInput; set: SetFn; timing: TimingMode; setTiming: (t: TimingMode) => void }) {
  const now = siteNow();
  const start = parseLocal(form.starts_at);
  const end = parseLocal(form.ends_at);
  const len = (timing === "now_until" ? (end ? (end.getTime() - now.getTime()) / 1000 : 0) : start && end ? (end.getTime() - start.getTime()) / 1000 : 0);

  const quickEnd = (hours: number) => set("ends_at", toLocalString(new Date(now.getTime() + hours * 3600 * 1000)));

  return (
    <StepShell title="تخفیف چه زمانی فعال باشد؟" hint="تاریخ‌ها شمسی و به وقت سایت هستند. در زمان پایان، قیمت‌ها خودکار به حالت قبل برمی‌گردند.">
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <ChoiceCard selected={timing === "now_open"} onSelect={() => setTiming("now_open")} icon={FlashIcon} title="از همین الان" description="تا وقتی خودتان متوقفش کنید." />
        <ChoiceCard selected={timing === "now_until"} onSelect={() => setTiming("now_until")} icon={CalendarCheckOut01Icon} title="از الان تا تاریخ مشخص" description="مثلاً تا پایان جمعه شب." />
        <ChoiceCard selected={timing === "window"} onSelect={() => setTiming("window")} icon={Calendar03Icon} title="بازهٔ زمانی دلخواه" description="از یک تاریخ تا تاریخ دیگر؛ خودکار شروع و تمام می‌شود." />
        <ChoiceCard selected={timing === "repeat"} onSelect={() => (setTiming("repeat"), form.repeat_mode === "none" && set("repeat_mode", "weekly"))} icon={RepeatIcon} title="تکرارشونده" description="مثلاً هر جمعه، یا هر روز ساعت ۲۰ تا ۲۲." />
      </div>

      <div className="mt-5 rounded-2xl border border-border bg-card p-5">
        {timing === "now_open" ? <p className="m-0 text-[14px] text-muted-foreground">تخفیف بلافاصله بعد از تأیید شروع می‌شود و تا وقتی دکمهٔ «پایان تخفیف» را نزنید فعال می‌ماند.</p> : null}

        {timing !== "now_open" ? (
          <div className="grid gap-6 md:grid-cols-2">
            {timing === "window" || timing === "repeat" ? (
              <Field label="شروع">
                <DateTimeField value={form.starts_at} onChange={(v) => set("starts_at", v)} placeholder="تاریخ شروع" />
              </Field>
            ) : null}
            <Field label="پایان">
              <DateTimeField value={form.ends_at} onChange={(v) => set("ends_at", v)} placeholder="تاریخ پایان" defaultTime="23:59" />
              {timing === "now_until" ? (
                <div className="mt-1 flex flex-wrap gap-2">
                  {[
                    { h: 6, l: "۶ ساعت" },
                    { h: 24, l: "۱ روز" },
                    { h: 72, l: "۳ روز" },
                    { h: 168, l: "۱ هفته" },
                  ].map((x) => (
                    <button key={x.h} type="button" onClick={() => quickEnd(x.h)} className="rounded-full border border-border px-3 py-1 text-[12px] hover:bg-accent">
                      {x.l} دیگر
                    </button>
                  ))}
                </div>
              ) : null}
            </Field>
            {timing === "repeat" ? (
              <Field label="تکرار" hint="بعد از هر دوره، قیمت‌ها برمی‌گردند و دورهٔ بعد خودکار زمان‌بندی می‌شود.">
                <Segmented<RepeatMode>
                  value={form.repeat_mode === "none" ? "weekly" : form.repeat_mode}
                  onChange={(v) => set("repeat_mode", v)}
                  options={[
                    { value: "daily", label: "هر روز" },
                    { value: "weekly", label: "هر هفته" },
                  ]}
                />
              </Field>
            ) : null}
          </div>
        ) : null}

        {len > 0 ? (
          <p className="m-0 mt-5 rounded-xl bg-muted/60 px-4 py-3 text-[13px]">
            مدت تخفیف: <b>{duration(len)}</b>
            {timing === "repeat" ? <> — {form.repeat_mode === "daily" ? "هر روز" : "هر هفته"} تکرار می‌شود</> : null}
          </p>
        ) : null}
      </div>
    </StepShell>
  );
}

function StepReview({ payload, timing, preview, previewing, categories }: { payload: CampaignInput; timing: TimingMode; preview: Preview | null; previewing: boolean; categories: Category[] }) {
  const disc = payload.discount_type === "percent" ? `${fa(payload.discount_value)}٪` : money(payload.discount_value);
  const catNames = payload.target.category_ids.map((id) => categories.find((c) => c.id === id)?.name).filter(Boolean);
  const where =
    payload.target_type === "all_in_stock"
      ? "همهٔ محصولات موجود"
      : payload.target_type === "categories"
        ? `دسته‌های ${catNames.join("، ")}`
        : `${num(payload.target.product_ids.length)} محصول انتخابی`;
  const s = parseLocal(payload.starts_at);
  const e = parseLocal(payload.ends_at);
  const when =
    timing === "now_open"
      ? "از همین الان تا وقتی متوقفش کنید"
      : timing === "now_until"
        ? `از همین الان تا ${jalali(e)}`
        : `از ${jalali(s)} تا ${jalali(e)}${timing === "repeat" ? (payload.repeat_mode === "daily" ? "، و هر روز تکرار" : "، و هر هفته تکرار") : ""}`;

  return (
    <StepShell title="مرور نهایی" hint="اگر همه چیز درست است، دکمهٔ پایین را بزنید. کار در پس‌زمینه و با توجه به توان سرور انجام می‌شود؛ لازم نیست صفحه باز بماند.">
      <div className="grid gap-5 lg:grid-cols-[1fr_1fr]">
        <div className="rounded-2xl border border-border bg-card p-5">
          <p className="m-0 text-[17px] leading-9">
            <b className="text-primary">{disc}</b> تخفیف روی <b>{where}</b>
            {preview ? (
              <>
                {" "}
                (<b className="tabular-nums">{num(preview.count)}</b> قلم)
              </>
            ) : null}
            ، <b>{when}</b>.
          </p>
          <ul className="m-0 mt-4 list-none space-y-2 p-0 text-[13px] text-muted-foreground">
            <li>• محصولاتی که از قبل تخفیف دارند: {payload.options.sale_policy === "best" ? "هر کدام ارزان‌تر بود" : payload.options.sale_policy === "override" ? "تخفیف جدید جایگزین می‌شود" : "دست نمی‌خورند"}</li>
            <li>• رُند کردن: {payload.options.round_step ? `به پایین، مضرب ${num(payload.options.round_step)}` : "ندارد"}</li>
            {payload.target_type !== "all_in_stock" ? <li>• محصولات ناموجود: {payload.options.only_in_stock ? "رد می‌شوند" : "هم تخفیف می‌گیرند"}</li> : null}
            <li>• قیمت اصلی هر محصول نگه‌داری می‌شود و در پایان دقیقاً برمی‌گردد.</li>
          </ul>
          {preview && preview.conflicts > 0 ? (
            <p className="m-0 mt-4 rounded-xl bg-violet-50 px-3 py-2 text-[13px] text-violet-700">{num(preview.conflicts)} محصول در کمپین فعال دیگری هستند و این کمپین به آن‌ها دست نمی‌زند.</p>
          ) : null}
        </div>

        <div className="rounded-2xl border border-border bg-card">
          <p className="m-0 border-b border-border px-5 py-3 text-[13px] font-medium">نمونهٔ قیمت‌ها</p>
          {previewing && !preview ? (
            <div className="space-y-2 p-4">
              {[0, 1, 2].map((i) => (
                <Skeleton key={i} className="h-8" />
              ))}
            </div>
          ) : null}
          <table className="w-full border-collapse text-[13px]">
            <tbody>
              {preview?.samples.map((s) => (
                <tr key={s.id} className="border-b border-border/60 last:border-0">
                  <td className="max-w-[200px] truncate px-5 py-2.5">{s.name}</td>
                  <td className="px-2 text-muted-foreground line-through tabular-nums">{money(s.regular)}</td>
                  <td className="px-5 font-bold text-primary tabular-nums">{s.new ? money(s.new) : <span className="font-normal text-muted-foreground">رد می‌شود</span>}</td>
                </tr>
              ))}
            </tbody>
          </table>
          {preview && preview.count === 0 ? <p className="m-0 p-5 text-center text-[13px] text-rose-600">هیچ محصولی با این انتخاب پیدا نشد.</p> : null}
        </div>
      </div>
    </StepShell>
  );
}

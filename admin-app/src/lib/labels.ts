import type { CampaignStatus, Campaign } from "./types";
import { fa, num, money } from "./format";

export const STATUS: Record<CampaignStatus, { label: string; tone: "gray" | "blue" | "green" | "amber" | "red" | "violet"; hint: string }> = {
  draft: { label: "پیش‌نویس", tone: "gray", hint: "هنوز شروع نشده. هر وقت آماده بودید «شروع» را بزنید." },
  scheduled: { label: "زمان‌بندی شده", tone: "violet", hint: "در زمان تعیین‌شده خودکار شروع می‌شود." },
  preparing: { label: "در حال آماده‌سازی", tone: "blue", hint: "فهرست محصولات در حال ساخته شدن است." },
  applying: { label: "در حال اعمال", tone: "blue", hint: "قیمت‌ها کم‌کم و با توجه به توان سرور تغییر می‌کنند." },
  verifying: { label: "در حال بررسی", tone: "blue", hint: "همهٔ قیمت‌ها دوباره از دیتابیس خوانده و کنترل می‌شوند." },
  active: { label: "فعال", tone: "green", hint: "تخفیف روی سایت فعال است." },
  reverting: { label: "در حال برگرداندن", tone: "amber", hint: "قیمت‌ها به حالت قبل از تخفیف برمی‌گردند." },
  verifying_revert: { label: "بررسی نهایی", tone: "amber", hint: "کنترل می‌شود که همهٔ قیمت‌ها برگشته باشند." },
  ended: { label: "تمام شده", tone: "gray", hint: "تخفیف تمام شده و قیمت‌ها برگشته‌اند." },
  paused: { label: "متوقف موقت", tone: "amber", hint: "کار متوقف شده؛ با «ادامه» از همان‌جا ادامه می‌دهد." },
};

export const ITEM_STATUS: Record<string, string> = {
  pending: "در صف",
  applied: "اعمال شد",
  skipped: "رد شد",
  failed: "ناموفق",
  reverted: "برگشت",
  kept: "دست‌نخورده ماند",
  cancelled: "لغو",
};

export const NOTES: Record<string, string> = {
  not_found: "محصول پیدا نشد",
  unsupported_type: "نوع محصول پشتیبانی نمی‌شود",
  out_of_stock: "ناموجود است",
  other_campaign: "در کمپین دیگری است",
  no_price: "قیمت ندارد",
  price_invalid: "تخفیف بیشتر از قیمت است",
  has_own_sale: "تخفیف خودش را دارد",
  own_sale_is_better: "تخفیف فعلی‌اش بیشتر است",
  manual_change: "قیمت دستی عوض شده بود",
  lock_changed: "توسط کمپین دیگری تغییر کرد",
  verify_mismatch: "بررسی ناموفق؛ دوباره تلاش شد",
  revert_mismatch: "برگرداندن دوباره تلاش شد",
  crashed: "هنگام ذخیرهٔ این محصول، افزونهٔ دیگری خطای مرگبار داد",
  interrupted: "کار روی این محصول نیمه‌کاره ماند؛ قیمت اصلی بررسی و برگردانده شد",
  failed_apply: "اعمال ناموفق بود؛ قیمت اصلی بررسی و برگردانده شد",
};

export function noteLabel(note: string): string {
  if (!note) return "";
  if (note.startsWith("error:")) return "خطا: " + note.slice(6).trim();
  return NOTES[note] ?? note;
}

export const LOG: Record<string, string> = {
  created: "کمپین ساخته شد",
  edited: "تنظیمات ویرایش شد",
  scheduled: "زمان‌بندی شد",
  started: "شروع شد",
  prepared: "فهرست محصولات آماده شد",
  verify_retry: "چند محصول دوباره تلاش می‌شود",
  activated: "تخفیف روی همهٔ محصولات فعال شد",
  paused: "متوقف موقت شد",
  resumed: "ادامه داده شد",
  ending: "پایان تخفیف آغاز شد",
  reverted: "قیمت‌ها برگشتند",
  ended: "تمام شد",
  rescheduled: "برای دورهٔ بعد زمان‌بندی شد",
  unscheduled: "زمان‌بندی لغو شد",
  retry: "محصولات ناموفق دوباره امتحان می‌شوند",
  missed_window: "زمان تخفیف از دست رفت (سایت در دسترس نبود)",
};

export function discountText(c: Pick<Campaign, "discount_type" | "discount_value">): string {
  return c.discount_type === "percent" ? `${fa(c.discount_value)}٪` : money(c.discount_value);
}

export function targetText(c: Pick<Campaign, "target_type" | "target">, count?: number): string {
  const n = count !== undefined ? ` (${num(count)} قلم)` : "";
  if (c.target_type === "all_in_stock") return `همهٔ محصولات موجود${n}`;
  if (c.target_type === "categories") return `${num(c.target.category_ids.length)} دسته‌بندی${n}`;
  return `${num(c.target.product_ids.length)} محصول انتخابی${n}`;
}

export const REPEAT: Record<string, string> = { none: "بدون تکرار", daily: "هر روز", weekly: "هر هفته" };

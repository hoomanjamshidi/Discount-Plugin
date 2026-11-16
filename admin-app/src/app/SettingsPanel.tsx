import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import type { Settings } from "@/lib/types";
import { SoftSwitch } from "@/components/animated-switches";
import { SaveButton } from "@/components/save-button";
import { Card, ChoiceCard, Hint, SectionTitle, Skeleton } from "./ui";
import { useToast } from "./Toaster";
import { LeafIcon, AiMagicIcon, RocketIcon } from "@hugeicons/core-free-icons";

export function SettingsPanel() {
  const toast = useToast();
  const [s, setS] = useState<Settings | null>(null);
  useEffect(() => {
    api<Settings>("settings").then(setS);
  }, []);
  if (!s) return <Skeleton className="h-80" />;

  const set = <K extends keyof Settings>(k: K, v: Settings[K]) => setS({ ...s, [k]: v });
  const save = () =>
    api<Settings>("settings", { method: "PUT", body: s })
      .then((r) => (setS(r), toast("success", "تنظیمات ذخیره شد")))
      .catch((e: Error) => (toast("error", "ذخیره نشد", e.message), Promise.reject(e)));

  return (
    <div className="flex flex-col gap-5">
      <Card>
        <SectionTitle title="سرعت کار" hint="اگر مطمئن نیستید، «خودکار» را نگه دارید." />
        <div className="grid gap-3 md:grid-cols-3">
          <ChoiceCard selected={s.profile === "gentle"} onSelect={() => set("profile", "gentle")} icon={LeafIcon} title="ملایم" description="برای هاست‌های اشتراکی ارزان. افزونه حداکثر ۳۰٪ وقت سرور را می‌گیرد؛ کندتر ولی بی‌دردسر." />
          <ChoiceCard selected={s.profile === "auto"} onSelect={() => set("profile", "auto")} icon={AiMagicIcon} title="خودکار (پیشنهادی)" description="حداکثر نیمی از وقت سرور؛ اگر سایت کند شود، خودش آهسته‌تر می‌شود." />
          <ChoiceCard selected={s.profile === "fast"} onSelect={() => set("profile", "fast")} icon={RocketIcon} title="سریع" description="برای سرور اختصاصی یا مجازی قوی با بازدید کم." />
        </div>
      </Card>

      <Card>
        <SectionTitle title="رفتار پس‌زمینه" />
        <div className="flex flex-col divide-y divide-border">
          <Row label="وقتی سرور شلوغ است، آهسته‌تر کار کن" help="اگر بار پردازنده بالاتر از حالت معمول همین سرور باشد، کار با سرعت کمتر ادامه پیدا می‌کند تا سایت کند نشود. کار هیچ‌وقت کاملاً متوقف نمی‌شود." checked={s.pause_on_high_load} onChange={(v) => set("pause_on_high_load", v)} />
          <Row label="باز بودن همین صفحه به سرعت کار کمک کند" help="اگر کران هاست کند باشد، تا وقتی این صفحه باز است کار با درخواست‌های کوتاه جلو می‌رود." checked={s.admin_runner} onChange={(v) => set("admin_runner", v)} />
          <Row label="ادامهٔ خودکار با درخواست داخلی" help="بعد از هر دسته، دستهٔ بعدی با یک درخواست داخلی کوتاه شروع می‌شود (مثل پردازش پس‌زمینهٔ ووکامرس). اگر هاست شما درخواست به خود سایت را مسدود می‌کند، خاموشش کنید." checked={s.loopback} onChange={(v) => set("loopback", v)} />
          <Row label="سازگاری با افزونه‌های کش (WP Rocket، لایت‌اسپید و …)" help="هنگام اعمال و برگرداندن قیمت‌ها، کش سایت به‌ازای هر محصول پاک نمی‌شود؛ فقط یک بار وقتی تخفیف کامل اعمال شد، یک بار لحظهٔ پایان تخفیف و یک بار وقتی قیمت‌ها برگشتند. این کار جلوی فشار بعد از کمپین را می‌گیرد." checked={s.cache_compat ?? true} onChange={(v) => set("cache_compat", v)} />
          <Row label="هنگام حذف افزونه، داده‌هایش هم پاک شود" help="فقط وقتی هیچ کمپین فعالی نباشد پاک می‌شود، تا قیمت‌های اصلی هیچ‌وقت از دست نروند." checked={s.delete_data_on_uninstall} onChange={(v) => set("delete_data_on_uninstall", v)} />
        </div>
      </Card>

      <div className="flex justify-end">
        <SaveButton label="ذخیرهٔ تنظیمات" onClick={save} />
      </div>
    </div>
  );
}

function Row({ label, help, checked, onChange }: { label: string; help: string; checked: boolean; onChange: (v: boolean) => void }) {
  return (
    <div className="flex items-center justify-between gap-4 py-3.5">
      <span className="flex items-center gap-1.5 text-[14px]">
        {label} <Hint text={help} />
      </span>
      <SoftSwitch checked={checked} onCheckedChange={onChange} aria-label={label} />
    </div>
  );
}

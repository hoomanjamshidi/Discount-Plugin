import { useCallback, useEffect, useState } from "react";
import { AnimatePresence, motion } from "motion/react";
import { HugeiconsIcon } from "@hugeicons/react";
import { DiscountTag01Icon, Add01Icon, ServerStack01Icon, Settings02Icon, DashboardSquare01Icon } from "@hugeicons/core-free-icons";
import { usePoll } from "@/lib/poll";
import { api, boot } from "@/lib/api";
import type { Campaign, Category, Worker } from "@/lib/types";
import { WORKING } from "@/lib/types";
import DiscreteTabs from "@/components/discrete-tabs";
import { CampaignList } from "./CampaignList";
import { CampaignDetail } from "./CampaignDetail";
import { Wizard, type WizardPreset } from "./Wizard";
import { SystemPanel } from "./SystemPanel";
import { SettingsPanel } from "./SettingsPanel";
import { useToast } from "./Toaster";

type View =
  | { name: "list" }
  | { name: "detail"; id: number }
  | { name: "wizard"; editing?: Campaign | null; preset?: WizardPreset }
  | { name: "system" }
  | { name: "settings" };

type Tab = "list" | "wizard" | "system" | "settings";

function viewFromHash(): View {
  const h = window.location.hash.replace(/^#\/?/, "");
  const m = /^campaign\/(\d+)$/.exec(h);
  if (m) return { name: "detail", id: Number(m[1]) };
  if (h === "new") return { name: "wizard" };
  if (h === "system") return { name: "system" };
  if (h === "settings") return { name: "settings" };
  return { name: "list" };
}

function hashFor(v: View): string {
  switch (v.name) {
    case "detail":
      return `#/campaign/${v.id}`;
    case "wizard":
      return v.editing ? `#/campaign/${v.editing.id}` : "#/new";
    case "system":
      return "#/system";
    case "settings":
      return "#/settings";
    default:
      return "#/";
  }
}

export function App() {
  const toast = useToast();
  const [view, setViewState] = useState<View>(viewFromHash);
  const [campaigns, setCampaigns] = useState<Campaign[] | null>(null);
  const [worker, setWorker] = useState<Worker | null>(null);
  const [categories, setCategories] = useState<Category[]>([]);
  const [loading, setLoading] = useState(true);

  const setView = (v: View) => {
    setViewState(v);
    if (window.location.hash !== hashFor(v)) window.history.pushState(null, "", hashFor(v));
    window.scrollTo({ top: 0 });
  };

  useEffect(() => {
    const onPop = () => setViewState(viewFromHash());
    window.addEventListener("popstate", onPop);
    return () => window.removeEventListener("popstate", onPop);
  }, []);

  const loadList = useCallback(
    () =>
      api<{ campaigns: Campaign[]; worker: Worker }>("campaigns").then((r) => {
        setCampaigns(r.campaigns);
        setWorker(r.worker);
      }),
    [],
  );
  const reload = useCallback(() => {
    return loadList()
      .catch((e: Error) => toast("error", "بارگذاری نشد", e.message))
      .finally(() => setLoading(false));
  }, [loadList, toast]);

  useEffect(() => {
    reload();
    api<Category[]>("categories").then(setCategories).catch(() => {});
  }, [reload]);

  const busy = (campaigns ?? []).some((c) => WORKING.includes(c.status) || c.status === "scheduled");
  const working = (campaigns ?? []).some((c) => WORKING.includes(c.status));

  // Keep the list fresh while something is happening (only on the list tab; the detail
  // page polls its own campaign). Errors back off instead of hammering a struggling host.
  usePoll(loadList, view.name === "list" ? (busy ? 6000 : 30000) : 20000);

  // The open page helps the background queue when the background runners lag
  // (the server decides whether a run is needed and keeps its own pace).
  usePoll(() => api("tick", { method: "POST" }), 15000, working || (worker?.has_work ?? false));

  const tab: Tab = view.name === "detail" ? "list" : view.name;
  const liveCount = (campaigns ?? []).filter((c) => !["draft", "ended"].includes(c.status)).length;

  return (
    <div className="mx-auto max-w-[1180px] px-1 pt-4 pb-16">
      <header className="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div className="flex items-center gap-3">
          <span className="flex size-11 items-center justify-center rounded-2xl bg-primary text-primary-foreground shadow-[0_8px_20px_-10px_var(--primary)]">
            <HugeiconsIcon icon={DiscountTag01Icon} size={24} />
          </span>
          <div>
            <h1 className="m-0 text-[20px] font-black leading-tight">تخفیف گروهی</h1>
            <p className="m-0 text-[12px] text-muted-foreground">تخفیف روی هزاران محصول، بدون فشار به سرور</p>
          </div>
        </div>
        <DiscreteTabs<Tab>
          value={tab}
          onChange={(t) => setView(t === "wizard" ? { name: "wizard" } : ({ name: t } as View))}
          tabs={[
            { id: "list", title: "کمپین‌ها", icon: DashboardSquare01Icon, badge: liveCount || undefined },
            { id: "wizard", title: "تخفیف جدید", icon: Add01Icon },
            { id: "system", title: "سلامت سرور", icon: ServerStack01Icon },
            { id: "settings", title: "تنظیمات", icon: Settings02Icon },
          ]}
        />
      </header>

      <AnimatePresence mode="popLayout" initial={false}>
        <motion.main
          key={view.name + ("id" in view ? view.id : "") + (view.name === "wizard" ? (view.editing?.id ?? "new") + JSON.stringify(view.preset ?? {}) : "")}
          initial={{ opacity: 0, y: 8 }}
          animate={{ opacity: 1, y: 0 }}
          exit={{ opacity: 0, y: -6 }}
          transition={{ duration: 0.18 }}
        >
          {view.name === "list" ? (
            <CampaignList campaigns={campaigns} loading={loading} onOpen={(c) => setView({ name: "detail", id: c.id })} onNew={(preset) => setView({ name: "wizard", preset })} />
          ) : null}
          {view.name === "detail" ? (
            <CampaignDetail id={view.id} onBack={() => setView({ name: "list" })} onEdit={(c) => setView({ name: "wizard", editing: c })} onChanged={reload} />
          ) : null}
          {view.name === "wizard" ? (
            <Wizard
              editing={view.editing}
              preset={view.preset}
              categories={categories}
              onCancel={() => setView(view.editing ? { name: "detail", id: view.editing.id } : { name: "list" })}
              onDone={(c) => (reload(), setView({ name: "detail", id: c.id }))}
            />
          ) : null}
          {view.name === "system" ? <SystemPanel /> : null}
          {view.name === "settings" ? <SettingsPanel /> : null}
        </motion.main>
      </AnimatePresence>

      <footer className="mt-10 text-center text-[11px] text-muted-foreground">نسخهٔ {boot.version}</footer>
    </div>
  );
}

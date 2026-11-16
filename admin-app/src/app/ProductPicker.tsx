import { useEffect, useMemo, useState } from "react";
import { AnimatePresence, motion } from "motion/react";
import { HugeiconsIcon } from "@hugeicons/react";
import { Search01Icon, ShoppingBasket01Icon, Delete02Icon, PackageIcon, Cancel01Icon } from "@hugeicons/core-free-icons";
import { api } from "@/lib/api";
import type { Category, Product } from "@/lib/types";
import type { Basket } from "@/lib/basket";
import { fa, money, num, toLatin } from "@/lib/format";
import { SoftCheckbox } from "@/components/animated-checkboxes";
import { Badge, Btn, Skeleton } from "./ui";
import { cn } from "@/lib/utils";

/**
 * Search (name / SKU / ID) + category filter on the left, the selection "basket" on the right.
 * The basket is owned by the parent, so searching, paging or changing the filter never
 * loses what was already chosen.
 */
export function ProductPicker({ basket, categories, onlyInStock }: { basket: Basket; categories: Category[]; onlyInStock: boolean }) {
  const [q, setQ] = useState("");
  const [cat, setCat] = useState(0);
  const [stock, setStock] = useState<"" | "instock">(onlyInStock ? "instock" : "");
  const [page, setPage] = useState(1);
  const [data, setData] = useState<{ products: Product[]; total: number; pages: number } | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  const query = useMemo(() => toLatin(q.trim()), [q]);

  useEffect(() => setPage(1), [query, cat, stock]);

  useEffect(() => {
    let alive = true;
    setLoading(true);
    const t = setTimeout(() => {
      api<{ products: Product[]; total: number; pages: number }>("products", { query: { q: query, category: cat || undefined, stock, page, per_page: 20 } })
        .then((r) => alive && (setData(r), setError("")))
        .catch((e: Error) => alive && setError(e.message))
        .finally(() => alive && setLoading(false));
    }, 250);
    return () => {
      alive = false;
      clearTimeout(t);
    };
  }, [query, cat, stock, page]);

  // Fill in names/prices for basket entries restored from an existing campaign.
  useEffect(() => {
    const missing = basket.items.filter((i) => i.name.startsWith("#")).map((i) => i.id);
    if (missing.length === 0) return;
    api<{ products: Product[] }>("products", { query: { include: missing.slice(0, 100), per_page: 100 } }).then((r) => basket.hydrate(r.products));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const pageIds = data?.products.map((p) => p.id) ?? [];
  const allOnPage = pageIds.length > 0 && pageIds.every((id) => basket.ids.has(id));

  return (
    <div className="grid gap-4 lg:grid-cols-[1fr_320px]">
      <div className="min-w-0 rounded-2xl border border-border bg-card">
        <div className="flex flex-wrap items-center gap-2 border-b border-border p-3">
          <div className="relative min-w-[220px] flex-1">
            <span className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-muted-foreground">
              <HugeiconsIcon icon={Search01Icon} size={18} />
            </span>
            <input
              value={q}
              onChange={(e) => setQ(e.target.value)}
              placeholder="جستجو با نام، کد محصول (SKU) یا شناسه…"
              className="h-11 w-full rounded-xl border border-input bg-background pe-9 ps-10 text-[15px] outline-none focus:ring-4 focus:ring-primary/10"
            />
            {q ? (
              <button type="button" aria-label="پاک کردن" onClick={() => setQ("")} className="absolute inset-y-0 left-2 flex items-center px-1 text-muted-foreground hover:text-foreground">
                <HugeiconsIcon icon={Cancel01Icon} size={16} />
              </button>
            ) : null}
          </div>
          <select
            value={cat}
            onChange={(e) => setCat(Number(e.target.value))}
            aria-label="دسته‌بندی"
            className="h-11 max-w-[220px] rounded-xl border border-input bg-background px-3 text-sm outline-none"
          >
            <option value={0}>همهٔ دسته‌بندی‌ها</option>
            {categories.map((c) => (
              <option key={c.id} value={c.id}>
                {"— ".repeat(c.depth) + c.name} ({fa(c.count)})
              </option>
            ))}
          </select>
          <label className="flex cursor-pointer items-center gap-2 text-[13px] text-muted-foreground">
            <SoftCheckbox checked={stock === "instock"} onCheckedChange={(v) => setStock(v ? "instock" : "")} aria-label="فقط موجودها" />
            فقط موجودها
          </label>
        </div>

        <div className="flex items-center justify-between border-b border-border px-4 py-2 text-[13px] text-muted-foreground">
          <span>{data ? `${num(data.total)} محصول پیدا شد` : "…"}</span>
          {data && data.products.length > 0 ? (
            <button
              type="button"
              className="font-medium text-primary hover:underline"
              onClick={() => (allOnPage ? basket.removeMany(pageIds) : basket.addMany(data.products))}
            >
              {allOnPage ? "برداشتن همهٔ این صفحه" : "انتخاب همهٔ این صفحه"}
            </button>
          ) : null}
        </div>

        <div className="dsd-scroll max-h-[460px] min-h-[300px] overflow-y-auto">
          {error ? <p className="p-6 text-center text-sm text-rose-600">{error}</p> : null}
          {loading && !data ? (
            <div className="space-y-2 p-3">
              {Array.from({ length: 6 }, (_, i) => (
                <Skeleton key={i} className="h-14" />
              ))}
            </div>
          ) : null}
          {data && data.products.length === 0 && !loading ? (
            <p className="p-10 text-center text-sm text-muted-foreground">محصولی با این مشخصات پیدا نشد.</p>
          ) : null}
          <ul className={cn("m-0 list-none p-0 transition-opacity", loading && data && "opacity-60")}>
            {data?.products.map((p) => {
              const on = basket.ids.has(p.id);
              return (
                <li key={p.id} className="m-0">
                  <div
                    role="button"
                    tabIndex={0}
                    onClick={() => basket.toggle(p)}
                    onKeyDown={(e) => (e.key === " " || e.key === "Enter") && (e.preventDefault(), basket.toggle(p))}
                    className={cn("flex cursor-pointer items-center gap-3 border-b border-border/60 px-4 py-2.5 transition-colors", on ? "bg-primary/[0.045]" : "hover:bg-accent/50")}
                  >
                    <span onClick={(e) => e.stopPropagation()}>
                      <SoftCheckbox checked={on} onCheckedChange={() => basket.toggle(p)} aria-label={p.name} />
                    </span>
                    <Thumb src={p.image} />
                    <div className="min-w-0 flex-1">
                      <p className="m-0 truncate text-[14px] font-medium">{p.name}</p>
                      <p className="m-0 flex flex-wrap items-center gap-x-3 text-xs text-muted-foreground">
                        <span>شناسه {fa(p.id)}</span>
                        {p.sku ? <span dir="ltr">{p.sku}</span> : null}
                        {p.variations ? <span>{fa(p.variations)} تنوع</span> : null}
                      </p>
                    </div>
                    <div className="hidden flex-col items-end gap-1 sm:flex">
                      <span className="text-[13px] tabular-nums">
                        {p.regular === null ? "—" : p.regular_max && p.regular_max !== p.regular ? `${money(p.regular)} تا ${num(p.regular_max)}` : money(p.regular)}
                      </span>
                      <span className="flex gap-1">
                        {p.stock !== "instock" ? <Badge tone="red">{p.stock === "outofstock" ? "ناموجود" : "پیش‌خرید"}</Badge> : null}
                        {p.sale ? <Badge tone="amber">تخفیف دارد</Badge> : null}
                        {p.locked_by ? <Badge tone="violet">در کمپین {fa(p.locked_by)}</Badge> : null}
                      </span>
                    </div>
                  </div>
                </li>
              );
            })}
          </ul>
        </div>

        {data && data.pages > 1 ? (
          <div className="flex items-center justify-between p-3 text-[13px]">
            <Btn onClick={() => setPage((p) => Math.max(1, p - 1))} disabled={page <= 1}>
              قبلی
            </Btn>
            <span className="text-muted-foreground">
              صفحهٔ {fa(page)} از {fa(data.pages)}
            </span>
            <Btn onClick={() => setPage((p) => Math.min(data.pages, p + 1))} disabled={page >= data.pages}>
              بعدی
            </Btn>
          </div>
        ) : null}
      </div>

      <BasketPanel basket={basket} />
    </div>
  );
}

function Thumb({ src }: { src: string | null }) {
  return src ? (
    <img src={src} alt="" className="size-10 shrink-0 rounded-lg border border-border object-cover" loading="lazy" />
  ) : (
    <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
      <HugeiconsIcon icon={PackageIcon} size={18} />
    </span>
  );
}

function BasketPanel({ basket }: { basket: Basket }) {
  return (
    <aside className="flex max-h-[600px] flex-col rounded-2xl border border-border bg-card lg:sticky lg:top-10">
      <div className="flex items-center justify-between border-b border-border p-4">
        <span className="flex items-center gap-2 font-bold">
          <HugeiconsIcon icon={ShoppingBasket01Icon} size={20} className="text-primary" />
          سبد انتخاب
          <motion.span key={basket.items.length} initial={{ scale: 0.6 }} animate={{ scale: 1 }} className="rounded-full bg-primary px-2 text-xs leading-5 text-primary-foreground">
            {fa(basket.items.length)}
          </motion.span>
        </span>
        {basket.items.length ? (
          <button type="button" className="text-xs text-muted-foreground hover:text-rose-600" onClick={basket.clear}>
            خالی کردن
          </button>
        ) : null}
      </div>
      <div className="dsd-scroll flex-1 overflow-y-auto p-2">
        {basket.items.length === 0 ? (
          <p className="m-0 p-6 text-center text-[13px] leading-6 text-muted-foreground">
            محصولات را از فهرست تیک بزنید.
            <br />
            انتخاب‌ها با جستجوی بعدی پاک نمی‌شوند.
          </p>
        ) : null}
        <AnimatePresence initial={false}>
          {basket.items.map((p) => (
            <motion.div
              key={p.id}
              layout
              initial={{ opacity: 0, x: 20 }}
              animate={{ opacity: 1, x: 0 }}
              exit={{ opacity: 0, x: -20, height: 0 }}
              className="group flex items-center gap-2 rounded-xl px-2 py-1.5 hover:bg-accent/60"
            >
              <Thumb src={p.image} />
              <div className="min-w-0 flex-1">
                <p className="m-0 truncate text-[13px] font-medium">{p.name}</p>
                <p className="m-0 text-[11px] text-muted-foreground">
                  {p.regular !== null ? money(p.regular) : ""}
                  {p.variations ? ` · ${fa(p.variations)} تنوع` : ""}
                </p>
              </div>
              <button type="button" aria-label="حذف از سبد" onClick={() => basket.toggle(p)} className="rounded-lg p-1.5 text-muted-foreground opacity-60 hover:bg-rose-50 hover:text-rose-600 group-hover:opacity-100">
                <HugeiconsIcon icon={Delete02Icon} size={16} />
              </button>
            </motion.div>
          ))}
        </AnimatePresence>
      </div>
    </aside>
  );
}

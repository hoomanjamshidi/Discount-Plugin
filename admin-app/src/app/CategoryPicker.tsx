import { useMemo, useState } from "react";
import { HugeiconsIcon } from "@hugeicons/react";
import { Search01Icon } from "@hugeicons/core-free-icons";
import type { Category } from "@/lib/types";
import { fa, num } from "@/lib/format";
import { SoftCheckbox } from "@/components/animated-checkboxes";
import { cn } from "@/lib/utils";

/** Tree of product categories with checkboxes and a quick filter. */
export function CategoryPicker({
  categories,
  selected,
  onChange,
  includeChildren,
  tone = "primary",
}: {
  categories: Category[];
  selected: number[];
  onChange: (ids: number[]) => void;
  includeChildren: boolean;
  tone?: "primary" | "danger";
}) {
  const [q, setQ] = useState("");
  const sel = useMemo(() => new Set(selected), [selected]);

  // A category is "covered" when one of its ancestors is selected and children are included.
  const covered = useMemo(() => {
    const byId = new Map(categories.map((c) => [c.id, c]));
    const out = new Set<number>();
    if (!includeChildren) return out;
    for (const c of categories) {
      let p = byId.get(c.parent);
      while (p) {
        if (sel.has(p.id)) {
          out.add(c.id);
          break;
        }
        p = byId.get(p.parent);
      }
    }
    return out;
  }, [categories, sel, includeChildren]);

  const visible = q.trim() ? categories.filter((c) => c.name.includes(q.trim())) : categories;
  const toggle = (id: number) => onChange(sel.has(id) ? selected.filter((x) => x !== id) : [...selected, id]);

  return (
    <div className="rounded-2xl border border-border bg-card">
      <div className="relative border-b border-border p-3">
        <span className="pointer-events-none absolute inset-y-0 right-6 flex items-center text-muted-foreground">
          <HugeiconsIcon icon={Search01Icon} size={18} />
        </span>
        <input
          value={q}
          onChange={(e) => setQ(e.target.value)}
          placeholder="فیلتر دسته‌بندی‌ها…"
          className="h-10 w-full rounded-xl border border-input bg-background ps-10 text-sm outline-none focus:ring-4 focus:ring-primary/10"
        />
      </div>
      <ul className="dsd-scroll m-0 max-h-[360px] list-none overflow-y-auto p-2">
        {visible.length === 0 ? <li className="p-6 text-center text-sm text-muted-foreground">دسته‌بندی پیدا نشد.</li> : null}
        {visible.map((c) => {
          const on = sel.has(c.id);
          const inherited = !on && covered.has(c.id);
          return (
            <li key={c.id} className="m-0">
              <div
                role="button"
                tabIndex={0}
                onClick={() => toggle(c.id)}
                onKeyDown={(e) => (e.key === " " || e.key === "Enter") && (e.preventDefault(), toggle(c.id))}
                className={cn(
                  "flex cursor-pointer items-center gap-3 rounded-xl py-2 pe-3 transition-colors hover:bg-accent/60",
                  on && (tone === "danger" ? "bg-rose-50" : "bg-primary/[0.05]"),
                )}
                style={{ paddingInlineStart: 12 + (q ? 0 : c.depth) * 22 }}
              >
                <span onClick={(e) => e.stopPropagation()}>
                  <SoftCheckbox checked={on || inherited} onCheckedChange={() => toggle(c.id)} aria-label={c.name} />
                </span>
                <span className={cn("flex-1 text-[14px]", inherited && "text-muted-foreground")}>{c.name}</span>
                {inherited ? <span className="text-[11px] text-muted-foreground">زیرمجموعه</span> : null}
                <span className="rounded-full bg-muted px-2 text-[11px] leading-5 text-muted-foreground">{fa(c.count)}</span>
              </div>
            </li>
          );
        })}
      </ul>
      <div className="border-t border-border px-4 py-2 text-xs text-muted-foreground">{num(selected.length)} دسته انتخاب شده</div>
    </div>
  );
}

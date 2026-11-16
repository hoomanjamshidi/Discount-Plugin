import DateField, { setDateFieldToday } from "@/components/date-field";
import { fa, fromCalendarDate, parseLocal, siteNow, toCalendarDate, toLocalString } from "@/lib/format";
import { cn } from "@/lib/utils";

// The calendar highlights the shop's "today", not the browser's.
setDateFieldToday(() => toCalendarDate(siteNow()));

const MINUTES = ["00", "05", "10", "15", "20", "25", "30", "35", "40", "45", "50", "55", "59"];

/**
 * Jalali date (Tarhak date-field) + hour/minute. The value is a site-local wall-clock
 * string "YYYY-MM-DD HH:mm" (Gregorian) — the server converts it with the site timezone,
 * so the browser's own timezone never matters.
 */
export function DateTimeField({
  value,
  onChange,
  placeholder,
  defaultTime = "08:00",
}: {
  value: string | null;
  onChange: (v: string | null) => void;
  placeholder?: string;
  defaultTime?: string;
}) {
  const d = parseLocal(value);
  const [hh, mm] = (d ? `${String(d.getUTCHours()).padStart(2, "0")}:${String(d.getUTCMinutes()).padStart(2, "0")}` : defaultTime).split(":");
  // A minute that is not in the list (e.g. 08:07 from an older version) is still shown.
  const minutes = MINUTES.includes(mm) ? MINUTES : [...MINUTES, mm].sort();

  const setDate = (day: Date | null) => {
    if (!day) return onChange(null);
    onChange(toLocalString(fromCalendarDate(day, Number(hh), Number(mm))));
  };
  const setTime = (h: string, m: string) => {
    const base = d ?? siteNow();
    onChange(toLocalString(new Date(Date.UTC(base.getUTCFullYear(), base.getUTCMonth(), base.getUTCDate(), Number(h), Number(m)))));
  };

  const sel = "h-12 rounded-xl border border-input bg-card px-2 text-[15px] outline-none focus:ring-4 focus:ring-primary/10 tabular-nums";

  return (
    <div className="flex flex-wrap items-start gap-3">
      <DateField value={d ? toCalendarDate(d) : null} onChange={setDate} placeholder={placeholder} className="max-w-xs flex-1" />
      <div className={cn("flex h-16 items-center gap-1.5", !d && "opacity-50")} dir="ltr">
        <select aria-label="ساعت" className={sel} value={hh} onChange={(e) => setTime(e.target.value, mm)} disabled={!d}>
          {Array.from({ length: 24 }, (_, i) => String(i).padStart(2, "0")).map((h) => (
            <option key={h} value={h}>
              {fa(h)}
            </option>
          ))}
        </select>
        <span className="text-lg text-muted-foreground">:</span>
        <select aria-label="دقیقه" className={sel} value={mm} onChange={(e) => setTime(hh, e.target.value)} disabled={!d}>
          {minutes.map((m) => (
            <option key={m} value={m}>
              {fa(m)}
            </option>
          ))}
        </select>
      </div>
    </div>
  );
}

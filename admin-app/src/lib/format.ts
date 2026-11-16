import { format as jFormat } from "date-fns-jalali";
import { faIR } from "date-fns-jalali/locale/fa-IR";
import { boot } from "./api";

const FA = "۰۱۲۳۴۵۶۷۸۹";

export function fa(value: string | number): string {
  return String(value).replace(/\d/g, (d) => FA[Number(d)] ?? d);
}

/** Persian / Arabic digits → Latin (for parsing what users type), thousands separators dropped. */
export function toLatin(value: string): string {
  return latinDigits(value).replace(/[٬,]/g, "");
}

/** Persian / Arabic digits → Latin, nothing else changed. */
export function latinDigits(value: string): string {
  return value
    .replace(/[۰-۹]/g, (d) => String("۰۱۲۳۴۵۶۷۸۹".indexOf(d)))
    .replace(/[٠-٩]/g, (d) => String("٠١٢٣٤٥٦٧٨٩".indexOf(d)));
}

export function num(n: number, decimals = 0): string {
  return new Intl.NumberFormat("fa-IR", { maximumFractionDigits: decimals }).format(n);
}

export function money(n: number | null | undefined): string {
  if (n === null || n === undefined || Number.isNaN(n)) return "—";
  return `${num(n, boot.decimals)} ${boot.currency}`;
}

/*
 * Dates
 * -----
 * The server speaks "site wall-clock" strings: "2026-11-20 08:30" (Gregorian, Latin digits,
 * in the shop's timezone). In the browser such a value is held as a *floating* Date whose
 * UTC fields equal the wall-clock fields. Nothing here ever reads the browser's timezone,
 * so a visitor abroad, a laptop with a wrong clock or an outdated Windows timezone table
 * (old Iran daylight-saving rules) can no longer shift or garble a date.
 */

/** "2026-11-20 08:30" (site-local, Gregorian) → floating Date. Accepts Persian digits. */
export function parseLocal(s: string | null | undefined): Date | null {
  if (!s) return null;
  const m = /^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T](\d{1,2}):(\d{2})(?::\d{2})?)?$/.exec(latinDigits(String(s)).trim());
  if (!m) return null;
  const [y, mo, d, h, mi] = [+m[1], +m[2], +m[3], +(m[4] ?? 0), +(m[5] ?? 0)];
  if (y < 1900 || y > 2200 || mo < 1 || mo > 12 || d < 1 || d > 31 || h > 23 || mi > 59) return null;
  const date = new Date(Date.UTC(y, mo - 1, d, h, mi));
  // Reject impossible days (31 Feb) instead of silently rolling over.
  if (date.getUTCDate() !== d || date.getUTCMonth() !== mo - 1) return null;
  return date;
}

/** Floating Date → "2026-11-20 08:30". */
export function toLocalString(d: Date): string {
  const p = (n: number) => String(n).padStart(2, "0");
  return `${d.getUTCFullYear()}-${p(d.getUTCMonth() + 1)}-${p(d.getUTCDate())} ${p(d.getUTCHours())}:${p(d.getUTCMinutes())}`;
}

/** Build a floating Date from wall-clock fields (month 1-12). */
export function wallDate(y: number, month: number, d: number, h = 0, mi = 0): Date {
  return new Date(Date.UTC(y, month - 1, d, h, mi));
}

/**
 * Floating Date → a browser-local Date at noon of the same calendar day. Only for calendar
 * widgets and Jalali day/month names: noon is never inside a daylight-saving jump.
 */
export function toCalendarDate(d: Date): Date {
  return new Date(d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate(), 12, 0, 0);
}

/** A calendar widget's (browser-local) day → floating Date at hh:mm of that day. */
export function fromCalendarDate(day: Date, h: number, mi: number): Date {
  return wallDate(day.getFullYear(), day.getMonth() + 1, day.getDate(), h, mi);
}

function hhmm(d: Date): string {
  return `${String(d.getUTCHours()).padStart(2, "0")}:${String(d.getUTCMinutes()).padStart(2, "0")}`;
}

/** "۲۰ آبان ۱۴۰۵، ساعت ۰۸:۳۰" */
export function jalali(d: Date | null, withTime = true): string {
  if (!d || Number.isNaN(d.getTime())) return "—";
  const date = jFormat(toCalendarDate(d), "d MMMM yyyy", { locale: faIR });
  return fa(withTime ? `${date}، ساعت ${hhmm(d)}` : date);
}

/** "جمعه ۲۰ آبان، ۰۸:۳۰" */
export function jalaliShort(d: Date | null): string {
  if (!d || Number.isNaN(d.getTime())) return "—";
  return fa(`${jFormat(toCalendarDate(d), "EEEE d MMMM", { locale: faIR })}، ${hhmm(d)}`);
}

/** Seconds → "۳ دقیقه" style. */
export function duration(seconds: number): string {
  const s = Math.max(0, Math.round(seconds));
  if (s < 60) return `${fa(s)} ثانیه`;
  const m = Math.round(s / 60);
  if (m < 60) return `${fa(m)} دقیقه`;
  const h = Math.floor(m / 60);
  const rm = m % 60;
  if (h < 48) return rm ? `${fa(h)} ساعت و ${fa(rm)} دقیقه` : `${fa(h)} ساعت`;
  return `${fa(Math.round(h / 24))} روز`;
}

/** Server time now (unix seconds), corrected for a wrong browser clock. */
const serverOffsetMs = boot.now * 1000 - Date.now();
export function serverNow(): number {
  return (Date.now() + serverOffsetMs) / 1000;
}

export function ago(ts: number, now = serverNow()): string {
  const d = now - ts;
  if (d < 10) return "همین الان";
  return `${duration(d)} پیش`;
}

/** Site "now" as a floating Date (the shop's wall clock), ticking with the server. */
const nowLocalAtBoot = parseLocal(boot.nowLocal);
export function siteNow(): Date {
  if (!nowLocalAtBoot) {
    // No server value (dev build): use the browser's own wall clock.
    const n = new Date();
    return wallDate(n.getFullYear(), n.getMonth() + 1, n.getDate(), n.getHours(), n.getMinutes());
  }
  return new Date(nowLocalAtBoot.getTime() + (serverNow() - boot.now) * 1000);
}

/**
 * Campaign report: every product of a campaign with its link, the price before the campaign
 * and the campaign price — as an Excel file or a printable page (saved as PDF by the browser,
 * which shapes Persian text correctly; PDF libraries do not).
 */
import { api, boot } from "./api";
import { ITEM_STATUS, STATUS, discountText, noteLabel, targetText } from "./labels";
import { fa, jalali, num, parseLocal, siteNow } from "./format";
import { buildXlsx, type CellValue, type Column } from "./xlsx";
import type { Campaign } from "./types";

/** One row as the server sends it (raw database values). */
export interface ReportRow {
  product_id: number;
  parent_id: number;
  name: string;
  sku: string;
  url: string;
  status: string;
  note: string;
  has_backup: number;
  orig_regular: string | null;
  orig_sale: string | null;
  new_sale: string | null;
  regular_now: string | null;
  sale_now: string | null;
}

/** One row ready for the report. Prices are numbers or null (unknown / not applicable). */
export interface ReportLine {
  productId: number;
  parentId: number;
  name: string;
  sku: string;
  url: string;
  /** Regular price before the campaign. */
  regular: number | null;
  /** What a customer paid before the campaign (the shop's own sale price if it had one). */
  before: number | null;
  /** Price the campaign set (null = this product did not get the discount). */
  campaign: number | null;
  /** regular − campaign (the campaign's discount is computed from the regular price). */
  off: number | null;
  /** off / regular, 0..1. */
  offRatio: number | null;
  status: string;
  statusLabel: string;
  noteLabel: string;
}

export function price(v: string | number | null | undefined): number | null {
  if (v === null || v === undefined) return null;
  const s = String(v).trim();
  if (s === "") return null;
  const n = Number(s);
  return Number.isFinite(n) ? n : null;
}

/**
 * Turn a server row into a report line.
 *  - Rows the campaign wrote (has_backup) use the backup taken BEFORE the first write, so the
 *    "before" price stays right after the campaign ended and the prices came back.
 *  - Rows never written (skipped, cancelled, not reached yet) were never changed by us: their
 *    current price is the price before the campaign.
 */
export function toLine(r: ReportRow): ReportLine {
  const backed = Number(r.has_backup) === 1 && r.orig_regular !== null;
  const regular = price(backed ? r.orig_regular : r.regular_now);
  const sale = price(backed ? r.orig_sale : r.sale_now);
  const before = regular !== null && sale !== null && sale > 0 && sale < regular ? sale : regular;
  const campaign = backed ? price(r.new_sale) : null;
  const off = regular !== null && campaign !== null && campaign < regular ? regular - campaign : null;
  return {
    productId: r.product_id,
    parentId: r.parent_id,
    name: r.name,
    sku: r.sku,
    url: r.url,
    regular,
    before,
    campaign,
    off,
    offRatio: off !== null && regular ? off / regular : null,
    status: r.status,
    statusLabel: ITEM_STATUS[r.status] ?? r.status,
    noteLabel: noteLabel(r.note),
  };
}

/** Fetch every page of the report (one bounded request at a time). */
export async function fetchReport(id: number, onProgress?: (done: number, total: number) => void): Promise<ReportLine[]> {
  const lines: ReportLine[] = [];
  let after = 0;
  let total = 0;
  for (let guard = 0; guard < 10000; guard++) {
    const page = await api<{ rows: ReportRow[]; next: number | null; total?: number }>(`campaigns/${id}/report`, { query: { after, per_page: 500 } });
    if (page.total !== undefined) total = page.total;
    for (const r of page.rows) lines.push(toLine(r));
    onProgress?.(lines.length, Math.max(total, lines.length));
    if (page.next === null || page.rows.length === 0) break;
    after = page.next;
  }
  return lines;
}

export interface ReportSummary {
  total: number;
  discounted: number;
  skipped: number;
  failed: number;
}

export function summarize(lines: ReportLine[]): ReportSummary {
  return {
    total: lines.length,
    discounted: lines.filter((l) => l.campaign !== null).length,
    skipped: lines.filter((l) => l.status === "skipped" || l.status === "cancelled").length,
    failed: lines.filter((l) => l.status === "failed").length,
  };
}

function campaignFacts(c: Campaign, lines: ReportLine[]): [string, string][] {
  const s = summarize(lines);
  const start = parseLocal(c.starts_local);
  const end = parseLocal(c.ends_local);
  return [
    ["نام کمپین", c.name],
    ["تخفیف", `${discountText(c)} از قیمت عادی`],
    ["محصولات", targetText(c)],
    ["وضعیت", STATUS[c.status]?.label ?? c.status],
    ["شروع", start ? jalali(start) : "—"],
    ["پایان", end ? jalali(end) : "تا توقف دستی"],
    ["تعداد ردیف‌ها", num(s.total)],
    ["با تخفیف کمپین", num(s.discounted)],
    ["بدون تخفیف (رد شده)", num(s.skipped)],
    ["ناموفق", num(s.failed)],
    ["تاریخ تهیهٔ گزارش", jalali(siteNow())],
  ];
}

export const REPORT_COLUMNS: Column[] = [
  { header: "ردیف", type: "int", width: 7 },
  { header: "شناسهٔ محصول", type: "int", width: 12 },
  { header: "کد محصول (SKU)", type: "text", width: 16 },
  { header: "نام محصول", type: "text", width: 44 },
  { header: "لینک محصول", type: "link", width: 40 },
  { header: "قیمت عادی", type: "money", width: 15 },
  { header: "قیمت قبلی", type: "money", width: 15 },
  { header: "قیمت جدید (کمپین)", type: "money", width: 17 },
  { header: "مبلغ تخفیف", type: "money", width: 14 },
  { header: "درصد تخفیف", type: "percent", width: 11 },
  { header: "وضعیت", type: "text", width: 14 },
  { header: "توضیح", type: "text", width: 34 },
];

/** Persian slugs arrive percent-encoded ("%d9%85…"): show them as letters, link the real URL. */
export function readableUrl(u: string): string {
  try {
    return decodeURI(u);
  } catch {
    return u;
  }
}

export function workbookRows(lines: ReportLine[]): CellValue[][] {
  return lines.map((l, i) => [
    i + 1,
    l.productId,
    l.sku,
    l.name,
    l.url ? { text: readableUrl(l.url), url: l.url } : "",
    l.regular,
    l.before,
    l.campaign,
    l.off,
    l.offRatio,
    l.statusLabel,
    l.noteLabel,
  ]);
}

export function buildWorkbook(c: Campaign, lines: ReportLine[]): Uint8Array<ArrayBuffer> {
  return buildXlsx(
    [
      { name: "محصولات", columns: REPORT_COLUMNS, rows: workbookRows(lines), rtl: true },
      {
        name: "خلاصه",
        columns: [
          { header: "", type: "text", width: 24 },
          { header: "", type: "text", width: 48 },
        ],
        rows: campaignFacts(c, lines),
        rtl: true,
        table: false,
      },
    ],
    { decimals: boot.decimals },
  );
}

export function fileName(c: Campaign, ext: string): string {
  const d = siteNow();
  const p = (n: number) => String(n).padStart(2, "0");
  return `discount-campaign-${c.id}-report-${d.getUTCFullYear()}-${p(d.getUTCMonth() + 1)}-${p(d.getUTCDate())}.${ext}`;
}

export function downloadBytes(bytes: Uint8Array<ArrayBuffer>, name: string, type: string): void {
  const blob = new Blob([bytes], { type });
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = name;
  a.rel = "noopener";
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 30000);
}

// --------------------------------------------------------------------------- printable (PDF)

export function esc(s: string): string {
  return s.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#39;");
}

function moneyCell(n: number | null): string {
  return n === null ? "—" : esc(num(n, boot.decimals));
}

/** @font-face rules of our own stylesheet (Vazirmatn is bundled inline), for the print frame. */
function fontFaces(): string {
  const out: string[] = [];
  for (const sheet of Array.from(document.styleSheets)) {
    let rules: CSSRuleList | null = null;
    try {
      rules = sheet.cssRules;
    } catch {
      continue; // Cross-origin stylesheet of another plugin.
    }
    for (const rule of Array.from(rules ?? [])) {
      if (rule.constructor.name === "CSSFontFaceRule" || rule.cssText.startsWith("@font-face")) {
        if (rule.cssText.includes("Vazirmatn")) out.push(rule.cssText);
      }
    }
  }
  return out.join("\n");
}

/** Complete, self-contained HTML document of the report (A4 landscape, RTL). */
export function reportHtml(c: Campaign, lines: ReportLine[], faces = ""): string {
  const facts = campaignFacts(c, lines)
    .map(([k, v]) => `<div class="fact"><span>${esc(k)}</span><b>${esc(v)}</b></div>`)
    .join("");
  const rows = lines
    .map(
      (l, i) =>
        `<tr${l.campaign === null ? ' class="muted"' : ""}>` +
        `<td class="n">${esc(fa(i + 1))}</td>` +
        `<td class="n">${esc(fa(l.productId))}</td>` +
        `<td class="name">${esc(l.name)}${l.sku ? `<div class="sku">${esc(l.sku)}</div>` : ""}</td>` +
        `<td class="url">${l.url ? `<a dir="ltr" href="${esc(l.url)}">${esc(readableUrl(l.url))}</a>` : "—"}</td>` +
        `<td class="n">${moneyCell(l.regular)}</td>` +
        `<td class="n">${moneyCell(l.before)}</td>` +
        `<td class="n strong">${moneyCell(l.campaign)}</td>` +
        `<td class="n">${l.offRatio === null ? "—" : esc(fa((l.offRatio * 100).toFixed(1).replace(/\.0$/, ""))) + "٪"}</td>` +
        `<td>${esc(l.statusLabel)}${l.noteLabel ? `<div class="note">${esc(l.noteLabel)}</div>` : ""}</td>` +
        `</tr>`,
    )
    .join("");
  return `<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>${esc(`گزارش کمپین ${c.name}`)}</title><style>
${faces}
@page { size: A4 landscape; margin: 10mm; }
* { box-sizing: border-box; }
body { margin: 0; font-family: "Vazirmatn Variable", Vazirmatn, Tahoma, Arial, sans-serif; color: #111827; font-size: 9.5pt; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
h1 { font-size: 15pt; margin: 0 0 2mm; }
.sub { color: #4b5563; margin: 0 0 4mm; }
.facts { display: grid; grid-template-columns: repeat(4, 1fr); gap: 2mm; margin-bottom: 5mm; }
.fact { background: #f3f4f6; border-radius: 2mm; padding: 2mm 3mm; display: flex; flex-direction: column; gap: 0.5mm; }
.fact span { color: #6b7280; font-size: 8pt; }
table { width: 100%; border-collapse: collapse; }
thead { display: table-header-group; }
th { background: #e8f0fe; font-weight: 700; text-align: start; padding: 1.6mm 1.5mm; border-bottom: 0.4mm solid #9aa5b1; font-size: 8.5pt; }
td { padding: 1.3mm 1.5mm; border-bottom: 0.2mm solid #e5e7eb; vertical-align: top; }
tr { break-inside: avoid; page-break-inside: avoid; }
td.n { white-space: nowrap; font-variant-numeric: tabular-nums; }
td.strong { font-weight: 700; }
td.name { max-width: 70mm; }
td.url { max-width: 62mm; word-break: break-all; font-size: 7.5pt; }
td.url a { color: #1d4ed8; text-decoration: none; display: inline-block; direction: ltr; unicode-bidi: isolate; text-align: left; }
.sku, .note { color: #6b7280; font-size: 7.5pt; }
tr.muted td { color: #6b7280; }
</style></head><body>
<h1>${esc(`گزارش کمپین «${c.name}»`)}</h1>
<p class="sub">${esc(`مبالغ به ${boot.currency}. «قیمت قبلی» همان قیمتی است که مشتری پیش از کمپین می‌پرداخت (اگر محصول فروش ویژهٔ خودش را داشت، همان).`)}</p>
<div class="facts">${facts}</div>
<table><thead><tr><th>ردیف</th><th>شناسه</th><th>نام محصول</th><th>لینک</th><th>قیمت عادی</th><th>قیمت قبلی</th><th>قیمت جدید</th><th>تخفیف</th><th>وضعیت</th></tr></thead>
<tbody>${rows || `<tr><td colspan="9">محصولی در این کمپین نیست.</td></tr>`}</tbody></table>
</body></html>`;
}

/** Open the browser's print dialog for the report ("Save as PDF" there). */
export function printReport(c: Campaign, lines: ReportLine[]): Promise<void> {
  return new Promise((resolve) => {
    const frame = document.createElement("iframe");
    frame.setAttribute("aria-hidden", "true");
    frame.style.cssText = "position:fixed;inset-inline-end:0;bottom:0;width:0;height:0;border:0;opacity:0;";
    document.body.appendChild(frame);
    const doc = frame.contentDocument;
    const win = frame.contentWindow;
    if (!doc || !win) {
      frame.remove();
      resolve();
      return;
    }
    doc.open();
    doc.write(reportHtml(c, lines, fontFaces()));
    doc.close();
    const go = () => {
      win.focus();
      win.print();
      // The dialog blocks until closed in most browsers; keep the frame a little longer for the rest.
      setTimeout(() => frame.remove(), 60000);
      resolve();
    };
    const fonts = (doc as Document & { fonts?: FontFaceSet }).fonts;
    if (fonts?.ready) fonts.ready.then(() => setTimeout(go, 50), () => go());
    else setTimeout(go, 300);
  });
}

import { describe, expect, it } from "vitest";
import { fileName, price, readableUrl, reportHtml, summarize, toLine, workbookRows, type ReportRow } from "./report";
import type { Campaign } from "./types";

const row = (over: Partial<ReportRow>): ReportRow => ({
  product_id: 10,
  parent_id: 0,
  name: "کتاب",
  sku: "B-10",
  url: "https://shop.test/product/book/",
  status: "applied",
  note: "",
  has_backup: 1,
  orig_regular: "1000000",
  orig_sale: "",
  new_sale: "850000",
  regular_now: "1000000",
  sale_now: "850000",
  ...over,
});

const campaign = {
  id: 7,
  name: "حراج <پاییز>",
  status: "ended",
  paused_status: "",
  discount_type: "percent",
  discount_value: 15,
  target_type: "products",
  target: { product_ids: [10], category_ids: [], exclude_product_ids: [], exclude_category_ids: [] },
  options: { only_in_stock: true, sale_policy: "best", round_step: 0, min_price: 0, include_children: true },
  starts_at: null,
  ends_at: null,
  starts_local: "2026-11-20 08:00",
  ends_local: "2026-11-25 23:59",
  repeat_mode: "none",
  stats: { total: 3, pending: 0, applied: 0, skipped: 1, failed: 0, reverted: 2, kept: 0, cancelled: 0 },
  log: [],
  run_count: 0,
  created_at: 0,
  updated_at: 0,
} as unknown as Campaign;

describe("price", () => {
  it("reads database strings", () => {
    expect(price("850000")).toBe(850000);
    expect(price("12.50")).toBe(12.5);
    expect(price("")).toBeNull();
    expect(price(null)).toBeNull();
    expect(price("abc")).toBeNull();
  });
});

describe("toLine", () => {
  it("1,000,000 → 850,000: before, campaign price and the 15% discount", () => {
    const l = toLine(row({}));
    expect([l.regular, l.before, l.campaign, l.off]).toEqual([1000000, 1000000, 850000, 150000]);
    expect(l.offRatio).toBeCloseTo(0.15);
    expect(l.statusLabel).toBe("اعمال شد");
  });

  it("after the campaign ended, still reports the price before it (from the backup)", () => {
    const l = toLine(row({ status: "reverted", regular_now: "1000000", sale_now: "" }));
    expect([l.regular, l.before, l.campaign]).toEqual([1000000, 1000000, 850000]);
  });

  it("shows the shop's own earlier sale price as the price before", () => {
    const l = toLine(row({ orig_sale: "900000", new_sale: "850000" }));
    expect([l.regular, l.before, l.campaign, l.off]).toEqual([1000000, 900000, 850000, 150000]);
  });

  it("ignores a stored sale price that was not a real discount", () => {
    expect(toLine(row({ orig_sale: "1200000" })).before).toBe(1000000);
  });

  it("products the campaign never touched: current price, no campaign price", () => {
    const l = toLine(row({ status: "skipped", note: "own_sale_is_better", has_backup: 0, orig_regular: null, orig_sale: null, new_sale: null, regular_now: "500000", sale_now: "400000" }));
    expect([l.regular, l.before, l.campaign, l.off, l.offRatio]).toEqual([500000, 400000, null, null, null]);
    expect(l.noteLabel).toBe("تخفیف فعلی‌اش بیشتر است");
  });

  it("trusts the backup even if something else rewrote the regular price later", () => {
    const l = toLine(row({ status: "reverted", regular_now: "850000", sale_now: "" }));
    expect(l.regular).toBe(1000000);
  });
});

describe("workbook rows and summary", () => {
  const lines = [row({}), row({ product_id: 11, url: "" }), row({ product_id: 12, status: "skipped", has_backup: 0, orig_regular: null, new_sale: null })].map(toLine);

  it("numbers rows, links products and keeps prices numeric", () => {
    const rows = workbookRows(lines);
    expect(rows[0].slice(0, 4)).toEqual([1, 10, "B-10", "کتاب"]);
    expect(rows[0][4]).toEqual({ text: "https://shop.test/product/book/", url: "https://shop.test/product/book/" });
    expect(rows[0].slice(5, 9)).toEqual([1000000, 1000000, 850000, 150000]);
    expect(rows[1][4]).toBe("");
    expect(rows[2][7]).toBeNull();
  });

  it("shows Persian product links as letters but links the real (encoded) URL", () => {
    const enc = "https://shop.test/product/%D9%85%D8%AD%D8%B5%D9%88%D9%84-1/";
    expect(readableUrl(enc)).toBe("https://shop.test/product/محصول-1/");
    expect(readableUrl("https://shop.test/%E0%A4%A")).toBe("https://shop.test/%E0%A4%A");
    const rows = workbookRows([toLine(row({ url: enc }))]);
    expect(rows[0][4]).toEqual({ text: "https://shop.test/product/محصول-1/", url: enc });
  });

  it("counts discounted and skipped products", () => {
    expect(summarize(lines)).toEqual({ total: 3, discounted: 2, skipped: 1, failed: 0 });
  });
});

describe("printable report", () => {
  it("escapes names and URLs and is a right-to-left document", () => {
    const html = reportHtml(campaign, [toLine(row({ name: `<img src=x onerror="alert(1)">`, url: `https://shop.test/?a="b"&c=<d>` }))]);
    expect(html).toContain('dir="rtl"');
    expect(html).not.toContain("<img src=x");
    expect(html).toContain("&lt;img src=x onerror=&quot;alert(1)&quot;&gt;");
    expect(html).toContain('href="https://shop.test/?a=&quot;b&quot;&amp;c=&lt;d&gt;"');
    expect(html).toContain("حراج &lt;پاییز&gt;");
    expect(html).toContain("۸۵۰٬۰۰۰");
  });

  it("names the file after the campaign", () => {
    expect(fileName(campaign, "xlsx")).toMatch(/^discount-campaign-7-report-\d{4}-\d{2}-\d{2}\.xlsx$/);
  });
});

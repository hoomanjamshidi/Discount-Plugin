import { afterEach, describe, expect, it } from "vitest";
import { createElement } from "react";
import { createRoot, type Root } from "react-dom/client";
import { act } from "react";
import { format } from "date-fns-jalali";
import { faIR } from "date-fns-jalali/locale/fa-IR";
import DateField, { setDateFieldToday } from "./date-field";

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

let root: Root | null = null;
let host: HTMLElement | null = null;

function mount(value: Date | null) {
  host = document.createElement("div");
  document.body.appendChild(host);
  root = createRoot(host);
  act(() => root!.render(createElement(DateField, { value, onChange: () => {} })));
  const button = host.querySelector('[aria-label="باز کردن تقویم"]') as HTMLElement;
  act(() => button.click());
  const month = host.querySelector('[aria-label="انتخاب ماه"]')?.textContent ?? "";
  const year = host.querySelector('[aria-label="انتخاب سال"]')?.textContent ?? "";
  return { month, year };
}

const fa = (s: string) => s.replace(/\d/g, (d) => "۰۱۲۳۴۵۶۷۸۹"[Number(d)]);

afterEach(() => {
  act(() => root?.unmount());
  host?.remove();
  root = null;
  host = null;
});

describe("date picker opens on today", () => {
  const today = new Date(2026, 10, 16, 12); // 25 Aban 1405
  setDateFieldToday(() => today);
  const thisMonth = format(today, "MMMM", { locale: faIR });

  it("with no date chosen", () => {
    const { month, year } = mount(null);
    expect(month).toContain(thisMonth);
    expect(year).toContain(fa("1405"));
  });

  it("when the chosen date is in the past", () => {
    const { month, year } = mount(new Date(2024, 0, 10, 12));
    expect(month).toContain(thisMonth);
    expect(year).toContain(fa("1405"));
  });

  it("shows a chosen future date's month", () => {
    const { month } = mount(new Date(2027, 1, 10, 12)); // Bahman 1405
    expect(month).toContain(format(new Date(2027, 1, 10, 12), "MMMM", { locale: faIR }));
  });

  it("marks today in the grid", () => {
    mount(null);
    const todayLabel = fa(format(today, "EEEE d MMMM yyyy", { locale: faIR }));
    expect(host!.querySelector(`[aria-label="${todayLabel}"]`)).not.toBeNull();
  });
});

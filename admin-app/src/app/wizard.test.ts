import { describe, expect, it } from "vitest";
import { validateStep } from "./Wizard";
import type { CampaignInput } from "@/lib/types";

const base = (): CampaignInput => ({
  name: "",
  discount_type: "percent",
  discount_value: 20,
  target_type: "all_in_stock",
  target: { product_ids: [], category_ids: [], exclude_product_ids: [], exclude_category_ids: [] },
  options: { only_in_stock: true, sale_policy: "best", round_step: 0, min_price: 0, include_children: true },
  starts_at: null,
  ends_at: null,
  repeat_mode: "none",
});

describe("wizard validation (plain-language guard rails)", () => {
  it("blocks impossible discounts", () => {
    expect(validateStep(0, { ...base(), discount_value: 0 }, "now_open")).not.toBe("");
    expect(validateStep(0, { ...base(), discount_value: 100 }, "now_open")).not.toBe("");
    expect(validateStep(0, base(), "now_open")).toBe("");
  });

  it("needs a selection for products/categories", () => {
    expect(validateStep(1, { ...base(), target_type: "products" }, "now_open")).toContain("محصول");
    expect(validateStep(1, { ...base(), target_type: "categories" }, "now_open")).toContain("دسته");
    const ok = { ...base(), target_type: "products" as const, target: { ...base().target, product_ids: [5] } };
    expect(validateStep(1, ok, "now_open")).toBe("");
  });

  it("checks the schedule", () => {
    expect(validateStep(2, base(), "now_until")).toContain("پایان");
    expect(validateStep(2, { ...base(), ends_at: "2000-01-01 10:00" }, "now_until")).toContain("گذشته");
    expect(validateStep(2, { ...base(), starts_at: "2099-01-02 10:00", ends_at: "2099-01-01 10:00" }, "window")).toContain("بعد از شروع");
    expect(validateStep(2, { ...base(), starts_at: "2099-01-01 10:00", ends_at: "2099-01-01 12:00" }, "window")).toBe("");
  });

  it("rejects a daily repeat longer than a day", () => {
    const p = { ...base(), starts_at: "2099-01-01 10:00", ends_at: "2099-01-03 10:00", repeat_mode: "daily" as const };
    expect(validateStep(2, p, "repeat")).toContain("تکرار");
    expect(validateStep(2, { ...p, repeat_mode: "weekly" }, "repeat")).toBe("");
  });
});

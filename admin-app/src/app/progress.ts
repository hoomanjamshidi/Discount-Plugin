import type { Campaign } from "@/lib/types";
import type { StatusMarkStatus } from "@/components/status-mark";
import { num } from "@/lib/format";

/** One number (0..1) + a human sentence for the current phase. */
export function campaignProgress(c: Campaign): { ratio: number; text: string } {
  const s = c.stats;
  const total = Math.max(1, s.total);
  const phase = c.status === "paused" ? c.paused_status : c.status;
  switch (phase) {
    case "preparing":
      return { ratio: 0.02, text: `در حال پیدا کردن محصولات… (${num(s.total)} تا الان)` };
    case "applying": {
      const done = s.applied + s.skipped + s.failed;
      return { ratio: done / total, text: `${num(done)} از ${num(s.total)} محصول بررسی شد` };
    }
    case "verifying":
      return { ratio: 0.99, text: "در حال کنترل نهایی قیمت‌ها…" };
    case "reverting":
    case "verifying_revert": {
      const todo = s.applied + s.reverted + s.kept;
      const done = s.reverted + s.kept;
      return { ratio: todo ? done / todo : 1, text: `${num(done)} از ${num(todo)} قیمت برگشت` };
    }
    default:
      return { ratio: c.status === "active" || c.status === "ended" ? 1 : 0, text: "" };
  }
}

export function markStatus(c: Campaign): StatusMarkStatus {
  if (["preparing", "applying", "verifying", "reverting", "verifying_revert"].includes(c.status)) return "running";
  if (c.status === "active") return c.stats.failed > 0 ? "failed" : "done";
  if (c.status === "ended") return c.stats.failed > 0 ? "failed" : "done";
  return "pending";
}

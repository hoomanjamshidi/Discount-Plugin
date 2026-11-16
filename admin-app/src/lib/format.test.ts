import { describe, expect, it } from "vitest";
import { fa, toLatin, parseLocal, toLocalString, jalali, jalaliShort, duration, toCalendarDate, fromCalendarDate } from "./format";

describe("digits", () => {
  it("converts to Persian digits", () => {
    expect(fa(1405)).toBe("۱۴۰۵");
    expect(fa("08:30")).toBe("۰۸:۳۰");
  });
  it("parses what users type (Persian, Arabic, separators)", () => {
    expect(toLatin("۲۰")).toBe("20");
    expect(toLatin("٥٠٬٠٠٠")).toBe("50000");
    expect(toLatin("1,250,000")).toBe("1250000");
  });
});

describe("site-local wall-clock strings", () => {
  it("round-trips without timezone drift", () => {
    const s = "2026-11-20 08:30";
    expect(toLocalString(parseLocal(s)!)).toBe(s);
  });
  it("rejects garbage and impossible days instead of rolling over", () => {
    expect(parseLocal("not a date")).toBeNull();
    expect(parseLocal(null)).toBeNull();
    expect(parseLocal("2026-02-30 10:00")).toBeNull();
    expect(parseLocal("2026-11-20 25:00")).toBeNull();
    // A Jalali string (what a Persian date plugin used to produce) is not taken as Gregorian.
    expect(parseLocal("۱۴۰۵-۰۸-۲۵ ۱۰:۰۰")).toBeNull();
  });
  it("accepts Persian digits and seconds", () => {
    expect(toLocalString(parseLocal("۲۰۲۶-۱۱-۲۰ ۰۸:۳۰")!)).toBe("2026-11-20 08:30");
    expect(toLocalString(parseLocal("2026-11-20 08:30:59")!)).toBe("2026-11-20 08:30");
  });
  it("does not depend on the browser timezone (floating wall clock)", () => {
    const d = parseLocal("2026-03-22 00:30")!; // inside the old Iran DST gap
    expect(d.getUTCHours()).toBe(0);
    expect(d.getUTCMinutes()).toBe(30);
    expect(jalali(d)).toBe("۲ فروردین ۱۴۰۵، ساعت ۰۰:۳۰");
    // Calendar round trip keeps the same day and time.
    expect(toLocalString(fromCalendarDate(toCalendarDate(d), 0, 30))).toBe("2026-03-22 00:30");
  });
  it("adds hours on the wall clock", () => {
    const d = parseLocal("2026-11-20 22:00")!;
    expect(toLocalString(new Date(d.getTime() + 4 * 3600 * 1000))).toBe("2026-11-21 02:00");
  });
});

describe("Jalali", () => {
  it("formats known Gregorian dates as the right Shamsi date", () => {
    // 2026-11-16 = 25 Aban 1405; 2026-03-21 = 1 Farvardin 1405 (Nowruz).
    expect(jalali(parseLocal("2026-11-16 10:00"))).toBe("۲۵ آبان ۱۴۰۵، ساعت ۱۰:۰۰");
    expect(jalali(parseLocal("2026-03-21 00:00"), false)).toBe("۱ فروردین ۱۴۰۵");
    // Leap year edge: 30 Esfand 1403 = 2025-03-20.
    expect(jalali(parseLocal("2025-03-20 12:00"), false)).toBe("۳۰ اسفند ۱۴۰۳");
    expect(jalaliShort(parseLocal("2026-11-20 08:30"))).toBe("جمعه ۲۹ آبان، ۰۸:۳۰");
  });
});

describe("duration", () => {
  it("speaks plain Persian", () => {
    expect(duration(45)).toBe("۴۵ ثانیه");
    expect(duration(3600)).toBe("۱ ساعت");
    expect(duration(3 * 86400)).toBe("۳ روز");
  });
});

// Node only (vitest): change the process timezone at runtime.
declare const process: { env: Record<string, string | undefined> };

describe("same results in every browser timezone", () => {
  const zones = ["Asia/Tehran", "Europe/Berlin", "America/Los_Angeles", "Pacific/Chatham", "Asia/Kolkata", "UTC"];
  const original = process.env.TZ;
  for (const tz of zones) {
    it(tz, () => {
      process.env.TZ = tz;
      try {
        for (const s of ["2026-03-22 00:30", "2026-03-29 02:30", "2026-11-01 01:30", "2025-03-20 23:59", "2026-09-22 00:00"]) {
          const d = parseLocal(s)!;
          expect(toLocalString(d)).toBe(s);
          const [h, m] = s.slice(11).split(":").map(Number);
          expect(toLocalString(fromCalendarDate(toCalendarDate(d), h, m))).toBe(s);
        }
        expect(jalali(parseLocal("2026-03-29 02:30"))).toBe("۹ فروردین ۱۴۰۵، ساعت ۰۲:۳۰");
        expect(jalali(parseLocal("2025-03-20 23:59"), false)).toBe("۳۰ اسفند ۱۴۰۳");
      } finally {
        process.env.TZ = original;
      }
    });
  }
});

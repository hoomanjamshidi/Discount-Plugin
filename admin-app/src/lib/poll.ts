import { useEffect, useRef } from "react";

/**
 * Polite polling for wp-admin on weak hosts.
 *
 * - Never overlaps: the next request is scheduled only after the previous one finished
 *   (a slow server used to receive a new request every few seconds while the old ones
 *   were still running — piling up PHP workers until the host answered 503).
 * - Pauses while the browser tab is hidden.
 * - Backs off on errors (×2 each time, up to 2 minutes) and recovers on success.
 */
export function usePoll(fn: () => Promise<unknown>, intervalMs: number, enabled = true) {
  const fnRef = useRef(fn);
  fnRef.current = fn;

  useEffect(() => {
    if (!enabled || intervalMs <= 0) return;
    let stopped = false;
    let timer: ReturnType<typeof setTimeout> | undefined;
    let running = false;
    let failures = 0;

    const schedule = () => {
      if (stopped) return;
      const delay = Math.min(120000, intervalMs * Math.pow(2, failures));
      timer = setTimeout(run, delay);
    };

    const run = async () => {
      if (stopped || running) return;
      if (typeof document !== "undefined" && document.hidden) {
        schedule();
        return;
      }
      running = true;
      try {
        await fnRef.current();
        failures = 0;
      } catch {
        failures = Math.min(failures + 1, 5);
      } finally {
        running = false;
        schedule();
      }
    };

    const onVisible = () => {
      if (!document.hidden && !running) {
        if (timer) clearTimeout(timer);
        run();
      }
    };

    schedule();
    document.addEventListener("visibilitychange", onVisible);
    return () => {
      stopped = true;
      if (timer) clearTimeout(timer);
      document.removeEventListener("visibilitychange", onVisible);
    };
  }, [intervalMs, enabled]);
}

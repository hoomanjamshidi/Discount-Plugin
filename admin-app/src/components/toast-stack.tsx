"use client";

import { useEffect, useState } from "react";
import {
  AnimatePresence,
  motion,
  useReducedMotion,
  type Transition,
} from "motion/react";
import { HugeiconsIcon, type IconSvgElement } from "@hugeicons/react";
import {
  Alert02Icon,
  Cancel01Icon,
  CancelCircleIcon,
  CheckmarkCircle02Icon,
} from "@hugeicons/core-free-icons";

export type ToastKind = "success" | "error" | "warning";

export type ToastItem = {
  id: string;
  kind: ToastKind;
  title: string;
  description?: string;
};

const KIND: Record<
  ToastKind,
  {
    label: string;
    icon: IconSvgElement;
    accent: string;
    soft: string;
  }
> = {
  success: {
    label: "موفقیت",
    icon: CheckmarkCircle02Icon,
    accent: "#34C759",
    soft: "color-mix(in oklab, #34C759 14%, transparent)",
  },
  error: {
    label: "خطا",
    icon: CancelCircleIcon,
    accent: "#E11D48",
    soft: "color-mix(in oklab, #E11D48 14%, transparent)",
  },
  warning: {
    label: "هشدار",
    icon: Alert02Icon,
    accent: "#D97706",
    soft: "color-mix(in oklab, #D97706 16%, transparent)",
  },
};

const springIn: Transition = {
  type: "spring",
  stiffness: 420,
  damping: 30,
  mass: 0.75,
};

const springLayout: Transition = {
  type: "spring",
  stiffness: 380,
  damping: 32,
  mass: 0.8,
};

const AUTO_MS = 5200;

function ToastCard({
  item,
  onDismiss,
  reduce,
}: {
  item: ToastItem;
  onDismiss: (id: string) => void;
  reduce: boolean;
}) {
  const meta = KIND[item.kind];
  const [progress, setProgress] = useState(1);

  useEffect(() => {
    if (reduce) {
      const t = window.setTimeout(() => onDismiss(item.id), AUTO_MS);
      return () => window.clearTimeout(t);
    }

    const started = performance.now();
    let frame = 0;
    const tick = (now: number) => {
      const t = Math.min(1, (now - started) / AUTO_MS);
      setProgress(1 - t);
      if (t < 1) frame = requestAnimationFrame(tick);
      else onDismiss(item.id);
    };
    frame = requestAnimationFrame(tick);
    return () => cancelAnimationFrame(frame);
  }, [item.id, onDismiss, reduce]);

  return (
    <motion.div
      layout
      role="status"
      aria-live="polite"
      initial={
        reduce
          ? false
          : { opacity: 0, y: -18, scale: 0.96, filter: "blur(4px)" }
      }
      animate={{ opacity: 1, y: 0, scale: 1, filter: "blur(0px)" }}
      exit={
        reduce
          ? { opacity: 0 }
          : { opacity: 0, y: -12, scale: 0.96, filter: "blur(4px)" }
      }
      transition={springIn}
      className="relative overflow-hidden rounded-2xl border border-border bg-card shadow-sm"
      style={{ borderInlineStartWidth: 3, borderInlineStartColor: meta.accent }}
    >
      <div
        className="flex items-start gap-3 px-3.5 py-3"
        style={{ background: meta.soft }}
      >
        <span
          className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full"
          style={{ background: meta.soft, color: meta.accent }}
        >
          <HugeiconsIcon icon={meta.icon} size={20} />
        </span>
        <div className="min-w-0 flex-1 pt-0.5">
          <p className="text-sm font-medium text-foreground">{item.title}</p>
          {item.description ? (
            <p className="mt-0.5 text-xs leading-relaxed text-muted-foreground">
              {item.description}
            </p>
          ) : null}
        </div>
        <button
          type="button"
          aria-label="بستن اعلان"
          onClick={() => onDismiss(item.id)}
          className="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-xl text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
        >
          <HugeiconsIcon icon={Cancel01Icon} size={16} />
        </button>
      </div>
      {!reduce ? (
        <div className="h-0.5 w-full bg-border/60">
          <div
            className="h-full origin-end transition-none"
            style={{
              width: `${progress * 100}%`,
              background: meta.accent,
            }}
          />
        </div>
      ) : null}
    </motion.div>
  );
}

/**
 * Tarhak «toast-stack», adapted: the demo panel is replaced by a fixed viewport
 * fed by the app's toaster context.
 */
export default function ToastViewport({
  items,
  onDismiss,
}: {
  items: ToastItem[];
  onDismiss: (id: string) => void;
}) {
  const reduce = useReducedMotion() ?? false;
  return (
    <section
      dir="rtl"
      lang="fa"
      aria-label="اعلان‌ها"
      className="pointer-events-none fixed bottom-6 left-6 z-[100000] flex w-[min(380px,calc(100vw-48px))] flex-col tracking-normal"
    >
      <motion.div layout transition={springLayout} className="flex flex-col gap-2">
        <AnimatePresence mode="popLayout" initial={false}>
          {items.map((item) => (
            <div key={item.id} className="pointer-events-auto">
              <ToastCard item={item} onDismiss={onDismiss} reduce={reduce} />
            </div>
          ))}
        </AnimatePresence>
      </motion.div>
    </section>
  );
}

"use client";

import {
  useId,
  useLayoutEffect,
  useRef,
  useState,
  type ReactNode,
} from "react";
import { createPortal } from "react-dom";
import { AnimatePresence, motion, MotionConfig } from "motion/react";
import { HugeiconsIcon } from "@hugeicons/react";
import {
  Copy01Icon,
  Download01Icon,
  FavouriteIcon,
  Share01Icon,
} from "@hugeicons/core-free-icons";
import clsx from "clsx";

type Side = "top" | "bottom";

type TooltipProps = {
  label: string;
  side?: Side;
  children: ReactNode;
  className?: string;
};

const ROW_EASE: [number, number, number, number] = [0.215, 0.61, 0.355, 1];

/**
 * Soft card tooltip — same surface language as filter-interaction.
 *
 * Adapted: the bubble is rendered in a portal (inside #dsd-root, so the scoped CSS
 * still applies) with fixed positioning. Inside a card or an animated panel with
 * overflow:hidden the old absolutely-positioned bubble was clipped and looked empty.
 * It also flips below the trigger near the top of the screen and never leaves the
 * viewport horizontally.
 */
export function SoftTooltip({
  label,
  side = "top",
  children,
  className,
}: TooltipProps) {
  const id = useId();
  const [open, setOpen] = useState(false);
  const anchorRef = useRef<HTMLDivElement>(null);
  const bubbleRef = useRef<HTMLDivElement>(null);
  const [pos, setPos] = useState<{ x: number; y: number; arrow: number; top: boolean } | null>(null);

  useLayoutEffect(() => {
    if (!open) {
      setPos(null);
      return;
    }
    const place = () => {
      const a = anchorRef.current?.getBoundingClientRect();
      if (!a) return;
      const b = bubbleRef.current?.getBoundingClientRect();
      const w = b?.width ?? 240;
      const h = b?.height ?? 48;
      const margin = 8;
      const top = side === "top" ? a.top - h - 12 >= margin : a.bottom + h + 12 > window.innerHeight - margin;
      const cx = a.left + a.width / 2;
      const x = Math.min(Math.max(cx - w / 2, margin), window.innerWidth - w - margin);
      const y = top ? a.top - h - 12 : a.bottom + 12;
      setPos({ x, y, arrow: Math.min(Math.max(cx - x, 14), w - 14), top });
    };
    place();
    const raf = requestAnimationFrame(place);
    window.addEventListener("scroll", place, true);
    window.addEventListener("resize", place);
    return () => {
      cancelAnimationFrame(raf);
      window.removeEventListener("scroll", place, true);
      window.removeEventListener("resize", place);
    };
  }, [open, side, label]);

  const fromTop = pos ? pos.top : side === "top";
  const portalTarget = typeof document !== "undefined" ? document.getElementById("dsd-root") ?? document.body : null;

  const bubble = (
    <AnimatePresence>
      {open && label ? (
        <motion.div
          ref={bubbleRef}
          id={id}
          role="tooltip"
          dir="rtl"
          lang="fa"
          initial={{ opacity: 0, y: fromTop ? 6 : -6, scale: 0.96 }}
          animate={{ opacity: pos ? 1 : 0, y: 0, scale: 1 }}
          exit={{ opacity: 0, y: fromTop ? 4 : -4, scale: 0.97 }}
          transition={{ type: "spring", bounce: 0.2, duration: 0.3, ease: ROW_EASE }}
          className="pointer-events-none fixed z-[100000]"
          style={{ left: pos?.x ?? -9999, top: pos?.y ?? -9999 }}
        >
          <div
            className={clsx(
              "relative w-max max-w-[280px] whitespace-normal border border-border bg-card px-4 py-2.5 text-[13px] leading-6 text-foreground text-start shadow-lg",
              "font-[family-name:var(--font-estedad),Tahoma,Arial,sans-serif] tracking-normal",
            )}
            style={{ borderRadius: 14, borderWidth: 1 }}
          >
            {label}
            <span
              aria-hidden
              className={clsx(
                "absolute size-2.5 -translate-x-1/2 rotate-45 border-border bg-card",
                fromTop ? "top-full -mt-[5px] border-e border-b" : "bottom-full -mb-[5px] border-s border-t",
              )}
              style={{ left: pos?.arrow ?? "50%" }}
            />
          </div>
        </motion.div>
      ) : null}
    </AnimatePresence>
  );

  return (
    <div
      ref={anchorRef}
      className={clsx("relative inline-flex", className)}
      onMouseEnter={() => setOpen(true)}
      onMouseLeave={() => setOpen(false)}
      onFocusCapture={() => setOpen(true)}
      onBlurCapture={(event) => {
        if (!event.currentTarget.contains(event.relatedTarget as Node | null)) {
          setOpen(false);
        }
      }}
    >
      <div aria-describedby={open ? id : undefined}>{children}</div>
      {portalTarget ? createPortal(bubble, portalTarget) : bubble}
    </div>
  );
}

// Change Here
const TOOLS = [
  { id: "share", label: "اشتراک‌گذاری", icon: Share01Icon },
  { id: "copy", label: "کپی لینک", icon: Copy01Icon },
  { id: "save", label: "ذخیره", icon: FavouriteIcon },
  { id: "download", label: "دانلود", icon: Download01Icon },
] as const;

export default function Tooltip() {
  return (
    <section
      dir="rtl"
      lang="fa"
      aria-label="نمایش تولتیپ"
      className="flex items-center justify-center fill-muted-foreground/70 font-[family-name:var(--font-estedad),Tahoma,Arial,sans-serif] tracking-normal"
    >
      <MotionConfig
        transition={{ type: "spring", duration: 0.85, bounce: 0.35 }}
      >
        <div className="flex items-center gap-3">
          {TOOLS.map((tool) => (
            <SoftTooltip key={tool.id} label={tool.label} side="top">
              <button
                type="button"
                aria-label={tool.label}
                className={clsx(
                  "relative flex size-14 cursor-pointer items-center justify-center",
                  "rounded-full border border-border bg-background text-foreground",
                  "outline-none transition-colors hover:bg-accent",
                  "focus-visible:ring-2 focus-visible:ring-ring",
                )}
              >
                <span className="text-muted-foreground">
                  <HugeiconsIcon icon={tool.icon} size={24} />
                </span>
              </button>
            </SoftTooltip>
          ))}
        </div>
      </MotionConfig>
    </section>
  );
}

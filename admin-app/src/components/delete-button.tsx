"use client";
// Tarhak «delete-button», adapted: labels/countdown via props (dialkit dev panel removed)
// and onConfirm fires when the countdown reaches zero. Clicking again during the
// countdown cancels — a forgiving way to do destructive actions.
// Adapted further: the countdown runs against a fixed deadline (parent re-renders, e.g.
// polling, no longer restart it), and once it fires the button shows a "working" state
// until onConfirm's promise settles (it used to sit on «۰» and look frozen).

import { Undo03Icon } from "@hugeicons/core-free-icons";
import { HugeiconsIcon } from "@hugeicons/react";
import { AnimatePresence, motion } from "motion/react";
import { useEffect, useRef, useState } from "react";

const FA_DIGITS = "۰۱۲۳۴۵۶۷۸۹";
const toFaDigits = (v: string | number) => String(v).replace(/\d/g, (d) => FA_DIGITS[Number(d)] ?? d);
const splitLabel = (text: string) => text.split(/(\s+)/).filter((p) => p.length > 0);

const LAYOUT = { duration: 0.4, ease: [0.77, 0, 0.175, 1] as [number, number, number, number] };
const CHAR = { duration: 0.3, ease: [0.785, 0.135, 0.15, 0.86] as [number, number, number, number] };

export interface DeleteButtonProps {
  label?: string;
  cancelLabel?: string;
  countdown?: number;
  color?: string;
  softColor?: string;
  /** Called when the countdown ends. Return a promise to show "working" until it settles. */
  onConfirm: () => void | Promise<unknown>;
  workingLabel?: string;
  disabled?: boolean;
  size?: "sm" | "md";
}

export default function DeleteButton({
  label = "حذف",
  cancelLabel = "لغو",
  countdown = 5,
  color = "#E5484D",
  softColor = "#FFEDF1",
  onConfirm,
  workingLabel = "در حال انجام…",
  disabled,
  size = "md",
}: DeleteButtonProps) {
  const [isDeleting, setIsDeleting] = useState(false);
  const [working, setWorking] = useState(false);
  const [count, setCount] = useState(countdown);
  const [isAnimating, setIsAnimating] = useState(false);
  const layoutId = useRef(`del-${Math.random().toString(36).slice(2)}`).current;
  const confirmRef = useRef(onConfirm);
  confirmRef.current = onConfirm;
  const mounted = useRef(true);
  useEffect(() => {
    mounted.current = true;
    return () => {
      mounted.current = false;
    };
  }, []);

  // Countdown against a fixed deadline; only (re)started when the countdown begins.
  useEffect(() => {
    if (!isDeleting) return;
    const deadline = Date.now() + countdown * 1000;
    let done = false;
    const tick = () => {
      if (done) return;
      const left = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
      setCount(left);
      if (left > 0) return;
      done = true;
      clearInterval(timer);
      setWorking(true);
      let result: void | Promise<unknown>;
      try {
        result = confirmRef.current();
      } catch {
        result = Promise.reject();
      }
      Promise.resolve(result).then(
        () => {
          // Normally the parent removes this button once the action took effect;
          // if it stays on screen, return to the normal state after a while.
          setTimeout(() => {
            if (mounted.current) {
              setWorking(false);
              setIsDeleting(false);
            }
          }, 15000);
        },
        () => {
          if (mounted.current) {
            setWorking(false);
            setIsDeleting(false);
          }
        },
      );
    };
    const timer = setInterval(tick, 200);
    return () => {
      done = true;
      clearInterval(timer);
    };
  }, [isDeleting, countdown]);

  const handleClick = (next: boolean) => {
    if (isAnimating || disabled || working) return;
    setIsAnimating(true);
    setIsDeleting(next);
    if (next) setCount(countdown);
    setTimeout(() => setIsAnimating(false), LAYOUT.duration * 1000);
  };

  const pad = size === "sm" ? "px-4 py-2 text-sm" : "px-5 py-2.5";

  return (
    <div dir="rtl" lang="fa" className="flex items-center justify-center tracking-normal">
      <AnimatePresence mode="popLayout" initial={false}>
        {!isDeleting ? (
          <motion.button
            key="delete"
            type="button"
            layoutId={layoutId}
            onClick={() => handleClick(true)}
            whileTap={{ scale: 0.95 }}
            disabled={disabled}
            style={{ pointerEvents: isAnimating ? "none" : "auto" }}
            initial={{ backgroundColor: softColor, opacity: 1 }}
            animate={{ backgroundColor: color, opacity: disabled ? 0.45 : 1 }}
            exit={{ backgroundColor: softColor, opacity: 0 }}
            className={`flex items-center justify-center overflow-hidden rounded-full text-white ${pad}`}
            transition={{ layout: LAYOUT, backgroundColor: { duration: 0.4 }, opacity: { duration: 0.2 } }}
          >
            <motion.span layoutId={`${layoutId}-t`} className="inline-flex">
              {splitLabel(label).map((part, i) => (
                <motion.span
                  key={`d-${i}-${part}`}
                  initial={{ y: 20, opacity: 0, scale: 0.3 }}
                  animate={{ y: 0, opacity: 1, scale: 1 }}
                  exit={{ y: -20, opacity: 0, scale: 0.3 }}
                  transition={{ ...CHAR, delay: i * 0.04 }}
                  className="inline-block whitespace-pre"
                >
                  {part}
                </motion.span>
              ))}
            </motion.span>
          </motion.button>
        ) : (
          <motion.button
            key="cancel"
            type="button"
            layoutId={layoutId}
            onClick={() => handleClick(false)}
            whileTap={working ? undefined : { scale: 0.95 }}
            disabled={working}
            aria-busy={working}
            style={{ pointerEvents: isAnimating || working ? "none" : "auto" }}
            initial={{ backgroundColor: color, opacity: 0 }}
            animate={{ backgroundColor: softColor, opacity: 1 }}
            exit={{ backgroundColor: color, opacity: 0 }}
            className="flex items-center gap-2 overflow-hidden rounded-full px-2 py-1.5 ring-1 ring-black/5"
            transition={{ layout: LAYOUT, backgroundColor: { duration: 0.4 }, opacity: { duration: 0.2 } }}
          >
            {working ? (
              <>
                <span className="flex shrink-0 items-center justify-center rounded-full p-1.5" style={{ backgroundColor: color }}>
                  <span className="block h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white" />
                </span>
                <span className="inline-flex pe-2 text-sm font-medium" style={{ color }}>
                  {workingLabel}
                </span>
              </>
            ) : (
            <>
            <span className="flex shrink-0 items-center justify-center rounded-full p-1.5" style={{ backgroundColor: color }}>
              <HugeiconsIcon icon={Undo03Icon} className="h-4 w-4 text-white" />
            </span>
            <span className="inline-flex text-sm font-medium" style={{ color }}>
              {cancelLabel}
            </span>
            <span
              className="relative flex h-8 min-w-8 shrink-0 items-center justify-center overflow-hidden rounded-full text-sm font-semibold text-white"
              style={{ backgroundColor: color }}
            >
              <AnimatePresence mode="popLayout">
                <motion.span
                  key={count}
                  initial={{ opacity: 0, y: 10, scale: 0.8 }}
                  animate={{ opacity: 1, y: 0, scale: 1 }}
                  exit={{ opacity: 0, y: -10, scale: 0.8 }}
                  transition={{ duration: 0.2, ease: [0.33, 1, 0.68, 1] }}
                  className="absolute"
                >
                  {toFaDigits(count)}
                </motion.span>
              </AnimatePresence>
            </span>
            </>
            )}
          </motion.button>
        )}
      </AnimatePresence>
    </div>
  );
}

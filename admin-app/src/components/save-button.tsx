"use client";
// Tarhak «save-button», adapted: async onClick drives idle → loading → success,
// labels and size come from props, errors return to idle.

import { cn } from "@/lib/utils";
import { motion, AnimatePresence } from "motion/react";
import { Tick02Icon } from "@hugeicons/core-free-icons";
import { HugeiconsIcon } from "@hugeicons/react";
import { useState } from "react";

export interface SaveButtonProps {
  label: string;
  loadingLabel?: string;
  doneLabel?: string;
  onClick: () => Promise<unknown>;
  disabled?: boolean;
  variant?: "primary" | "soft";
  className?: string;
}

export function SaveButton({
  label,
  loadingLabel = "صبر کنید…",
  doneLabel = "انجام شد",
  onClick,
  disabled,
  variant = "primary",
  className,
}: SaveButtonProps) {
  const [status, setStatus] = useState<"idle" | "loading" | "success">("idle");

  const handleClick = async () => {
    if (status !== "idle" || disabled) return;
    setStatus("loading");
    try {
      await onClick();
      setStatus("success");
      setTimeout(() => setStatus("idle"), 1600);
    } catch {
      setStatus("idle");
    }
  };

  const text = status === "idle" ? label : status === "loading" ? loadingLabel : doneLabel;

  return (
    <div dir="rtl" lang="fa" className={cn("relative inline-flex group tracking-normal", className)}>
      <button
        type="button"
        onClick={handleClick}
        disabled={disabled || status !== "idle"}
        className={cn(
          "relative h-11 min-w-[140px] rounded-full px-7 text-[15px] font-medium transition-all duration-300 outline-none focus-visible:ring-2 focus-visible:ring-ring",
          status === "idle" && !disabled
            ? variant === "primary"
              ? "bg-primary text-primary-foreground shadow-[0_10px_24px_-14px_var(--primary)] hover:brightness-110"
              : "bg-secondary text-secondary-foreground hover:bg-accent"
            : "cursor-not-allowed border border-border bg-secondary text-secondary-foreground",
          disabled && status === "idle" && "opacity-50",
        )}
      >
        <span className="relative flex min-h-[1.25em] items-center justify-center overflow-hidden">
          <AnimatePresence mode="popLayout" initial={false}>
            <motion.span
              key={status}
              layout
              initial={{ opacity: 0, y: 8, filter: "blur(4px)" }}
              animate={{ opacity: 1, y: 0, filter: "blur(0px)" }}
              exit={{ opacity: 0, y: -8, filter: "blur(4px)" }}
              transition={{ type: "spring", stiffness: 500, damping: 30, mass: 1 }}
              className="inline-block whitespace-nowrap"
            >
              {text}
            </motion.span>
          </AnimatePresence>
        </span>
      </button>

      <div className="pointer-events-none absolute -top-1 -end-1 z-10">
        <AnimatePresence mode="wait">
          {status !== "idle" && (
            <motion.div
              initial={{ opacity: 0, scale: 0, x: -8, filter: "blur(4px)" }}
              animate={{ opacity: 1, scale: 1, x: 0, filter: "blur(0px)" }}
              exit={{ opacity: 0, scale: 0, x: -8, filter: "blur(4px)" }}
              transition={{ type: "spring", stiffness: 300, damping: 20 }}
              className={cn(
                "flex size-6 items-center justify-center overflow-visible rounded-full ring-3",
                status === "success" ? "bg-primary text-primary-foreground ring-background" : "bg-secondary text-secondary-foreground ring-background",
              )}
            >
              <AnimatePresence mode="popLayout">
                {status === "loading" && (
                  <motion.div key="loader" exit={{ scale: 0, opacity: 0 }} transition={{ duration: 0.2 }} className="absolute inset-0 flex items-center justify-center">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
                      <path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".5" />
                      <path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z">
                        <animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate" />
                      </path>
                    </svg>
                  </motion.div>
                )}
                {status === "success" && (
                  <motion.div
                    key="check"
                    initial={{ scale: 0, opacity: 0, filter: "blur(4px)" }}
                    animate={{ scale: 1, opacity: 1, filter: "blur(0px)" }}
                    exit={{ scale: 0, opacity: 0 }}
                    transition={{ type: "spring", stiffness: 500, damping: 25 }}
                    className="absolute inset-0 flex items-center justify-center"
                  >
                    <HugeiconsIcon icon={Tick02Icon} className="size-4" />
                  </motion.div>
                )}
              </AnimatePresence>
            </motion.div>
          )}
        </AnimatePresence>
      </div>
    </div>
  );
}

export default SaveButton;

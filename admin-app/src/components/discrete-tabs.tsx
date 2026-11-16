"use client";
// Tarhak «discrete-tabs», adapted: tabs come from props and the active tab is controlled.

import { motion, MotionConfig } from "motion/react";
import { HugeiconsIcon, type IconSvgElement } from "@hugeicons/react";
import clsx from "clsx";

export interface DiscreteTab<T extends string> {
  id: T;
  title: string;
  icon: IconSvgElement;
  badge?: number;
}

const ROW_EASE: [number, number, number, number] = [0.215, 0.61, 0.355, 1];

export default function DiscreteTabs<T extends string>({
  tabs,
  value,
  onChange,
  size = "md",
  className,
}: {
  tabs: DiscreteTab<T>[];
  value: T;
  onChange: (id: T) => void;
  size?: "sm" | "md";
  className?: string;
}) {
  return (
    <nav
      dir="rtl"
      lang="fa"
      aria-label="بخش‌ها"
      className={clsx(
        "flex flex-wrap items-center gap-2 fill-muted-foreground/70 tracking-normal",
        className,
      )}
    >
      <MotionConfig transition={{ type: "spring", duration: 0.85, bounce: 0.35 }}>
        {tabs.map((tab) => (
          <TabButton
            key={tab.id}
            title={tab.title}
            icon={tab.icon}
            badge={tab.badge}
            size={size}
            isActive={value === tab.id}
            onClick={() => onChange(tab.id)}
          />
        ))}
      </MotionConfig>
    </nav>
  );
}

function TabButton({
  title,
  icon,
  badge,
  isActive,
  size,
  onClick,
}: {
  title: string;
  icon: IconSvgElement;
  badge?: number;
  isActive: boolean;
  size: "sm" | "md";
  onClick: () => void;
}) {
  return (
    <motion.button
      type="button"
      layout
      transition={{ layout: { type: "spring", bounce: 0.2, duration: 0.55, ease: ROW_EASE } }}
      onClick={onClick}
      aria-pressed={isActive}
      aria-label={title}
      title={title}
      className={clsx(
        "relative flex cursor-pointer items-center gap-x-2.5 overflow-hidden border border-border outline-none",
        "focus-visible:ring-2 focus-visible:ring-ring",
        size === "md" ? "h-12" : "h-10",
        isActive
          ? "bg-card px-5 text-foreground shadow-sm"
          : "bg-background px-3.5 text-muted-foreground hover:bg-accent hover:text-foreground",
      )}
      style={{ borderRadius: isActive ? 18 : 40, borderWidth: 1 }}
    >
      <motion.span layout className="shrink-0">
        <HugeiconsIcon icon={icon} size={size === "md" ? 22 : 18} />
      </motion.span>
      {isActive ? (
        <motion.span
          initial={{ opacity: 0, x: 8 }}
          animate={{ opacity: 1, x: 0 }}
          transition={{ type: "spring", bounce: 0.1, duration: 0.25, ease: ROW_EASE }}
          className={clsx("whitespace-nowrap font-medium", size === "md" ? "text-base" : "text-sm")}
        >
          {title}
        </motion.span>
      ) : null}
      {badge ? (
        <motion.span
          layout
          className="flex min-w-5 items-center justify-center rounded-full bg-primary px-1.5 text-[11px] leading-5 text-primary-foreground"
        >
          {new Intl.NumberFormat("fa-IR").format(badge)}
        </motion.span>
      ) : null}
    </motion.button>
  );
}

import { type ReactNode, type ButtonHTMLAttributes, type InputHTMLAttributes } from "react";
import { motion } from "motion/react";
import { HugeiconsIcon, type IconSvgElement } from "@hugeicons/react";
import { HelpCircleIcon } from "@hugeicons/core-free-icons";
import { cn } from "@/lib/utils";
import { SoftTooltip } from "@/components/tooltip";

export function Card({ className, children }: { className?: string; children: ReactNode }) {
  return <div className={cn("rounded-2xl border border-border bg-card p-5 shadow-[0_1px_2px_rgba(0,0,0,0.03)]", className)}>{children}</div>;
}

export function SectionTitle({ title, hint, action }: { title: string; hint?: string; action?: ReactNode }) {
  return (
    <div className="mb-4 flex items-start justify-between gap-4">
      <div>
        <h2 className="m-0 text-[17px] font-bold text-foreground">{title}</h2>
        {hint ? <p className="m-0 mt-1 text-[13px] text-muted-foreground">{hint}</p> : null}
      </div>
      {action}
    </div>
  );
}

type Tone = "gray" | "blue" | "green" | "amber" | "red" | "violet";
const TONES: Record<Tone, string> = {
  gray: "bg-muted text-muted-foreground",
  blue: "bg-sky-50 text-sky-700 ring-sky-200",
  green: "bg-emerald-50 text-emerald-700 ring-emerald-200",
  amber: "bg-amber-50 text-amber-700 ring-amber-200",
  red: "bg-rose-50 text-rose-700 ring-rose-200",
  violet: "bg-violet-50 text-violet-700 ring-violet-200",
};

export function Badge({ tone = "gray", children, className }: { tone?: Tone; children: ReactNode; className?: string }) {
  return <span className={cn("inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-transparent ring-inset", TONES[tone], className)}>{children}</span>;
}

export function Btn({
  variant = "secondary",
  icon,
  className,
  children,
  ...rest
}: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: "primary" | "secondary" | "ghost" | "danger"; icon?: IconSvgElement }) {
  return (
    <motion.button
      whileTap={{ scale: 0.97 }}
      type="button"
      {...(rest as object)}
      className={cn(
        "inline-flex h-10 items-center justify-center gap-2 rounded-full px-4 text-sm font-medium outline-none transition-colors focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-45",
        variant === "primary" && "bg-primary text-primary-foreground hover:brightness-110",
        variant === "secondary" && "border border-border bg-card text-foreground hover:bg-accent",
        variant === "ghost" && "text-muted-foreground hover:bg-accent hover:text-foreground",
        variant === "danger" && "border border-rose-200 bg-rose-50 text-rose-700 hover:bg-rose-100",
        className,
      )}
    >
      {icon ? <HugeiconsIcon icon={icon} size={18} /> : null}
      {children}
    </motion.button>
  );
}

export function Hint({ text }: { text: string }) {
  return (
    <SoftTooltip label={text} side="top">
      <span tabIndex={0} role="button" aria-label={text} onClick={(e) => e.preventDefault()} className="inline-flex cursor-help rounded-full p-0.5 text-muted-foreground/70 outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary/30">
        <HugeiconsIcon icon={HelpCircleIcon} size={16} />
      </span>
    </SoftTooltip>
  );
}

export function Field({ label, hint, help, children, className }: { label: string; hint?: string; help?: string; children: ReactNode; className?: string }) {
  return (
    <label className={cn("flex flex-col gap-1.5", className)}>
      <span className="flex items-center gap-1.5 text-[13px] font-medium text-foreground">
        {label}
        {help ? <Hint text={help} /> : null}
      </span>
      {children}
      {hint ? <span className="text-xs text-muted-foreground">{hint}</span> : null}
    </label>
  );
}

export function TextInput({ className, ...rest }: InputHTMLAttributes<HTMLInputElement>) {
  return (
    <input
      {...rest}
      className={cn(
        "h-11 w-full rounded-xl border border-input bg-card px-3.5 text-[15px] text-foreground outline-none transition-shadow placeholder:text-muted-foreground/70 focus:border-primary/50 focus:ring-4 focus:ring-primary/10",
        className,
      )}
    />
  );
}

/** Big, friendly choice card (radio). */
export function ChoiceCard({
  selected,
  onSelect,
  icon,
  title,
  description,
  footer,
}: {
  selected: boolean;
  onSelect: () => void;
  icon: IconSvgElement;
  title: string;
  description: string;
  footer?: ReactNode;
}) {
  return (
    <motion.button
      type="button"
      role="radio"
      aria-checked={selected}
      onClick={onSelect}
      whileTap={{ scale: 0.985 }}
      className={cn(
        "relative flex flex-col items-start gap-2 rounded-2xl border p-4 text-start outline-none transition-colors focus-visible:ring-2 focus-visible:ring-ring",
        selected ? "border-primary/60 bg-primary/[0.04]" : "border-border bg-card hover:bg-accent/60",
      )}
    >
      <span className={cn("flex size-10 items-center justify-center rounded-xl", selected ? "bg-primary text-primary-foreground" : "bg-muted text-muted-foreground")}>
        <HugeiconsIcon icon={icon} size={22} />
      </span>
      <span className="text-[15px] font-bold text-foreground">{title}</span>
      <span className="text-[13px] leading-6 text-muted-foreground">{description}</span>
      {footer}
      {selected ? (
        <motion.span layoutId="choice-dot" className="absolute top-4 left-4 size-2.5 rounded-full bg-primary" transition={{ type: "spring", bounce: 0.3, duration: 0.4 }} />
      ) : null}
    </motion.button>
  );
}

/** Segmented control (small set of options). */
export function Segmented<T extends string>({ value, onChange, options, className }: { value: T; onChange: (v: T) => void; options: { value: T; label: string }[]; className?: string }) {
  return (
    <div role="radiogroup" className={cn("inline-flex rounded-full border border-border bg-muted p-1", className)}>
      {options.map((o) => (
        <button
          key={o.value}
          type="button"
          role="radio"
          aria-checked={value === o.value}
          onClick={() => onChange(o.value)}
          className={cn("relative h-8 rounded-full px-4 text-[13px] font-medium transition-colors", value === o.value ? "text-foreground" : "text-muted-foreground hover:text-foreground")}
        >
          {value === o.value ? (
            <motion.span layoutId={`seg-${options.map((x) => x.value).join("")}`} className="absolute inset-0 rounded-full bg-card shadow-sm" transition={{ type: "spring", bounce: 0.2, duration: 0.4 }} />
          ) : null}
          <span className="relative">{o.label}</span>
        </button>
      ))}
    </div>
  );
}

export function ProgressBar({ value, tone = "primary" }: { value: number; tone?: "primary" | "amber" }) {
  const pct = Math.max(0, Math.min(100, value * 100));
  return (
    <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
      <motion.div
        className={cn("h-full rounded-full", tone === "primary" ? "bg-primary" : "bg-amber-500")}
        initial={false}
        animate={{ width: `${pct}%` }}
        transition={{ type: "spring", bounce: 0, duration: 0.8 }}
      />
    </div>
  );
}

export function Skeleton({ className }: { className?: string }) {
  return <div className={cn("animate-pulse rounded-xl bg-muted", className)} />;
}

export function Empty({ icon, title, text, action }: { icon: IconSvgElement; title: string; text: string; action?: ReactNode }) {
  return (
    <div className="flex flex-col items-center justify-center gap-3 px-6 py-14 text-center">
      <span className="flex size-14 items-center justify-center rounded-2xl bg-primary/10 text-primary">
        <HugeiconsIcon icon={icon} size={28} />
      </span>
      <p className="m-0 text-base font-bold">{title}</p>
      <p className="m-0 max-w-md text-[13px] leading-6 text-muted-foreground">{text}</p>
      {action}
    </div>
  );
}

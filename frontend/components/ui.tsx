"use client";

import { useEffect, useRef } from "react";
import { useT } from "@/components/Providers";
import type { OpStatus } from "@/lib/types";

// ---------------------------------------------------------------- buttons & fields

type Variant = "primary" | "secondary" | "danger" | "ghost";

export function Button({
  variant = "secondary",
  busy,
  className = "",
  children,
  ...rest
}: React.ButtonHTMLAttributes<HTMLButtonElement> & { variant?: Variant; busy?: boolean }) {
  const base =
    "inline-flex items-center justify-center gap-2 rounded-lg px-3.5 py-2 text-sm font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-50";
  const styles: Record<Variant, string> = {
    primary: "bg-accent text-accent-ink hover:brightness-110",
    secondary: "border border-line-strong bg-surface text-ink hover:bg-surface-2",
    danger: "bg-bad text-white hover:brightness-110",
    ghost: "text-ink-2 hover:bg-surface-2",
  };
  return (
    <button {...rest} disabled={rest.disabled || busy} className={`${base} ${styles[variant]} ${className}`}>
      {busy && <span className="size-3.5 animate-spin rounded-full border-2 border-current border-t-transparent" aria-hidden />}
      {children}
    </button>
  );
}

export const inputClass =
  "w-full rounded-lg border border-line-strong bg-surface px-3 py-2 text-sm text-ink placeholder:text-ink-3 focus:border-accent";

export function Field({ label, hint, children }: { label: string; hint?: string; children: React.ReactNode }) {
  return (
    <label className="block space-y-1">
      <span className="block text-xs font-medium text-ink-2">{label}</span>
      {children}
      {hint && <span className="block text-xs text-ink-3">{hint}</span>}
    </label>
  );
}

// ---------------------------------------------------------------- layout blocks

export function Card({
  title,
  action,
  children,
  className = "",
}: {
  title?: React.ReactNode;
  action?: React.ReactNode;
  children: React.ReactNode;
  className?: string;
}) {
  return (
    <section className={`min-w-0 rounded-xl border border-line bg-surface ${className}`}>
      {(title || action) && (
        <header className="flex flex-wrap items-center justify-between gap-2 border-b border-line px-4 py-3">
          <h2 className="text-sm font-semibold text-ink">{title}</h2>
          {action}
        </header>
      )}
      <div className="p-4">{children}</div>
    </section>
  );
}

export function Kpi({ label, value, sub, tone }: { label: string; value: React.ReactNode; sub?: React.ReactNode; tone?: "bad" | "good" }) {
  return (
    <div className="rounded-xl border border-line bg-surface p-4">
      <div className="text-xs text-ink-2">{label}</div>
      <div className={`mt-1 text-2xl font-semibold ${tone === "bad" ? "text-bad" : tone === "good" ? "text-good-ink" : "text-ink"}`}>{value}</div>
      {sub && <div className="mt-1 text-xs text-ink-3">{sub}</div>}
    </div>
  );
}

export function PageHeader({ title, sub, actions }: { title: string; sub?: React.ReactNode; actions?: React.ReactNode }) {
  return (
    <div className="mb-5 flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 className="text-xl font-semibold text-ink">{title}</h1>
        {sub && <p className="mt-0.5 text-sm text-ink-2">{sub}</p>}
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  );
}

export function Banner({ tone = "info", children }: { tone?: "info" | "warn" | "error" | "ok"; children: React.ReactNode }) {
  const style = {
    info: "border-line-strong bg-surface-2 text-ink",
    warn: "border-warn bg-warn-soft text-ink",
    error: "border-bad bg-bad-soft text-ink",
    ok: "border-line-strong bg-accent-soft text-ink",
  }[tone];
  const icon = { info: "ℹ", warn: "⚠", error: "✕", ok: "✓" }[tone];
  return (
    <div role={tone === "error" ? "alert" : "status"} className={`flex gap-2 rounded-lg border px-3 py-2.5 text-sm ${style}`}>
      <span aria-hidden className="font-bold">
        {icon}
      </span>
      <div className="min-w-0 flex-1">{children}</div>
    </div>
  );
}

export function Empty({ children }: { children: React.ReactNode }) {
  return <p className="py-6 text-center text-sm text-ink-3">{children}</p>;
}

export function Loading() {
  const { t } = useT();
  return <p className="py-10 text-center text-sm text-ink-3">{t("common.loading")}</p>;
}

export function ErrorBox({ message }: { message: string }) {
  return <Banner tone="error">{message}</Banner>;
}

export function ProgressBar({ value, label }: { value: number; label?: string }) {
  const v = Math.max(0, Math.min(100, value));
  return (
    <div
      role="progressbar"
      aria-valuenow={Math.round(v)}
      aria-valuemin={0}
      aria-valuemax={100}
      aria-label={label}
      className="h-2 w-full overflow-hidden rounded-full bg-surface-2"
    >
      <div className="h-full rounded-full bg-accent" style={{ width: `${v}%` }} />
    </div>
  );
}

export function Segmented<T extends string>({
  value,
  options,
  onChange,
  label,
}: {
  value: T;
  options: { value: T; label: string; disabled?: boolean; hint?: string }[];
  onChange: (v: T) => void;
  label: string;
}) {
  return (
    <div role="radiogroup" aria-label={label} className="inline-flex rounded-lg border border-line-strong bg-surface-2 p-0.5">
      {options.map((o) => (
        <button
          key={o.value}
          role="radio"
          aria-checked={value === o.value}
          disabled={o.disabled}
          title={o.hint}
          onClick={() => onChange(o.value)}
          className={`rounded-md px-3 py-1.5 text-sm font-medium transition-colors disabled:opacity-50 ${
            value === o.value ? "bg-surface text-ink shadow-sm ring-1 ring-line-strong" : "text-ink-2 hover:text-ink"
          }`}
        >
          {o.label}
        </button>
      ))}
    </div>
  );
}

export function Modal({
  open,
  title,
  onClose,
  children,
  footer,
  wide,
}: {
  open: boolean;
  title: string;
  onClose: () => void;
  children: React.ReactNode;
  footer?: React.ReactNode;
  wide?: boolean;
}) {
  const ref = useRef<HTMLDivElement>(null);
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => e.key === "Escape" && onClose();
    document.addEventListener("keydown", onKey);
    ref.current?.focus();
    return () => document.removeEventListener("keydown", onKey);
  }, [open, onClose]);
  if (!open) return null;
  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/50 p-4 sm:items-center" onMouseDown={onClose}>
      <div
        ref={ref}
        tabIndex={-1}
        role="dialog"
        aria-modal="true"
        aria-label={title}
        onMouseDown={(e) => e.stopPropagation()}
        className={`w-full ${wide ? "max-w-3xl" : "max-w-lg"} rounded-xl border border-line bg-surface shadow-2xl outline-none`}
      >
        <header className="flex items-center justify-between border-b border-line px-5 py-3">
          <h2 className="text-base font-semibold">{title}</h2>
          <button onClick={onClose} aria-label="Close" className="rounded p-1 text-ink-2 hover:bg-surface-2">
            ✕
          </button>
        </header>
        <div className="max-h-[70vh] overflow-y-auto px-5 py-4">{children}</div>
        {footer && <footer className="flex flex-wrap justify-end gap-2 border-t border-line px-5 py-3">{footer}</footer>}
      </div>
    </div>
  );
}

// ---------------------------------------------------------------- status

export const STATUS_COLOR: Record<OpStatus, string> = {
  NOT_READY: "var(--s-not-ready)",
  READY: "var(--s-ready)",
  ASSIGNED: "var(--s-assigned)",
  IN_PROGRESS: "var(--s-progress)",
  BLOCKED: "var(--s-blocked)",
  COMPLETED: "var(--s-completed)",
  CANCELLED: "var(--s-cancelled)",
};

/** Every status has its own shape, so state never relies on colour alone. */
export function StatusIcon({ status, size = 14 }: { status: OpStatus; size?: number }) {
  const c = STATUS_COLOR[status];
  const common = { width: size, height: size, viewBox: "0 0 16 16", "aria-hidden": true } as const;
  switch (status) {
    case "NOT_READY":
      return (
        <svg {...common}>
          <circle cx="8" cy="8" r="6" fill="none" stroke={c} strokeWidth="2" strokeDasharray="3 2" />
        </svg>
      );
    case "READY":
      return (
        <svg {...common}>
          <circle cx="8" cy="8" r="6" fill="none" stroke={c} strokeWidth="2" />
        </svg>
      );
    case "ASSIGNED":
      return (
        <svg {...common}>
          <circle cx="8" cy="8" r="6" fill="none" stroke={c} strokeWidth="2" />
          <circle cx="8" cy="8" r="2.5" fill={c} />
        </svg>
      );
    case "IN_PROGRESS":
      return (
        <svg {...common}>
          <circle cx="8" cy="8" r="6" fill="none" stroke={c} strokeWidth="2" />
          <path d="M8 2a6 6 0 0 1 0 12z" fill={c} />
        </svg>
      );
    case "BLOCKED":
      return (
        <svg {...common}>
          <path d="M5 1.5h6L14.5 5v6L11 14.5H5L1.5 11V5z" fill={c} />
          <path d="M5.5 8h5" stroke="#fff" strokeWidth="2" strokeLinecap="round" />
        </svg>
      );
    case "COMPLETED":
      return (
        <svg {...common}>
          <circle cx="8" cy="8" r="7" fill={c} />
          <path d="M4.5 8.2l2.4 2.4 4.6-4.9" fill="none" stroke="#fff" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
      );
    case "CANCELLED":
      return (
        <svg {...common}>
          <circle cx="8" cy="8" r="6" fill="none" stroke={c} strokeWidth="2" />
          <path d="M4.5 11.5l7-7" stroke={c} strokeWidth="2" strokeLinecap="round" />
        </svg>
      );
  }
}

export function StatusBadge({ status }: { status: OpStatus }) {
  const { t } = useT();
  return (
    <span className="inline-flex items-center gap-1.5 whitespace-nowrap text-xs font-medium text-ink">
      <StatusIcon status={status} />
      {t(`status.${status}`)}
    </span>
  );
}

export function Mono({ children }: { children: React.ReactNode }) {
  return <span className="ltr font-mono text-xs">{children}</span>;
}

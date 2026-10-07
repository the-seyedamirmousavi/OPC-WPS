"use client";

import { useRouter } from "next/navigation";
import { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";
import { en, fa } from "@/lib/i18n";
import type { User } from "@/lib/types";

// ---------------------------------------------------------------- i18n

export type Lang = "fa" | "en";

interface I18n {
  lang: Lang;
  setLang: (l: Lang) => void;
  t: (key: string, params?: Record<string, string | number>) => string;
  num: (n: number | null | undefined, digits?: number) => string;
  date: (iso: string | null | undefined) => string;
  hours: (h: number | null | undefined) => string;
  percent: (n: number | null | undefined) => string;
}

const I18nContext = createContext<I18n | null>(null);

export function useT() {
  const ctx = useContext(I18nContext);
  if (!ctx) throw new Error("useT outside Providers");
  return ctx;
}

// ---------------------------------------------------------------- session

interface Session {
  user: User | null;
  signOut: () => Promise<void>;
  setUser: (u: User | null) => void;
}

const SessionContext = createContext<Session | null>(null);

export function useSession() {
  const ctx = useContext(SessionContext);
  if (!ctx) throw new Error("useSession outside Providers");
  return ctx;
}

// ---------------------------------------------------------------- toasts

type ToastKind = "ok" | "error" | "info";
interface Toast {
  id: number;
  kind: ToastKind;
  text: string;
}
const ToastContext = createContext<((text: string, kind?: ToastKind) => void) | null>(null);

export function useToast() {
  const ctx = useContext(ToastContext);
  if (!ctx) throw new Error("useToast outside Providers");
  return ctx;
}

// ---------------------------------------------------------------- provider

export function Providers({ initialLang, children }: { initialLang: Lang; children: React.ReactNode }) {
  const [lang, setLangState] = useState<Lang>(initialLang);
  const [user, setUser] = useState<User | null>(null);
  const [toasts, setToasts] = useState<Toast[]>([]);
  const router = useRouter();

  const setLang = useCallback((l: Lang) => {
    document.cookie = `aiso_lang=${l}; path=/; max-age=31536000; samesite=lax`;
    document.documentElement.lang = l;
    document.documentElement.dir = l === "fa" ? "rtl" : "ltr";
    setLangState(l);
  }, []);

  useEffect(() => {
    try {
      const saved = localStorage.getItem("aiso_theme");
      if (saved === "light" || saved === "dark") document.documentElement.dataset.theme = saved;
    } catch {
      /* storage unavailable: keep the system theme */
    }
  }, []);

  const i18n = useMemo<I18n>(() => {
    const dict = lang === "fa" ? fa : en;
    const locale = lang === "fa" ? "fa-IR" : "en-US";
    const nf = new Intl.NumberFormat(locale, { maximumFractionDigits: 1 });
    const df = new Intl.DateTimeFormat(lang === "fa" ? "fa-IR-u-ca-persian" : "en-GB", {
      dateStyle: "medium",
      timeStyle: "short",
    });
    const t = (key: string, params?: Record<string, string | number>) => {
      let s = dict[key] ?? en[key] ?? key;
      if (params) for (const [k, v] of Object.entries(params)) s = s.replaceAll(`{${k}}`, String(v));
      return s;
    };
    const num = (n: number | null | undefined, digits = 1) =>
      n === null || n === undefined || Number.isNaN(n)
        ? "—"
        : new Intl.NumberFormat(locale, { maximumFractionDigits: digits }).format(n);
    return {
      lang,
      setLang,
      t,
      num,
      date: (iso) => (iso ? df.format(new Date(iso)) : "—"),
      hours: (h) => (h === null || h === undefined ? "—" : `${nf.format(h)} ${t("unit.h")}`),
      percent: (n) => (n === null || n === undefined ? "—" : `${nf.format(n)}%`),
    };
  }, [lang, setLang]);

  const signOut = useCallback(async () => {
    await fetch("/api/session", { method: "DELETE" });
    setUser(null);
    router.replace("/login");
  }, [router]);

  const session = useMemo(() => ({ user, setUser, signOut }), [user, signOut]);

  const toast = useCallback((text: string, kind: ToastKind = "ok") => {
    const id = Date.now() + Math.random();
    setToasts((x) => [...x, { id, kind, text }]);
    setTimeout(() => setToasts((x) => x.filter((m) => m.id !== id)), kind === "error" ? 7000 : 3500);
  }, []);

  return (
    <I18nContext.Provider value={i18n}>
      <SessionContext.Provider value={session}>
        <ToastContext.Provider value={toast}>
          {children}
          <div className="pointer-events-none fixed bottom-4 end-4 z-[100] flex w-80 max-w-[calc(100vw-2rem)] flex-col gap-2" aria-live="polite">
            {toasts.map((m) => (
              <div
                key={m.id}
                role={m.kind === "error" ? "alert" : "status"}
                className={`pointer-events-auto rounded-lg border px-4 py-3 text-sm shadow-lg ${
                  m.kind === "error"
                    ? "border-bad bg-bad-soft text-ink"
                    : "border-line-strong bg-surface text-ink"
                }`}
              >
                {m.text}
              </div>
            ))}
          </div>
        </ToastContext.Provider>
      </SessionContext.Provider>
    </I18nContext.Provider>
  );
}

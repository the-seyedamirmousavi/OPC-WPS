"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { Banner, Button, Field, inputClass } from "@/components/ui";
import { useT } from "@/components/Providers";
import { api } from "@/lib/api";
import type { User } from "@/lib/types";

export default function LoginPage() {
  const { t, lang, setLang } = useT();
  const router = useRouter();
  const [userId, setUserId] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await api<User>("/session", { method: "POST", body: { userId, password } });
      router.replace("/");
      router.refresh();
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err));
      setBusy(false);
    }
  };

  return (
    <main className="flex min-h-screen items-center justify-center px-4">
      <div className="w-full max-w-sm">
        <div className="mb-6 flex items-center justify-between">
          <h1 className="text-2xl font-bold tracking-tight">AISO</h1>
          <Button variant="ghost" onClick={() => setLang(lang === "fa" ? "en" : "fa")}>
            {lang === "fa" ? "English" : "فارسی"}
          </Button>
        </div>
        <form onSubmit={submit} className="space-y-4 rounded-xl border border-line bg-surface p-6">
          <p className="text-sm text-ink-2">{t("login.subtitle")}</p>
          {error && <Banner tone="error">{error}</Banner>}
          <Field label={t("login.userId")}>
            <input className={`${inputClass} ltr`} value={userId} onChange={(e) => setUserId(e.target.value)} autoComplete="username" autoFocus required />
          </Field>
          <Field label={t("login.password")}>
            <input type="password" className={`${inputClass} ltr`} value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="current-password" required />
          </Field>
          <Button type="submit" variant="primary" busy={busy} className="w-full">
            {t("login.signIn")}
          </Button>
        </form>
      </div>
    </main>
  );
}

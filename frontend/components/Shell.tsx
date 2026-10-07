"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { Button, Field, Loading, Modal, inputClass } from "@/components/ui";
import { useSession, useT, useToast } from "@/components/Providers";
import { api, useFetch } from "@/lib/api";
import type { Notification, Role, User } from "@/lib/types";

interface NavItem {
  href: string;
  label: string;
  roles: Role[];
}

const MGMT: Role[] = ["OWNER", "MANAGER"];
const EXEC: Role[] = ["USER_1", "USER_2", "USER_3"];

const NAV: NavItem[] = [
  { href: "/owner", label: "nav.owner", roles: ["OWNER"] },
  { href: "/manager", label: "nav.overview", roles: MGMT },
  { href: "/manager/projects", label: "nav.projects", roles: MGMT },
  { href: "/manager/assignment", label: "nav.assignment", roles: MGMT },
  { href: "/manager/operations", label: "nav.operations", roles: MGMT },
  { href: "/manager/plan", label: "nav.plan", roles: MGMT },
  { href: "/manager/reports", label: "nav.reports", roles: MGMT },
  { href: "/import", label: "nav.import", roles: MGMT },
  { href: "/owner/users", label: "nav.users", roles: ["OWNER"] },
  { href: "/audit", label: "nav.audit", roles: MGMT },
  { href: "/my", label: "nav.myTasks", roles: EXEC },
];

export function homeFor(role: Role) {
  return role === "OWNER" ? "/owner" : role === "MANAGER" ? "/manager" : "/my";
}

export function AppShell({ children }: { children: React.ReactNode }) {
  const { user, setUser } = useSession();
  const { t, lang, setLang } = useT();
  const pathname = usePathname();
  const router = useRouter();
  const [menu, setMenu] = useState(false);

  useEffect(() => {
    let alive = true;
    api<User>("/session")
      .then((u) => alive && setUser(u))
      .catch(() => {
        /* api() already redirects on 401 */
      });
    return () => {
      alive = false;
    };
  }, [setUser]);

  // keep each role inside its own area (the backend enforces this too)
  useEffect(() => {
    if (!user) return;
    const allowed = NAV.some((n) => n.roles.includes(user.role) && (pathname === n.href || pathname.startsWith(n.href + "/")));
    if (!allowed && pathname !== "/") router.replace(homeFor(user.role));
  }, [user, pathname, router]);

  if (!user) return <Loading />;

  const items = NAV.filter((n) => n.roles.includes(user.role));
  const active = (href: string) =>
    pathname === href || (href !== "/manager" && href !== "/owner" && pathname.startsWith(href + "/"));

  const links = (
    <nav className="flex flex-col gap-0.5" aria-label="Main">
      {items.map((n) => (
        <Link
          key={n.href}
          href={n.href}
          onClick={() => setMenu(false)}
          aria-current={active(n.href) ? "page" : undefined}
          className={`rounded-lg px-3 py-2 text-sm font-medium ${
            active(n.href) ? "bg-accent-soft text-ink" : "text-ink-2 hover:bg-surface-2"
          }`}
        >
          {t(n.label)}
        </Link>
      ))}
    </nav>
  );

  return (
    <div className="min-h-screen lg:flex">
      <aside className="hidden w-60 shrink-0 border-e border-line bg-surface p-4 lg:block">
        <div className="mb-6 px-3 text-lg font-bold tracking-tight">AISO</div>
        {links}
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-30 flex items-center gap-2 border-b border-line bg-surface/95 px-4 py-2.5 backdrop-blur">
          <button
            className="rounded-lg p-2 text-ink-2 hover:bg-surface-2 lg:hidden"
            aria-label="Menu"
            aria-expanded={menu}
            onClick={() => setMenu((m) => !m)}
          >
            ☰
          </button>
          <span className="font-bold lg:hidden">AISO</span>
          <div className="flex-1" />
          <Button variant="ghost" onClick={() => setLang(lang === "fa" ? "en" : "fa")} aria-label="Language">
            {lang === "fa" ? "EN" : "فا"}
          </Button>
          <ThemeToggle />
          <Bell />
          <UserMenu user={user} />
        </header>
        {menu && <div className="border-b border-line bg-surface p-3 lg:hidden">{links}</div>}
        <main className="mx-auto w-full max-w-7xl flex-1 px-4 py-6">{children}</main>
      </div>
    </div>
  );
}

function ThemeToggle() {
  const { t } = useT();
  const toggle = () => {
    const root = document.documentElement;
    const dark =
      root.dataset.theme === "dark" ||
      (root.dataset.theme !== "light" && window.matchMedia("(prefers-color-scheme: dark)").matches);
    const next = dark ? "light" : "dark";
    root.dataset.theme = next;
    try {
      localStorage.setItem("aiso_theme", next);
    } catch {
      /* ignore */
    }
  };
  return (
    <Button variant="ghost" onClick={toggle} aria-label={t("common.theme")} title={t("common.theme")}>
      ◐
    </Button>
  );
}

function Bell() {
  const { t, date } = useT();
  const [open, setOpen] = useState(false);
  const { data, reload } = useFetch<Notification[]>("/notifications", 30000);
  const unread = data?.filter((n) => !n.readAt).length ?? 0;

  const markAll = async () => {
    await api("/notifications/read-all", { method: "POST" });
    reload();
  };

  return (
    <div className="relative">
      <Button variant="ghost" onClick={() => setOpen((o) => !o)} aria-label={t("notif.title")} aria-expanded={open}>
        🔔
        {unread > 0 && (
          <span className="rounded-full bg-bad px-1.5 text-xs font-semibold text-white">{unread}</span>
        )}
      </Button>
      {open && (
        <div className="absolute end-0 top-full z-40 mt-1 w-80 max-w-[85vw] rounded-xl border border-line bg-surface shadow-xl">
          <div className="flex items-center justify-between border-b border-line px-3 py-2">
            <span className="text-sm font-semibold">{t("notif.title")}</span>
            {unread > 0 && (
              <button onClick={markAll} className="text-xs text-accent hover:underline">
                {t("notif.markAll")}
              </button>
            )}
          </div>
          <ul className="max-h-80 divide-y divide-line overflow-y-auto">
            {(data ?? []).length === 0 && <li className="p-4 text-center text-sm text-ink-3">{t("notif.none")}</li>}
            {(data ?? []).map((n) => (
              <li key={n.id} className={`px-3 py-2 text-sm ${n.readAt ? "text-ink-2" : "font-medium text-ink"}`}>
                <div>{n.message}</div>
                <div className="mt-0.5 text-xs text-ink-3">{date(n.createdAt)}</div>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}

function UserMenu({ user }: { user: User }) {
  const { t } = useT();
  const { signOut } = useSession();
  const toast = useToast();
  const [open, setOpen] = useState(false);
  const [pw, setPw] = useState(false);
  const [cur, setCur] = useState("");
  const [next, setNext] = useState("");
  const [busy, setBusy] = useState(false);

  const change = async () => {
    setBusy(true);
    try {
      await api("/auth/change-password", { method: "POST", body: { currentPassword: cur, newPassword: next } });
      toast(t("user.passwordChanged"));
      setPw(false);
      setCur("");
      setNext("");
    } catch (e) {
      toast(e instanceof Error ? e.message : String(e), "error");
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="relative">
      <Button variant="secondary" onClick={() => setOpen((o) => !o)} aria-expanded={open}>
        <span className="max-w-32 truncate">{user.fullName}</span>
        <span className="text-xs text-ink-3">{t(`role.${user.role}`)}</span>
      </Button>
      {open && (
        <div className="absolute end-0 top-full z-40 mt-1 w-48 rounded-xl border border-line bg-surface p-1 shadow-xl">
          <button
            className="block w-full rounded-lg px-3 py-2 text-start text-sm hover:bg-surface-2"
            onClick={() => {
              setOpen(false);
              setPw(true);
            }}
          >
            {t("user.changePassword")}
          </button>
          <button className="block w-full rounded-lg px-3 py-2 text-start text-sm hover:bg-surface-2" onClick={signOut}>
            {t("user.signOut")}
          </button>
        </div>
      )}
      <Modal
        open={pw}
        title={t("user.changePassword")}
        onClose={() => setPw(false)}
        footer={
          <>
            <Button onClick={() => setPw(false)}>{t("common.cancel")}</Button>
            <Button variant="primary" busy={busy} disabled={!cur || next.length < 8} onClick={change}>
              {t("common.save")}
            </Button>
          </>
        }
      >
        <div className="space-y-3">
          <Field label={t("user.currentPassword")}>
            <input type="password" autoComplete="current-password" className={inputClass} value={cur} onChange={(e) => setCur(e.target.value)} />
          </Field>
          <Field label={t("user.newPassword")} hint={t("user.passwordRule")}>
            <input type="password" autoComplete="new-password" className={inputClass} value={next} onChange={(e) => setNext(e.target.value)} />
          </Field>
        </div>
      </Modal>
    </div>
  );
}

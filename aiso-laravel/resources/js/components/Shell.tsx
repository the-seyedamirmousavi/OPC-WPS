
import { useEffect, useRef, useState } from "react";
import { Link, useLocation, useNavigate } from "react-router-dom";
import { Banner, Button, Field, Loading, Modal, inputClass } from "@/components/ui";
import { useSession, useT, useToast } from "@/components/Providers";
import { api, useFetch } from "@/lib/api";
import { can, roleLabel } from "@/lib/capabilities";
import type { DepartmentMenuItem, Notification, User } from "@/lib/types";

interface NavItem {
  href: string;
  label: string;
  /** who sees the item: the owner, a capability, the people who receive tasks, or everyone signed in */
  show: (u: User) => boolean;
}

const OWNER = (u: User) => u.role === "OWNER";
// The owner does not get the production pages as menu items: they are inside the Production department's workspace.
const PRODUCTION = (u: User) => u.role !== "OWNER" && can(u, "production.view");
const TASKS = (u: User) => u.receivesTasks;

/**
 * The owner's menu is one Dashboard, the department panels (Production holds all the planning pages and the import history,
 * Human resources holds users, roles and the audit log) and the AI assistant. System administration is in the account menu.
 * A manager keeps the production pages as a short list of their own; the assistant is a small button in their header.
 */
const NAV: NavItem[] = [
  { href: "/owner", label: "nav.dashboard", show: OWNER },
  { href: "/manager/projects", label: "nav.projects", show: PRODUCTION },
  { href: "/manager/assignment", label: "nav.assignment", show: PRODUCTION },
  { href: "/manager/operations", label: "nav.operations", show: PRODUCTION },
  { href: "/manager/planner", label: "nav.planner", show: PRODUCTION },
  { href: "/manager/plan", label: "nav.plan", show: PRODUCTION },
  { href: "/manager/reports", label: "nav.reports", show: PRODUCTION },
  { href: "/my", label: "nav.myTasks", show: TASKS },
];

/** Where a person lands: the owner's dashboard, the production workspace, their tasks, or the assistant. */
export function homeFor(user: User) {
  if (user.role === "OWNER") return "/owner";
  if (can(user, "production.view")) return "/manager";
  if (user.receivesTasks) return "/my";

  return "/chat";
}

export function AppShell({ children }: { children: React.ReactNode }) {
  const { user, setUser } = useSession();
  const { t, lang, setLang } = useT();
  const { pathname } = useLocation();
  const navigate = useNavigate();
  const [menu, setMenu] = useState(false);

  useEffect(() => {
    let alive = true;
    api<User>("/auth/me")
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
    // a department panel is allowed for everyone signed in: the server answers 403 where the person has no access
    const allowed =
      pathname === "/chat" ||
      pathname.startsWith("/departments/") ||
      pathname === "/manager" ||
      // the owner's old manager addresses are sent into the Production workspace by ManagerArea
      (user.role === "OWNER" && (pathname.startsWith("/manager/") || pathname === "/owner/system")) ||
      NAV.some((n) => n.show(user) && (pathname === n.href || pathname.startsWith(n.href + "/")));
    if (!allowed && pathname !== "/") navigate(homeFor(user), { replace: true });
  }, [user, pathname, navigate]);

  if (!user) return <Loading />;

  const items = NAV.filter((n) => n.show(user));
  const active = (href: string) =>
    pathname === href || (href !== "/manager" && href !== "/owner" && pathname.startsWith(href + "/"));

  const links = (
    <nav className="flex flex-col gap-0.5" aria-label="Main">
      {items.map((n) => (
        <Link
          key={n.href}
          to={n.href}
          onClick={() => setMenu(false)}
          aria-current={active(n.href) ? "page" : undefined}
          className={`rounded-lg px-3 py-2 text-sm font-medium ${
            active(n.href) ? "bg-accent-soft text-ink" : "text-ink-2 hover:bg-surface-2"
          }`}
        >
          {t(n.label)}
        </Link>
      ))}
      {/* the departments are fixed: one button each, straight into the workspace (all of them for the owner) */}
      <DepartmentMenu pathname={pathname} onPick={() => setMenu(false)} />
      {/* the owner's menu ends with the AI assistant; everyone else has it as a small button in the header */}
      {user.role === "OWNER" && (
        <div className="mt-4 border-t border-line pt-3">
          <Link
            to="/chat"
            onClick={() => setMenu(false)}
            aria-current={pathname === "/chat" ? "page" : undefined}
            className={`block rounded-lg px-3 py-2 text-sm font-medium ${pathname === "/chat" ? "bg-accent-soft text-ink" : "text-ink-2 hover:bg-surface-2"}`}
          >
            ✦ {t("nav.chat")}
          </Link>
          <Link
            to="/owner/assistant"
            onClick={() => setMenu(false)}
            aria-current={pathname === "/owner/assistant" ? "page" : undefined}
            className={`mt-1 block rounded-lg px-3 py-2 text-sm font-medium ${pathname === "/owner/assistant" ? "bg-accent-soft text-ink" : "text-ink-2 hover:bg-surface-2"}`}
          >
            {t("nav.assistantLog")}
          </Link>
        </div>
      )}
    </nav>
  );

  return (
    <div className="min-h-screen lg:flex">
      <aside className="hidden w-60 shrink-0 overflow-y-auto border-e border-line bg-surface p-4 lg:sticky lg:top-0 lg:block lg:h-screen lg:self-start">
        <div className="mb-6 px-3 text-lg font-bold tracking-tight">AISO</div>
        {links}
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-30 flex flex-wrap items-center gap-1 sm:flex-nowrap border-b border-line bg-surface/95 px-3 py-2.5 backdrop-blur sm:gap-2 sm:px-4">
          <button
            className="shrink-0 rounded-lg p-2 text-ink-2 hover:bg-surface-2 lg:hidden"
            aria-label="Menu"
            aria-expanded={menu}
            onClick={() => setMenu((m) => !m)}
          >
            ☰
          </button>
          <span className="shrink-0 font-bold lg:hidden">AISO</span>
          <div className="hidden min-w-0 flex-1 truncate px-2 text-sm text-ink-2 sm:block">
            <TodayDate />
          </div>
          <div className="min-w-0 flex-1 sm:hidden" />
          {/* phones: the date gets its own thin row so it is always visible without crowding the buttons */}
          <div className="order-last basis-full border-t border-line pt-1 text-center text-xs text-ink-2 sm:hidden">
            <TodayDate />
          </div>
          <Button variant="ghost" className="shrink-0 px-2.5! sm:px-3.5!" onClick={() => setLang(lang === "fa" ? "en" : "fa")} aria-label="Language">
            {lang === "fa" ? "EN" : "فا"}
          </Button>
          {user.role !== "OWNER" && <AssistantButton active={pathname === "/chat"} />}
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

/** The departments the person may open, each one a button in the menu. Read from the database through the API. */
function DepartmentMenu({ pathname, onPick }: { pathname: string; onPick: () => void }) {
  const { t } = useT();
  const { data } = useFetch<DepartmentMenuItem[]>("/departments/menu", 60000);
  if (!data || data.length === 0) return null;
  return (
    <div className="mt-4 border-t border-line pt-3">
      <div className="px-3 pb-1 text-xs font-medium text-ink-3">{t("dept.menu.title")}</div>
      {data.map((d) => {
        const href = `/departments/${d.id}`;
        const current = pathname === href;
        return (
          <Link
            key={d.id}
            to={href}
            onClick={onPick}
            aria-current={current ? "page" : undefined}
            className={`block truncate rounded-lg px-3 py-2 text-sm font-medium ${current ? "bg-accent-soft text-ink" : "text-ink-2 hover:bg-surface-2"}`}
          >
            {d.name}
          </Link>
        );
      })}
    </div>
  );
}

/** The assistant for everyone but the owner: a small button in the header, so it takes no place in the side menu. */
function AssistantButton({ active }: { active: boolean }) {
  const { t } = useT();
  return (
    <Link
      to="/chat"
      aria-label={t("nav.chat")}
      title={t("nav.chat")}
      aria-current={active ? "page" : undefined}
      className={`inline-flex shrink-0 items-center justify-center gap-1.5 rounded-lg px-2.5 py-2 text-sm font-medium hover:bg-surface-2 sm:px-3.5 ${active ? "bg-accent-soft text-ink" : "text-ink-2"}`}
    >
      <span aria-hidden>✦</span>
      <span className="hidden md:inline">{t("nav.chat")}</span>
    </Link>
  );
}

/** Closes a dropdown on an outside click or Escape. Attach the returned ref to the element wrapping the trigger and the panel. */
function useDismiss(open: boolean, close: () => void) {
  const box = useRef<HTMLDivElement>(null);
  const closeRef = useRef(close);
  closeRef.current = close;
  useEffect(() => {
    if (!open) return;
    const onDown = (e: MouseEvent) => {
      if (box.current && !box.current.contains(e.target as Node)) closeRef.current();
    };
    const onKey = (e: KeyboardEvent) => e.key === "Escape" && closeRef.current();
    document.addEventListener("mousedown", onDown);
    document.addEventListener("keydown", onKey);
    return () => {
      document.removeEventListener("mousedown", onDown);
      document.removeEventListener("keydown", onKey);
    };
  }, [open]);
  return box;
}

/** Today's date, always in the header: Solar Hijri with the weekday (Tehran time) in Persian, Gregorian in English. */
function TodayDate() {
  const { lang } = useT();
  const [now, setNow] = useState(() => new Date());
  useEffect(() => {
    const id = setInterval(() => setNow(new Date()), 30000);
    return () => clearInterval(id);
  }, []);
  const fa = lang === "fa";
  const fmt = new Intl.DateTimeFormat(fa ? "fa-IR-u-ca-persian" : "en-GB", {
    weekday: "long",
    day: "numeric",
    month: "long",
    year: "numeric",
    ...(fa ? { timeZone: "Asia/Tehran" } : {}),
  });
  return <time dateTime={now.toISOString()}>{fmt.format(now)}</time>;
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
    <Button variant="ghost" className="shrink-0 px-2.5! sm:px-3.5!" onClick={toggle} aria-label={t("common.theme")} title={t("common.theme")}>
      ◐
    </Button>
  );
}

function Bell() {
  const { t, date } = useT();
  const [open, setOpen] = useState(false);
  const { data, reload } = useFetch<Notification[]>("/notifications", 30000);
  const unread = data?.filter((n) => !n.readAt).length ?? 0;
  const box = useDismiss(open, () => setOpen(false));

  const markAll = async () => {
    await api("/notifications/read-all", { method: "POST" });
    reload();
  };

  return (
    <div className="shrink-0 sm:relative" ref={box}>
      <Button variant="ghost" className="px-2.5! sm:px-3.5!" onClick={() => setOpen((o) => !o)} aria-label={t("notif.title")} aria-expanded={open}>
        🔔
        {unread > 0 && (
          <span className="rounded-full bg-bad px-1.5 text-xs font-semibold text-white">{unread}</span>
        )}
      </Button>
      {open && (
        // Phones: the panel spans the (positioned) header so it can never run off the screen; from sm up it hangs under the bell.
        <div className="absolute inset-x-3 top-full z-40 mt-1 rounded-xl border border-line bg-surface shadow-xl sm:inset-x-auto sm:end-0 sm:w-80">
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
  const [baleOpen, setBaleOpen] = useState(false);
  const [bale, setBale] = useState<{ connected: boolean; platform: string } | null>(null);
  const [linkCode, setLinkCode] = useState<string | null>(null);
  const box = useDismiss(open, () => setOpen(false));

  const openBale = async () => {
    setOpen(false);
    setLinkCode(null);
    setBaleOpen(true);
    try {
      setBale(await api<{ connected: boolean; platform: string }>("/me/bale"));
    } catch (e) {
      toast(e instanceof Error ? e.message : String(e), "error");
    }
  };

  const getCode = async () => {
    setBusy(true);
    try {
      const r = await api<{ code: string }>("/me/bale-link-code", { method: "POST" });
      setLinkCode(r.code);
    } catch (e) {
      toast(e instanceof Error ? e.message : String(e), "error");
    } finally {
      setBusy(false);
    }
  };

  const disconnectBale = async () => {
    setBusy(true);
    try {
      await api("/me/bale", { method: "DELETE" });
      toast(t("bale.disconnected"));
      setBale((b) => ({ connected: false, platform: b?.platform ?? "IN_APP" }));
      setLinkCode(null);
    } catch (e) {
      toast(e instanceof Error ? e.message : String(e), "error");
    } finally {
      setBusy(false);
    }
  };

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
    <div className="relative min-w-0 shrink" ref={box}>
      <Button variant="secondary" className="max-w-full px-2.5! sm:px-3.5!" onClick={() => setOpen((o) => !o)} aria-expanded={open}>
        <span className="max-w-20 truncate sm:max-w-32">{user.fullName}</span>
        <span className="hidden text-xs text-ink-3 sm:inline">{roleLabel(t, user.role, user.roleName)}</span>
      </Button>
      {open && (
        <div className="absolute end-0 top-full z-40 mt-1 w-48 rounded-xl border border-line bg-surface p-1 shadow-xl">
          {user.role === "OWNER" && (
            <Link
              to="/owner/system"
              onClick={() => setOpen(false)}
              className="block w-full rounded-lg px-3 py-2 text-start text-sm hover:bg-surface-2"
            >
              {t("user.system")}
            </Link>
          )}
          {user.role === "OWNER" && (
            <Link
              to="/owner/roles"
              onClick={() => setOpen(false)}
              className="block w-full rounded-lg px-3 py-2 text-start text-sm hover:bg-surface-2"
            >
              {t("user.roles")}
            </Link>
          )}
          <button
            className="block w-full rounded-lg px-3 py-2 text-start text-sm hover:bg-surface-2"
            onClick={() => {
              setOpen(false);
              setPw(true);
            }}
          >
            {t("user.changePassword")}
          </button>
          <button className="block w-full rounded-lg px-3 py-2 text-start text-sm hover:bg-surface-2" onClick={openBale}>
            {t("user.bale")}
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
      <Modal
        open={baleOpen}
        title={t("bale.title")}
        onClose={() => setBaleOpen(false)}
        footer={
          <>
            {bale?.connected && (
              <Button variant="danger" busy={busy} onClick={disconnectBale}>
                {t("bale.disconnect")}
              </Button>
            )}
            <Button onClick={() => setBaleOpen(false)}>{t("common.cancel")}</Button>
          </>
        }
      >
        <div className="space-y-3 text-sm">
          <p className="text-ink-2">{t("bale.intro")}</p>
          {bale && (
            <p className="font-medium">{bale.connected ? t("bale.connected") : t("bale.notConnected")}</p>
          )}
          {bale && bale.platform !== "BALE" && <Banner tone="warn">{t("bale.platformOff")}</Banner>}
          {bale && !bale.connected && (
            <>
              <Button variant="primary" busy={busy} onClick={getCode}>
                {t("bale.getCode")}
              </Button>
              {linkCode && (
                <div className="rounded-lg border border-line bg-surface-2 p-3">
                  <div>{t("bale.codeHint")}</div>
                  <div className="ltr mt-2 font-mono text-lg">/link {linkCode}</div>
                  <div className="mt-2 text-xs text-ink-3">{t("bale.codeExpires")}</div>
                </div>
              )}
            </>
          )}
        </div>
      </Modal>
    </div>
  );
}

import { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import { Banner, Button, Card, Empty, ErrorBox, Field, Loading, Modal, Mono, Segmented, inputClass } from "@/components/ui";
import { useT, useToast } from "@/components/Providers";
import { api, useFetch } from "@/lib/api";

// The software vendor's console. It is reached only by signing in on the normal form with the vendor's own credentials
// (nothing in the application links here), and it has its own simple header instead of the application shell.

type Plan = "UNLIMITED" | "YEARLY" | "TRIAL" | "CUSTOM";
type Tab = "company" | "license" | "owner" | "users" | "settings" | "log";

interface LicenseState {
  state: "NONE" | "ACTIVE" | "EXPIRING" | "GRACE" | "EXPIRED" | "SUSPENDED" | "NOT_STARTED";
  access: "FULL" | "READ_ONLY" | "BLOCKED";
  message: string | null;
  expiresAt: string | null;
  daysLeft: number | null;
  graceEndsAt: string | null;
}
interface Company {
  installationId: string;
  name: string;
  legalName: string | null;
  contactName: string | null;
  contactPhone: string | null;
  contactEmail: string | null;
  notes: string | null;
  createdAt: string | null;
}
interface Terms {
  plan: Plan;
  status: "ACTIVE" | "SUSPENDED";
  suspendedReason: string | null;
  startsAt: string | null;
  expiresAt: string | null;
  graceDays: number;
  maxUsers: number | null;
  maxActiveProjects: number | null;
  allowLlm: boolean;
  allowBale: boolean;
}
interface Overview {
  company: Company | null;
  license: Terms | null;
  state: LicenseState;
  usage: { activeUsers: number; activeProjects: number };
}
interface VendorUser {
  id: string;
  fullName: string;
  role: string;
  active: boolean;
  mustChangePassword: boolean;
}
interface Creds {
  id: string;
  password: string;
}
type ShowCreds = (c: Creds) => void;

const TONE: Record<LicenseState["state"], "info" | "ok" | "warn" | "error"> = {
  NONE: "info",
  ACTIVE: "ok",
  EXPIRING: "warn",
  GRACE: "warn",
  EXPIRED: "error",
  SUSPENDED: "error",
  NOT_STARTED: "error",
};

export default function VendorPage() {
  const { t, lang, setLang } = useT();
  const toast = useToast();
  const navigate = useNavigate();
  const me = useFetch<{ vendor: boolean }>("/vendor/me");
  const overview = useFetch<Overview>("/vendor/company");
  const [tab, setTab] = useState<Tab>("company");
  const [creds, setCreds] = useState<Creds | null>(null);

  // Not signed in as the vendor: the console does not exist for you, so go back to the sign-in page.
  useEffect(() => {
    if (me.error) navigate("/login", { replace: true });
  }, [me.error, navigate]);

  const signOut = async () => {
    try {
      await api("/vendor/logout", { method: "POST" });
    } finally {
      navigate("/login", { replace: true });
    }
  };

  if (me.loading || me.error) return <Loading />;

  const ov = overview.data;
  return (
    <div className="min-h-screen">
      <header className="sticky top-0 z-30 flex items-center gap-2 border-b border-line bg-surface px-3 py-2.5 sm:px-4">
        <span className="font-bold">AISO</span>
        <span className="rounded bg-accent-soft px-2 py-0.5 text-xs font-medium">{t("vendor.title")}</span>
        <div className="min-w-0 flex-1" />
        <Button variant="ghost" onClick={() => setLang(lang === "fa" ? "en" : "fa")} aria-label="Language">
          {lang === "fa" ? "EN" : "فا"}
        </Button>
        <Button variant="secondary" onClick={signOut}>
          {t("vendor.signOut")}
        </Button>
      </header>

      <main className="mx-auto w-full max-w-5xl space-y-4 px-4 py-6">
        <div>
          <h1 className="text-xl font-semibold">{t("vendor.title")}</h1>
          <p className="mt-0.5 text-sm text-ink-2">{t("vendor.subtitle")}</p>
        </div>

        {overview.error && <ErrorBox message={overview.error} />}
        {ov && <Summary ov={ov} />}

        <div className="overflow-x-auto pb-1">
          <Segmented<Tab>
            label={t("vendor.title")}
            value={tab}
            onChange={setTab}
            options={(["company", "license", "owner", "users", "settings", "log"] as Tab[]).map((k) => ({ value: k, label: t(`vendor.tab.${k}`) }))}
          />
        </div>

        {ov && tab === "company" && <CompanyTab ov={ov} reload={overview.reload} />}
        {ov && tab === "license" && <LicenseTab ov={ov} reload={overview.reload} />}
        {tab === "owner" && <OwnerTab showCreds={setCreds} />}
        {tab === "users" && <UsersTab showCreds={setCreds} onChange={overview.reload} />}
        {tab === "settings" && <SettingsTab />}
        {tab === "log" && <LogTab />}
      </main>

      <Modal
        open={creds !== null}
        title={t("vendor.creds.title")}
        onClose={() => setCreds(null)}
        footer={
          <>
            <Button
              onClick={async () => {
                if (!creds) return;
                try {
                  await navigator.clipboard.writeText(`${creds.id}\n${creds.password}`);
                  toast(t("vendor.creds.copied"));
                } catch {
                  /* clipboard unavailable: the values are on screen */
                }
              }}
            >
              {t("vendor.creds.copy")}
            </Button>
            <Button variant="primary" onClick={() => setCreds(null)}>
              {t("vendor.creds.close")}
            </Button>
          </>
        }
      >
        {creds && (
          <div className="space-y-3 text-sm">
            <Banner tone="warn">{t("vendor.creds.body")}</Banner>
            <dl className="grid grid-cols-1 gap-2 sm:grid-cols-2">
              <div>
                <dt className="text-xs text-ink-3">{t("vendor.creds.user")}</dt>
                <dd className="mt-0.5 text-base">
                  <Mono>{creds.id}</Mono>
                </dd>
              </div>
              <div>
                <dt className="text-xs text-ink-3">{t("vendor.creds.password")}</dt>
                <dd className="mt-0.5 text-base">
                  <Mono>{creds.password}</Mono>
                </dd>
              </div>
            </dl>
          </div>
        )}
      </Modal>
    </div>
  );
}

// ---------------------------------------------------------------- shared helpers

/** Runs an action, shows its result as a toast and reports failures the same way. */
function useAction() {
  const toast = useToast();
  const [busy, setBusy] = useState<string | null>(null);
  const run = async (key: string, fn: () => Promise<void>, ok?: string) => {
    setBusy(key);
    try {
      await fn();
      if (ok) toast(ok);
    } catch (e) {
      toast(e instanceof Error ? e.message : String(e), "error");
    } finally {
      setBusy(null);
    }
  };
  return { busy, run };
}

const optNum = (s: string): number | null => (s.trim() === "" ? null : Number(s));

function Summary({ ov }: { ov: Overview }) {
  const { t, date, num } = useT();
  const s = ov.state;
  const l = ov.license;
  const left =
    s.daysLeft === null ? null : s.daysLeft >= 0 ? t("vendor.sum.daysLeft", { n: num(s.daysLeft, 0) }) : t("vendor.sum.daysOver", { n: num(-s.daysLeft, 0) });
  const limit = (used: number, max: number | null | undefined) =>
    max === null || max === undefined ? `${num(used, 0)} (${t("vendor.sum.noLimit")})` : t("vendor.sum.of", { a: num(used, 0), b: num(max, 0) });
  return (
    <div className="space-y-3">
      <Banner tone={TONE[s.state]}>
        <strong>{t(`vendor.state.${s.state}`)}</strong>
        {s.message && <span className="ms-2 text-ink-2">{s.message}</span>}
      </Banner>
      <dl className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <Stat label={t("vendor.sum.company")} value={ov.company?.name ?? t("vendor.sum.none")} />
        <Stat label={t("vendor.sum.plan")} value={l ? t(`vendor.plan.${l.plan}`) : "—"} />
        <Stat label={t("vendor.sum.ends")} value={l ? (l.expiresAt ? date(l.expiresAt) : t("vendor.sum.never")) : "—"} sub={left ?? undefined} />
        <Stat
          label={`${t("vendor.sum.users")} / ${t("vendor.sum.projects")}`}
          value={limit(ov.usage.activeUsers, l?.maxUsers)}
          sub={limit(ov.usage.activeProjects, l?.maxActiveProjects)}
        />
      </dl>
    </div>
  );
}

function Stat({ label, value, sub }: { label: string; value: string; sub?: string }) {
  return (
    <div className="min-w-0 rounded-xl border border-line bg-surface p-3">
      <dt className="text-xs text-ink-2">{label}</dt>
      <dd className="mt-1 truncate text-base font-semibold">{value}</dd>
      {sub && <dd className="mt-0.5 truncate text-xs text-ink-3">{sub}</dd>}
    </div>
  );
}

// ---------------------------------------------------------------- company

function CompanyTab({ ov, reload }: { ov: Overview; reload: () => Promise<void> }) {
  const { t } = useT();
  const c = ov.company;
  const { busy, run } = useAction();
  const [f, setF] = useState({
    name: c?.name ?? "",
    legalName: c?.legalName ?? "",
    contactName: c?.contactName ?? "",
    contactPhone: c?.contactPhone ?? "",
    contactEmail: c?.contactEmail ?? "",
    notes: c?.notes ?? "",
  });
  const set = (k: keyof typeof f) => (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => setF({ ...f, [k]: e.target.value });

  return (
    <Card title={t("vendor.tab.company")}>
      <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
        <Field label={t("vendor.company.name")}>
          <input className={inputClass} value={f.name} onChange={set("name")} />
        </Field>
        <Field label={t("vendor.company.legalName")}>
          <input className={inputClass} value={f.legalName} onChange={set("legalName")} />
        </Field>
        <Field label={t("vendor.company.contactName")}>
          <input className={inputClass} value={f.contactName} onChange={set("contactName")} />
        </Field>
        <Field label={t("vendor.company.contactPhone")}>
          <input className={`${inputClass} ltr`} value={f.contactPhone} onChange={set("contactPhone")} />
        </Field>
        <Field label={t("vendor.company.contactEmail")}>
          <input className={`${inputClass} ltr`} value={f.contactEmail} onChange={set("contactEmail")} />
        </Field>
        {c && (
          <Field label={t("vendor.company.installationId")}>
            <input className={`${inputClass} ltr font-mono`} value={c.installationId} readOnly />
          </Field>
        )}
        <div className="md:col-span-2">
          <Field label={t("vendor.company.notes")}>
            <textarea className={inputClass} rows={3} value={f.notes} onChange={set("notes")} />
          </Field>
        </div>
      </div>
      <div className="mt-4">
        <Button
          variant="primary"
          busy={busy === "company"}
          disabled={f.name.trim() === ""}
          onClick={() =>
            run(
              "company",
              async () => {
                await api("/vendor/company", { method: "PUT", body: f });
                await reload();
              },
              t("vendor.company.saved"),
            )
          }
        >
          {t("vendor.company.save")}
        </Button>
      </div>
    </Card>
  );
}

// ---------------------------------------------------------------- license

function LicenseTab({ ov, reload }: { ov: Overview; reload: () => Promise<void> }) {
  const { t } = useT();
  const { busy, run } = useAction();
  const l = ov.license;

  const [plan, setPlan] = useState<Plan>(l?.plan ?? "UNLIMITED");
  const [startsAt, setStartsAt] = useState("");
  const [days, setDays] = useState("");
  const [endDate, setEndDate] = useState("");
  const [extendDays, setExtendDays] = useState("30");
  const [reason, setReason] = useState("");
  const [rules, setRules] = useState({
    graceDays: String(l?.graceDays ?? 0),
    maxUsers: l?.maxUsers === null || l?.maxUsers === undefined ? "" : String(l.maxUsers),
    maxActiveProjects: l?.maxActiveProjects === null || l?.maxActiveProjects === undefined ? "" : String(l.maxActiveProjects),
    allowLlm: l?.allowLlm ?? true,
    allowBale: l?.allowBale ?? true,
  });

  if (!l) return <Banner tone="info">{t("vendor.company.defineFirst")}</Banner>;

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
      <Card title={t("vendor.license.period")}>
        <div className="space-y-3">
          <Segmented<Plan>
            label={t("vendor.license.plan")}
            value={plan}
            onChange={setPlan}
            options={(["UNLIMITED", "YEARLY", "TRIAL", "CUSTOM"] as Plan[]).map((p) => ({ value: p, label: t(`vendor.plan.${p}`) }))}
          />
          {plan !== "UNLIMITED" && (
            <Field label={t("vendor.license.startsAt")}>
              <input type="date" className={`${inputClass} ltr`} value={startsAt} onChange={(e) => setStartsAt(e.target.value)} />
            </Field>
          )}
          {plan === "CUSTOM" && (
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <Field label={t("vendor.license.days")} hint={t("vendor.license.customHint")}>
                <input type="number" min={1} className={`${inputClass} ltr`} value={days} onChange={(e) => setDays(e.target.value)} />
              </Field>
              <Field label={t("vendor.license.endDate")}>
                <input type="date" className={`${inputClass} ltr`} value={endDate} onChange={(e) => setEndDate(e.target.value)} />
              </Field>
            </div>
          )}
          <Button
            variant="primary"
            busy={busy === "period"}
            disabled={plan === "CUSTOM" && days.trim() === "" && endDate === ""}
            onClick={() =>
              run(
                "period",
                async () => {
                  await api("/vendor/license/period", {
                    method: "PUT",
                    body: {
                      plan,
                      startsAt: plan !== "UNLIMITED" && startsAt ? startsAt : undefined,
                      days: plan === "CUSTOM" && days.trim() !== "" ? Number(days) : undefined,
                      expiresAt: plan === "CUSTOM" && endDate ? endDate : undefined,
                    },
                  });
                  await reload();
                },
                t("vendor.license.applied"),
              )
            }
          >
            {t("vendor.license.apply")}
          </Button>
        </div>
      </Card>

      <div className="space-y-4">
        {l.expiresAt !== null && (
          <Card title={t("vendor.license.extendTitle")}>
            <div className="flex flex-wrap items-end gap-3">
              <div className="w-40">
                <Field label={t("vendor.license.extendDays")}>
                  <input type="number" min={1} className={`${inputClass} ltr`} value={extendDays} onChange={(e) => setExtendDays(e.target.value)} />
                </Field>
              </div>
              <Button
                busy={busy === "extend"}
                disabled={Number(extendDays) < 1}
                onClick={() =>
                  run(
                    "extend",
                    async () => {
                      await api("/vendor/license/extend", { method: "POST", body: { days: Number(extendDays) } });
                      await reload();
                    },
                    t("vendor.license.extended"),
                  )
                }
              >
                {t("vendor.license.extend")}
              </Button>
            </div>
          </Card>
        )}

        <Card title={t("vendor.license.suspendTitle")}>
          {l.status === "SUSPENDED" ? (
            <div className="space-y-3">
              <Banner tone="error">{t("vendor.license.suspendedNow", { reason: l.suspendedReason ?? "" })}</Banner>
              <Button
                variant="primary"
                busy={busy === "resume"}
                onClick={() =>
                  run(
                    "resume",
                    async () => {
                      await api("/vendor/license/resume", { method: "POST" });
                      await reload();
                    },
                    t("vendor.license.resumed"),
                  )
                }
              >
                {t("vendor.license.resume")}
              </Button>
            </div>
          ) : (
            <div className="space-y-3">
              <Field label={t("vendor.license.suspendReason")}>
                <input className={inputClass} value={reason} onChange={(e) => setReason(e.target.value)} maxLength={500} />
              </Field>
              <Button
                variant="danger"
                busy={busy === "suspend"}
                disabled={reason.trim() === ""}
                onClick={() =>
                  run(
                    "suspend",
                    async () => {
                      await api("/vendor/license/suspend", { method: "POST", body: { reason } });
                      setReason("");
                      await reload();
                    },
                    t("vendor.license.suspended"),
                  )
                }
              >
                {t("vendor.license.suspend")}
              </Button>
            </div>
          )}
        </Card>
      </div>

      <div className="lg:col-span-2">
        <Card title={t("vendor.rules.title")}>
          <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
            <Field label={t("vendor.rules.grace")} hint={t("vendor.rules.graceHint")}>
              <input type="number" min={0} max={365} className={`${inputClass} ltr`} value={rules.graceDays} onChange={(e) => setRules({ ...rules, graceDays: e.target.value })} />
            </Field>
            <Field label={t("vendor.rules.maxUsers")} hint={t("vendor.rules.blank")}>
              <input type="number" min={1} className={`${inputClass} ltr`} value={rules.maxUsers} onChange={(e) => setRules({ ...rules, maxUsers: e.target.value })} />
            </Field>
            <Field label={t("vendor.rules.maxProjects")} hint={t("vendor.rules.blank")}>
              <input type="number" min={1} className={`${inputClass} ltr`} value={rules.maxActiveProjects} onChange={(e) => setRules({ ...rules, maxActiveProjects: e.target.value })} />
            </Field>
          </div>
          <div className="mt-3 flex flex-wrap gap-x-6 gap-y-2 text-sm">
            <label className="flex items-center gap-2">
              <input type="checkbox" checked={rules.allowLlm} onChange={(e) => setRules({ ...rules, allowLlm: e.target.checked })} />
              {t("vendor.rules.allowLlm")}
            </label>
            <label className="flex items-center gap-2">
              <input type="checkbox" checked={rules.allowBale} onChange={(e) => setRules({ ...rules, allowBale: e.target.checked })} />
              {t("vendor.rules.allowBale")}
            </label>
          </div>
          <div className="mt-4">
            <Button
              variant="primary"
              busy={busy === "rules"}
              onClick={() =>
                run(
                  "rules",
                  async () => {
                    await api("/vendor/license/rules", {
                      method: "PUT",
                      body: {
                        graceDays: Number(rules.graceDays || 0),
                        maxUsers: optNum(rules.maxUsers),
                        maxActiveProjects: optNum(rules.maxActiveProjects),
                        allowLlm: rules.allowLlm,
                        allowBale: rules.allowBale,
                      },
                    });
                    await reload();
                  },
                  t("vendor.rules.saved"),
                )
              }
            >
              {t("vendor.rules.save")}
            </Button>
          </div>
        </Card>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------- owner

function OwnerTab({ showCreds }: { showCreds: ShowCreds }) {
  const { t } = useT();
  const { busy, run } = useAction();
  const owner = useFetch<{ owner: VendorUser | null }>("/vendor/owner");
  const [id, setId] = useState("");
  const [fullName, setFullName] = useState("");
  const [password, setPassword] = useState("");
  const current = owner.data?.owner ?? null;

  // start the form from the current owner once it is known
  useEffect(() => {
    if (current) {
      setId((v) => v || current.id);
      setFullName((v) => v || current.fullName);
    }
  }, [current]);

  const handle = (r: { user: VendorUser; temporaryPassword: string | null }) => {
    if (r.temporaryPassword) showCreds({ id: r.user.id, password: r.temporaryPassword });
    setPassword("");
    return owner.reload();
  };

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
      <Card title={t("vendor.owner.title")}>
        <p className="mb-3 text-sm text-ink-2">{t("vendor.owner.hint")}</p>
        <p className="mb-4 text-sm">
          <span className="text-ink-3">{t("vendor.owner.current")}: </span>
          {current ? (
            <strong>
              {current.fullName} (<Mono>{current.id}</Mono>)
            </strong>
          ) : (
            t("vendor.owner.none")
          )}
        </p>
        <div className="space-y-3">
          <Field label={t("vendor.owner.id")}>
            <input className={`${inputClass} ltr`} value={id} onChange={(e) => setId(e.target.value)} autoComplete="off" />
          </Field>
          <Field label={t("vendor.owner.fullName")}>
            <input className={inputClass} value={fullName} onChange={(e) => setFullName(e.target.value)} />
          </Field>
          <Field label={t("vendor.owner.password")} hint={t("vendor.owner.demote")}>
            <input type="password" className={`${inputClass} ltr`} value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="new-password" />
          </Field>
          <div className="flex flex-wrap gap-2">
            <Button
              variant="primary"
              busy={busy === "owner"}
              disabled={id.trim().length < 3 || fullName.trim() === ""}
              onClick={() =>
                run(
                  "owner",
                  async () => {
                    const r = await api<{ user: VendorUser; temporaryPassword: string | null }>("/vendor/owner", {
                      method: "PUT",
                      body: { id: id.trim(), fullName, password: password || undefined },
                    });
                    await handle(r);
                  },
                  t("vendor.owner.saved"),
                )
              }
            >
              {t("vendor.owner.set")}
            </Button>
            {current && (
              <Button
                busy={busy === "reset"}
                onClick={() =>
                  run("reset", async () => {
                    const r = await api<{ user: VendorUser; temporaryPassword: string }>("/vendor/owner/reset-password", { method: "POST", body: {} });
                    await handle(r);
                  })
                }
              >
                {t("vendor.owner.reset")}
              </Button>
            )}
          </div>
        </div>
      </Card>
    </div>
  );
}

// ---------------------------------------------------------------- users

function UsersTab({ showCreds, onChange }: { showCreds: ShowCreds; onChange: () => Promise<void> }) {
  const { t } = useT();
  const { busy, run } = useAction();
  const users = useFetch<VendorUser[]>("/vendor/users");
  const [id, setId] = useState("");
  const [fullName, setFullName] = useState("");
  const [role, setRole] = useState("USER_1");
  const [password, setPassword] = useState("");

  const refresh = async () => {
    await Promise.all([users.reload(), onChange()]);
  };

  return (
    <div className="space-y-4">
      <Card title={t("vendor.users.add")}>
        <div className="grid grid-cols-1 gap-3 md:grid-cols-4">
          <Field label={t("vendor.users.id")}>
            <input className={`${inputClass} ltr`} value={id} onChange={(e) => setId(e.target.value)} autoComplete="off" />
          </Field>
          <Field label={t("vendor.users.name")}>
            <input className={inputClass} value={fullName} onChange={(e) => setFullName(e.target.value)} />
          </Field>
          <Field label={t("vendor.users.role")}>
            <select className={inputClass} value={role} onChange={(e) => setRole(e.target.value)}>
              {["MANAGER", "USER_1", "USER_2", "USER_3"].map((r) => (
                <option key={r} value={r}>
                  {t(`role.${r}`)}
                </option>
              ))}
            </select>
          </Field>
          <Field label={t("vendor.users.password")}>
            <input type="password" className={`${inputClass} ltr`} value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="new-password" />
          </Field>
        </div>
        <div className="mt-4">
          <Button
            variant="primary"
            busy={busy === "create"}
            disabled={id.trim().length < 3 || fullName.trim() === ""}
            onClick={() =>
              run(
                "create",
                async () => {
                  const r = await api<{ user: VendorUser; temporaryPassword: string }>("/vendor/users", {
                    method: "POST",
                    body: { id: id.trim(), fullName, role, password: password || undefined },
                  });
                  showCreds({ id: r.user.id, password: r.temporaryPassword });
                  setId("");
                  setFullName("");
                  setPassword("");
                  await refresh();
                },
                t("vendor.users.created"),
              )
            }
          >
            {t("vendor.users.create")}
          </Button>
        </div>
      </Card>

      <Card title={t("vendor.users.title")}>
        {users.error && <ErrorBox message={users.error} />}
        {users.loading ? (
          <Loading />
        ) : (users.data ?? []).length === 0 ? (
          <Empty>{t("common.none")}</Empty>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[560px] text-sm">
              <thead>
                <tr className="text-start text-xs text-ink-3">
                  <th className="py-2 text-start font-medium">{t("vendor.users.id")}</th>
                  <th className="py-2 text-start font-medium">{t("vendor.users.name")}</th>
                  <th className="py-2 text-start font-medium">{t("vendor.users.role")}</th>
                  <th className="py-2 text-start font-medium">{t("vendor.users.status")}</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {(users.data ?? []).map((u) => (
                  <tr key={u.id} className="border-t border-line">
                    <td className="py-2">
                      <Mono>{u.id}</Mono>
                    </td>
                    <td className="py-2">{u.fullName}</td>
                    <td className="py-2">{t(`role.${u.role}`)}</td>
                    <td className="py-2">{u.active ? t("vendor.users.active") : t("vendor.users.inactive")}</td>
                    <td className="py-2">
                      <div className="flex flex-wrap justify-end gap-1">
                        <Button
                          variant="ghost"
                          busy={busy === `pw-${u.id}`}
                          onClick={() =>
                            run(`pw-${u.id}`, async () => {
                              const r = await api<{ user: VendorUser; temporaryPassword: string }>(`/vendor/users/${encodeURIComponent(u.id)}/reset-password`, { method: "POST", body: {} });
                              showCreds({ id: r.user.id, password: r.temporaryPassword });
                            })
                          }
                        >
                          {t("vendor.users.resetPw")}
                        </Button>
                        <Button
                          variant="ghost"
                          busy={busy === `act-${u.id}`}
                          onClick={() =>
                            run(
                              `act-${u.id}`,
                              async () => {
                                await api(`/vendor/users/${encodeURIComponent(u.id)}`, { method: "PATCH", body: { active: !u.active } });
                                await refresh();
                              },
                              t("vendor.users.updated"),
                            )
                          }
                        >
                          {u.active ? t("vendor.users.deactivate") : t("vendor.users.activate")}
                        </Button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  );
}

// ---------------------------------------------------------------- starting settings

interface StartSettings {
  projectName: string | null;
  language: string;
  maxActiveTasksPerUser: number;
  requireCompletionApproval: boolean;
  messengerPlatform: string;
}

function SettingsTab() {
  const { t } = useT();
  const { busy, run } = useAction();
  const settings = useFetch<StartSettings>("/vendor/settings");
  const [f, setF] = useState<StartSettings | null>(null);

  useEffect(() => {
    if (settings.data) setF((cur) => cur ?? settings.data);
  }, [settings.data]);

  if (settings.error) return <ErrorBox message={settings.error} />;
  if (!f) return <Loading />;

  return (
    <Card title={t("vendor.settings.title")}>
      <p className="mb-3 text-sm text-ink-2">{t("vendor.settings.hint")}</p>
      <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
        <Field label={t("vendor.settings.projectName")}>
          <input className={inputClass} value={f.projectName ?? ""} onChange={(e) => setF({ ...f, projectName: e.target.value })} />
        </Field>
        <Field label={t("vendor.settings.language")}>
          <select className={inputClass} value={f.language} onChange={(e) => setF({ ...f, language: e.target.value })}>
            <option value="fa">فارسی</option>
            <option value="en">English</option>
          </select>
        </Field>
        <Field label={t("vendor.settings.maxTasks")}>
          <input type="number" min={1} max={50} className={`${inputClass} ltr`} value={f.maxActiveTasksPerUser} onChange={(e) => setF({ ...f, maxActiveTasksPerUser: Number(e.target.value) })} />
        </Field>
        <Field label={t("vendor.settings.messenger")}>
          <select className={inputClass} value={f.messengerPlatform} onChange={(e) => setF({ ...f, messengerPlatform: e.target.value })}>
            <option value="IN_APP">IN_APP</option>
            <option value="BALE">BALE</option>
          </select>
        </Field>
      </div>
      <label className="mt-3 flex items-center gap-2 text-sm">
        <input type="checkbox" checked={f.requireCompletionApproval} onChange={(e) => setF({ ...f, requireCompletionApproval: e.target.checked })} />
        {t("vendor.settings.approval")}
      </label>
      <div className="mt-4">
        <Button
          variant="primary"
          busy={busy === "settings"}
          onClick={() =>
            run(
              "settings",
              async () => {
                setF(await api<StartSettings>("/vendor/settings", { method: "PUT", body: f }));
              },
              t("vendor.settings.saved"),
            )
          }
        >
          {t("vendor.settings.save")}
        </Button>
      </div>
    </Card>
  );
}

// ---------------------------------------------------------------- activity log

function LogTab() {
  const { t, date } = useT();
  const events = useFetch<{ id: number; occurredAt: string; action: string; detail: string | null }[]>("/vendor/events");
  return (
    <Card title={t("vendor.log.title")}>
      <p className="mb-3 text-sm text-ink-2">{t("vendor.log.hint")}</p>
      {events.error && <ErrorBox message={events.error} />}
      {events.loading ? (
        <Loading />
      ) : (events.data ?? []).length === 0 ? (
        <Empty>{t("vendor.log.empty")}</Empty>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[480px] text-sm">
            <thead>
              <tr className="text-xs text-ink-3">
                <th className="py-2 text-start font-medium">{t("vendor.log.when")}</th>
                <th className="py-2 text-start font-medium">{t("vendor.log.action")}</th>
                <th className="py-2 text-start font-medium">{t("vendor.log.detail")}</th>
              </tr>
            </thead>
            <tbody>
              {(events.data ?? []).map((e) => (
                <tr key={e.id} className="border-t border-line">
                  <td className="whitespace-nowrap py-2 pe-3">{date(e.occurredAt)}</td>
                  <td className="py-2 pe-3">
                    <Mono>{e.action}</Mono>
                  </td>
                  <td className="py-2 text-ink-2">{e.detail ?? "—"}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Card>
  );
}

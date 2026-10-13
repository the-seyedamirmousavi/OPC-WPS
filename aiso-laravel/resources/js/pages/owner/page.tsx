import { Link } from "react-router-dom";
import { AiApprovals } from "@/components/AiApprovals";
import { StatusBar } from "@/components/StatusBar";
import { Banner, Card, Empty, ErrorBox, Kpi, Loading, Mono, PageHeader } from "@/components/ui";
import { useT } from "@/components/Providers";
import { useFetch } from "@/lib/api";
import { roleLabel } from "@/lib/capabilities";
import type { DepartmentSummary, OwnerDashboard } from "@/lib/types";

interface Alert {
  key: string;
  text: string;
  /** where to deal with it; null when no department can take it yet */
  to: string | null;
}

/**
 * The owner's dashboard: a summary of the business and of every department, what needs attention, and a way into each
 * department's workspace. The work itself is in the workspaces; the system-wide controls are in System administration
 * (account menu).
 */
export default function OwnerDashboardPage() {
  const { t, num, date, percent } = useT();
  const dash = useFetch<OwnerDashboard>("/dashboard/owner", 20000);
  const depts = useFetch<DepartmentSummary[]>("/departments", 30000);

  if (dash.loading || depts.loading) return <Loading />;
  if (dash.error || depts.error || !dash.data || !depts.data) {
    return <ErrorBox message={dash.error ?? depts.error ?? t("common.error")} />;
  }
  const d = dash.data;
  const list = depts.data;
  const hr = list.find((x) => x.code === "HR");
  const production = list.find((x) => x.code === "PRODUCTION");
  const suspended = d.systemStatus === "SUSPENDED";

  const workspace = (dep: DepartmentSummary | undefined, tab?: string) =>
    dep ? `/departments/${dep.id}${tab ? `?tab=${tab}` : ""}` : null;

  // only what the system really reports
  const alerts: Alert[] = [];
  if (suspended) alerts.push({ key: "suspended", text: t("owner.attention.suspended"), to: "/owner/system" });
  if (d.failedNotifications > 0) {
    alerts.push({ key: "failed", text: t("owner.attention.failed", { n: d.failedNotifications }), to: workspace(production, "reports") });
  }
  if (d.statusCounts.BLOCKED > 0) {
    alerts.push({ key: "blocked", text: t("owner.attention.blocked", { n: d.statusCounts.BLOCKED }), to: workspace(production, "operations") });
  }
  for (const dep of list) {
    if (dep.staleResourceCount > 0) {
      alerts.push({ key: `stale-${dep.id}`, text: t("owner.attention.stale", { dept: dep.name, n: dep.staleResourceCount }), to: workspace(dep) });
    }
    if (dep.pendingSuggestions > 0) {
      alerts.push({ key: `sug-${dep.id}`, text: t("owner.attention.suggestions", { dept: dep.name, n: dep.pendingSuggestions }), to: workspace(dep) });
    }
  }

  return (
    <>
      <PageHeader
        title={t("owner.dash.title")}
        sub={d.projectName ? `${t("owner.dash.sub")} · ${d.projectName}` : t("owner.dash.sub")}
      />

      {suspended && (
        <div className="mb-4">
          <Banner tone="warn">{t("owner.suspendedBanner")}</Banner>
        </div>
      )}

      <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4">
        <Kpi label={t("owner.systemStatus")} value={suspended ? t("owner.statusSuspended") : t("owner.statusActive")} tone={suspended ? "bad" : "good"} />
        <Kpi label={t("kpi.progress")} value={percent(d.progress.progressPercent)} sub={`${num(d.progress.totalOperations, 0)} ${t("owner.operations")}`} />
        <Kpi label={t("owner.failedMessages")} value={num(d.failedNotifications, 0)} tone={d.failedNotifications > 0 ? "bad" : undefined} sub={t("owner.failedSub")} />
        <Kpi label={t("owner.activeUsers")} value={num(d.activeUsers, 0)} />
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <div className="lg:col-span-2">
          <AiApprovals />
        </div>

        <Card title={t("owner.attention")}>
          {alerts.length === 0 ? (
            <Empty>{t("owner.attention.none")}</Empty>
          ) : (
            <ul className="divide-y divide-line text-sm">
              {alerts.map((a) => (
                <li key={a.key} className="flex items-center justify-between gap-3 py-2">
                  <span>{a.text}</span>
                  {a.to && (
                    <Link to={a.to} className="shrink-0 text-accent hover:underline">
                      {t("owner.seeAll")}
                    </Link>
                  )}
                </li>
              ))}
            </ul>
          )}
        </Card>

        <Card title={t("owner.deptSummary")}>
          {list.length === 0 ? (
            <Empty>{t("dept.empty")}</Empty>
          ) : (
            <ul className="divide-y divide-line text-sm">
              {list.map((dep) => (
                <li key={dep.id} className="flex items-center justify-between gap-3 py-2">
                  <div className="min-w-0">
                    <div className="truncate font-medium">{dep.name}</div>
                    <div className="text-xs text-ink-3">
                      {t("dept.kpi.members")}: {num(dep.memberCount, 0)} · {t("dept.kpi.resources")}: {num(dep.resourceCount, 0)}
                      {dep.staleResourceCount > 0 && <span className="text-bad"> · {t("dept.kpi.stale")}: {num(dep.staleResourceCount, 0)}</span>}
                    </div>
                  </div>
                  <Link to={`/departments/${dep.id}`} className="shrink-0 text-accent hover:underline">
                    {t("dept.dir.open")}
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </Card>

        <Card title={t("chart.statusDistribution")}>
          <StatusBar counts={d.statusCounts} />
        </Card>

        <Card
          title={t("owner.users")}
          action={hr && <Link to={`/departments/${hr.id}?tab=users`} className="text-sm text-accent hover:underline">{t("owner.users.manageInHr")}</Link>}
        >
          <ul className="grid grid-cols-2 gap-2 text-sm">
            {Object.keys(d.usersByRole).map((r) => (
              <li key={r} className="flex justify-between rounded-lg border border-line px-3 py-2">
                <span>{roleLabel(t, r, d.roleNames?.[r])}</span>
                <span className="tabular-nums">{num(d.usersByRole[r] ?? 0, 0)}</span>
              </li>
            ))}
          </ul>
          <p className="mt-2 text-xs text-ink-3">
            {t("owner.activeUsers")}: {num(d.activeUsers, 0)}
          </p>
        </Card>

        <Card
          title={t("owner.recentImports")}
          action={production && <Link to={`/departments/${production.id}?tab=imports`} className="text-sm text-accent hover:underline">{t("owner.seeAll")}</Link>}
        >
          {d.recentImports.length === 0 ? (
            <Empty>{t("common.none")}</Empty>
          ) : (
            <ul className="divide-y divide-line text-sm">
              {d.recentImports.map((i) => (
                <li key={i.id} className="py-2">
                  <div className="flex justify-between gap-2">
                    <span className="truncate">{i.fileName}</span>
                    <span className={i.status === "REJECTED" ? "text-bad" : ""}>{t(`imp.status.${i.status}`)}</span>
                  </div>
                  <div className="text-xs text-ink-3">
                    {date(i.importedAt)} · {i.actorId}
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Card>

        <Card
          title={t("owner.recentAudit")}
          action={hr && <Link to={`/departments/${hr.id}?tab=audit`} className="text-sm text-accent hover:underline">{t("owner.seeAll")}</Link>}
        >
          <ul className="divide-y divide-line text-sm">
            {d.recentAudit.map((a) => (
              <li key={a.eventId} className="py-1.5">
                <span className="font-medium">{(() => { const v = t(`audit.action.${a.action}`); return v === `audit.action.${a.action}` ? a.action : v; })()}</span> <Mono>{a.entityId}</Mono>
                <div className="text-xs text-ink-3">
                  {a.actorId} · {date(a.occurredAt)}
                </div>
              </li>
            ))}
          </ul>
        </Card>
      </div>
    </>
  );
}

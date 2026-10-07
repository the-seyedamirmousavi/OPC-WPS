"use client";

import Link from "next/link";
import { useState } from "react";
import { OperationDetailModal } from "@/components/OperationDetailModal";
import { OperationTable } from "@/components/OperationTable";
import { StatusBar } from "@/components/StatusBar";
import { Banner, Card, Empty, ErrorBox, Kpi, Loading, Mono, PageHeader, ProgressBar, StatusBadge } from "@/components/ui";
import { useT } from "@/components/Providers";
import { useFetch } from "@/lib/api";
import type { ManagerDashboard, Operation } from "@/lib/types";

export default function ManagerOverview() {
  const { t, num, hours, date, percent } = useT();
  const { data, error, loading, reload } = useFetch<ManagerDashboard>("/dashboard/manager", 15000);
  const [open, setOpen] = useState<string | null>(null);

  if (loading) return <Loading />;
  if (error || !data) return <ErrorBox message={error ?? t("common.error")} />;
  const p = data.progress;
  const maxLoad = Math.max(1, ...data.users.map((u) => u.loadHours));

  return (
    <>
      <PageHeader
        title={t("mgr.title")}
        sub={t("mgr.sub")}
        actions={
          <Link href="/manager/assignment" className="rounded-lg bg-accent px-3.5 py-2 text-sm font-medium text-accent-ink hover:brightness-110">
            {t("mgr.goAssign")}
            {data.pendingProposals > 0 && ` (${num(data.pendingProposals, 0)})`}
          </Link>
        }
      />

      {p.totalOperations === 0 && (
        <div className="mb-4">
          <Banner tone="info">
            {t("mgr.noData")} <Link href="/import" className="font-medium text-accent underline">{t("nav.import")}</Link>
          </Banner>
        </div>
      )}

      <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        <Kpi label={t("kpi.progress")} value={percent(p.progressPercent)} sub={`${hours(p.completedHours)} / ${hours(p.totalHours)}`} />
        <Kpi label={t("kpi.projectedEnd")} value={<span className="text-base">{date(p.projectedEnd)}</span>} sub={`${t("kpi.criticalRemaining")}: ${hours(p.remainingCriticalHours)}`} />
        <Kpi label={t("kpi.ready")} value={num(data.statusCounts.READY, 0)} sub={t("kpi.readySub")} />
        <Kpi label={t("kpi.inProgress")} value={num(data.statusCounts.IN_PROGRESS + data.statusCounts.ASSIGNED, 0)} />
        <Kpi label={t("kpi.blocked")} value={num(data.statusCounts.BLOCKED, 0)} tone={data.statusCounts.BLOCKED > 0 ? "bad" : undefined} />
        <Kpi label={t("kpi.delayed")} value={num(data.delayed.length, 0)} tone={data.delayed.length > 0 ? "bad" : undefined} />
      </div>

      {data.projects.filter((p) => p.status === "ACTIVE").length > 0 && (
        <Card
          title={t("dash.projects")}
          className="mb-4"
          action={
            <Link href="/manager/projects" className="text-sm text-accent hover:underline">
              {t("dash.managePriorities")}
            </Link>
          }
        >
          <div className="overflow-x-auto">
            <table className="w-full min-w-[640px] text-sm">
              <thead>
                <tr className="border-b border-line text-xs text-ink-2">
                  <th className="px-2 py-2 text-start font-medium">{t("prio.rank")}</th>
                  <th className="px-2 py-2 text-start font-medium">{t("proj.name")}</th>
                  <th className="w-40 px-2 py-2 text-start font-medium">{t("op.progress")}</th>
                  <th className="px-2 py-2 text-start font-medium">{t("proj.finish")}</th>
                  <th className="px-2 py-2 text-start font-medium">{t("proj.tardiness")}</th>
                </tr>
              </thead>
              <tbody>
                {data.projects.filter((p) => p.status === "ACTIVE").map((p) => (
                  <tr key={p.id} className="border-b border-line last:border-0">
                    <td className="px-2 py-2 tabular-nums">{num(p.priority, 0)}</td>
                    <td className="px-2 py-2">{p.name}</td>
                    <td className="px-2 py-2">
                      <div className="flex items-center gap-2">
                        <div className="w-24"><ProgressBar value={p.progressPercent} label={t("op.progress")} /></div>
                        <span className="text-xs tabular-nums">{percent(p.progressPercent)}</span>
                      </div>
                    </td>
                    <td className="px-2 py-2 text-xs">{p.finishAt ? date(p.finishAt) : "—"}</td>
                    <td className="px-2 py-2 tabular-nums">{p.tardinessHours > 0 ? <span className="text-bad">⚠ {hours(p.tardinessHours)}</span> : "—"}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <p className="mt-3 text-xs text-ink-3">
            {t("impact.waste")}: <strong>{hours(data.schedule.wasteHours)}</strong> · {t("impact.utilization")}: <strong>{percent(data.schedule.utilizationPercent)}</strong> · {t("impact.naive")}: {hours(data.naiveSchedule.wasteHours)}
          </p>
        </Card>
      )}

      <div className="grid gap-4 lg:grid-cols-3">
        <Card title={t("chart.statusDistribution")} className="lg:col-span-2">
          <StatusBar counts={data.statusCounts} />
          <div className="mt-4">
            <div className="mb-1 flex justify-between text-xs text-ink-2">
              <span>{t("kpi.progress")}</span>
              <span className="tabular-nums">{percent(p.progressPercent)}</span>
            </div>
            <ProgressBar value={p.progressPercent} label={t("kpi.progress")} />
            <p className="mt-2 text-xs text-ink-3">{t("kpi.progressFormula")}</p>
          </div>
        </Card>

        <Card title={t("mgr.mode")}>
          <p className="text-sm">
            {data.assignmentMode === "LLM" ? t("mode.LLM") : t("mode.ALGORITHM")}
            {data.assignmentMode === "LLM" && <span className="ms-2 text-xs text-ink-3"><Mono>{data.llmModel}</Mono></span>}
          </p>
          {data.assignmentMode === "LLM" && !data.llmAvailable && (
            <div className="mt-2">
              <Banner tone="warn">{t("mode.noKey")}</Banner>
            </div>
          )}
          <p className="mt-3 text-xs text-ink-3">{t("mode.hint")}</p>
        </Card>

        <Card title={t("mgr.critical")} className="lg:col-span-2">
          <p className="mb-2 text-xs text-ink-3">{t("mgr.criticalHint")}</p>
          <ul className="divide-y divide-line">
            {data.criticalPath.length === 0 && <Empty>{t("common.none")}</Empty>}
            {data.criticalPath.map((o) => (
              <li key={o.id}>
                <button onClick={() => setOpen(o.id)} className="flex w-full items-center justify-between gap-3 py-2 text-start hover:bg-surface-2">
                  <span className="min-w-0 truncate">
                    <Mono>{o.id}</Mono> <span className="text-ink-2">{o.name}</span>
                  </span>
                  <span className="flex shrink-0 items-center gap-3">
                    <span className="text-xs tabular-nums text-ink-2">{hours(o.tailHours)}</span>
                    <StatusBadge status={o.status} />
                  </span>
                </button>
              </li>
            ))}
          </ul>
        </Card>

        <Card title={t("mgr.resources")}>
          <ul className="space-y-3">
            {data.resources.map((r) => (
              <li key={r.id}>
                <div className="flex items-center justify-between text-sm">
                  <span className="truncate">{r.name}</span>
                  <span className="text-xs text-ink-3">
                    {t("res.waiting")}: {num(r.readyWaiting, 0)}
                  </span>
                </div>
                <div className="mt-1 flex gap-1" title={`${num(r.busy, 0)} / ${num(r.capacity, 0)}`} aria-label={`${r.busy}/${r.capacity}`}>
                  {Array.from({ length: Math.max(r.capacity, r.busy) }, (_, i) => (
                    <span key={i} className={`h-2.5 w-6 rounded-sm ${i < r.busy ? "bg-[var(--s-progress)]" : "bg-surface-2 ring-1 ring-line-strong"}`} />
                  ))}
                  <span className="ms-1 text-xs tabular-nums text-ink-2">
                    {num(r.busy, 0)}/{num(r.capacity, 0)}
                  </span>
                </div>
              </li>
            ))}
            {data.resources.length === 0 && <Empty>{t("common.none")}</Empty>}
          </ul>
        </Card>

        <Card title={t("mgr.workload")} className="lg:col-span-2">
          <ul className="space-y-3">
            {data.users.map((u) => (
              <li key={u.userId}>
                <div className="flex items-baseline justify-between gap-2 text-sm">
                  <span>{u.name}</span>
                  <span className="text-xs text-ink-2">
                    {t("user.assigned")} {num(u.assigned, 0)} · {t("status.IN_PROGRESS")} {num(u.inProgress, 0)} · {t("status.BLOCKED")} {num(u.blocked, 0)} · {t("status.COMPLETED")} {num(u.completed, 0)}
                  </span>
                </div>
                <div className="mt-1 h-2 rounded-full bg-surface-2" title={hours(u.loadHours)}>
                  <div className="h-full rounded-full bg-accent" style={{ width: `${(u.loadHours / maxLoad) * 100}%` }} />
                </div>
                <div className="mt-0.5 text-xs text-ink-3">
                  {t("user.loadHours")}: {hours(u.loadHours)}
                </div>
              </li>
            ))}
          </ul>
        </Card>

        <Card title={t("mgr.recent")}>
          <ul className="space-y-2">
            {data.recentReports.length === 0 && <Empty>{t("common.none")}</Empty>}
            {data.recentReports.slice(0, 8).map((r) => (
              <li key={r.id} className="text-sm">
                <span className="font-medium">{t(`report.${r.type}`)}</span> <span className="text-ink-2">{r.userName ?? r.userId}</span>
                {r.note && <div className="truncate text-xs text-ink-3">{r.note}</div>}
                <div className="text-xs text-ink-3">{date(r.createdAt)}</div>
              </li>
            ))}
          </ul>
        </Card>
      </div>

      <div className="mt-4 grid gap-4">
        <ListCard title={t("mgr.blockedList")} ops={data.blocked} onOpen={setOpen} />
        <ListCard title={t("mgr.delayedList")} ops={data.delayed} onOpen={setOpen} />
        <ListCard title={t("mgr.readyList")} ops={data.ready} onOpen={setOpen} />
      </div>

      <OperationDetailModal id={open} mode="manage" onClose={() => setOpen(null)} onChanged={reload} />
    </>
  );
}

function ListCard({ title, ops, onOpen }: { title: string; ops: Operation[]; onOpen: (id: string) => void }) {
  const { num } = useT();
  return (
    <Card title={`${title} (${num(ops.length, 0)})`}>
      <OperationTable ops={ops} onOpen={onOpen} />
    </Card>
  );
}

"use client";

import { useState } from "react";
import { OperationDetailModal } from "@/components/OperationDetailModal";
import { Card, Empty, ErrorBox, Kpi, Loading, Mono, PageHeader, ProgressBar, StatusBadge } from "@/components/ui";
import { useT } from "@/components/Providers";
import { useFetch } from "@/lib/api";
import type { Operation, UserDashboard } from "@/lib/types";

export default function MyTasksPage() {
  const { t, num, hours, date, percent } = useT();
  const { data, error, loading, reload } = useFetch<UserDashboard>("/dashboard/user", 15000);
  const [open, setOpen] = useState<string | null>(null);

  if (loading) return <Loading />;
  if (error || !data) return <ErrorBox message={error ?? t("common.error")} />;
  const p = data.performance;

  return (
    <>
      <PageHeader title={t("my.title", { name: data.name })} sub={t("my.sub")} />

      <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4">
        <Kpi label={t("my.toStart")} value={num(data.statusCounts.ASSIGNED, 0)} />
        <Kpi label={t("status.IN_PROGRESS")} value={num(data.statusCounts.IN_PROGRESS, 0)} />
        <Kpi label={t("status.BLOCKED")} value={num(data.statusCounts.BLOCKED, 0)} tone={data.statusCounts.BLOCKED > 0 ? "bad" : undefined} />
        <Kpi label={t("status.COMPLETED")} value={num(data.statusCounts.COMPLETED, 0)} sub={hours(p.completedHours)} />
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <div className="space-y-3 lg:col-span-2">
          <h2 className="text-sm font-semibold">{t("my.active")}</h2>
          {data.activeTasks.length === 0 && (
            <Card>
              <Empty>{t("my.noTasks")}</Empty>
            </Card>
          )}
          {data.activeTasks.map((o) => (
            <TaskCard key={o.id} o={o} onOpen={() => setOpen(o.id)} />
          ))}

          {data.recentCompleted.length > 0 && (
            <>
              <h2 className="pt-2 text-sm font-semibold">{t("my.recentDone")}</h2>
              <Card>
                <ul className="divide-y divide-line text-sm">
                  {data.recentCompleted.map((o) => (
                    <li key={o.id} className="flex items-center justify-between gap-3 py-2">
                      <span className="min-w-0 truncate">
                        <Mono>{o.id}</Mono> <span className="text-ink-2">{o.name}</span>
                      </span>
                      <span className="shrink-0 text-xs text-ink-3">
                        {o.late && <span className="me-2 text-bad">⚠ {t("op.late")}</span>}
                        {date(o.completedAt)}
                        {o.completionApproved && <span className="ms-2 text-good-ink">✓</span>}
                      </span>
                    </li>
                  ))}
                </ul>
              </Card>
            </>
          )}
        </div>

        <div className="space-y-4">
          <Card title={t("my.performance")}>
            <dl className="space-y-2 text-sm">
              <Row k={t("rep.onTime")} v={p.onTimeRate === null ? "—" : percent(p.onTimeRate * 100)} />
              <Row k={t("rep.actualVsPlanned")} v={p.actualToPlanned === null ? "—" : num(p.actualToPlanned, 2)} />
              <Row k={t("my.late")} v={num(p.lateCount, 0)} />
              <Row k={t("rep.blockReports")} v={num(p.blockReports, 0)} />
            </dl>
          </Card>
          <Card title={t("notif.title")}>
            <ul className="space-y-2">
              {data.notifications.length === 0 && <Empty>{t("notif.none")}</Empty>}
              {data.notifications.slice(0, 8).map((n) => (
                <li key={n.id} className={`text-sm ${n.readAt ? "text-ink-2" : "font-medium"}`}>
                  {n.message}
                  <div className="text-xs font-normal text-ink-3">{date(n.createdAt)}</div>
                </li>
              ))}
            </ul>
          </Card>
        </div>
      </div>

      <OperationDetailModal id={open} mode="execute" onClose={() => setOpen(null)} onChanged={reload} />
    </>
  );
}

function Row({ k, v }: { k: string; v: string }) {
  return (
    <div className="flex justify-between gap-2 border-b border-line py-1 last:border-0">
      <dt className="text-ink-2">{k}</dt>
      <dd className="tabular-nums">{v}</dd>
    </div>
  );
}

function TaskCard({ o, onOpen }: { o: Operation; onOpen: () => void }) {
  const { t, hours, date } = useT();
  return (
    <button onClick={onOpen} className="block w-full rounded-xl border border-line bg-surface p-4 text-start hover:border-accent">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="min-w-0">
          <Mono>{o.id}</Mono>
          <div className="truncate font-medium">{o.name}</div>
        </div>
        <div className="flex items-center gap-2">
          <StatusBadge status={o.status} />
          {o.delayed && <span className="rounded bg-bad-soft px-1.5 py-0.5 text-xs font-medium text-bad">⚠ {t("op.delayed")}</span>}
        </div>
      </div>
      <div className="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-xs text-ink-2">
        <span>{o.projectName}</span>
        <span>{o.resourceName}</span>
        <span>{hours(o.totalHours)}</span>
        <span>
          {t("op.due")}: {date(o.plannedEnd)}
        </span>
      </div>
      {o.status === "IN_PROGRESS" && (
        <div className="mt-3">
          <ProgressBar value={o.progressPercent} label={t("op.progress")} />
        </div>
      )}
      {o.status === "BLOCKED" && o.blockReason && <div className="mt-2 text-xs text-bad">{o.blockReason}</div>}
    </button>
  );
}

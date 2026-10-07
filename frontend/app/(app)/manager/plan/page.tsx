"use client";

import { useMemo, useState } from "react";
import { Gantt } from "@/components/Gantt";
import { OperationDetailModal } from "@/components/OperationDetailModal";
import { Card, ErrorBox, Kpi, Loading, PageHeader, inputClass } from "@/components/ui";
import { useT } from "@/components/Providers";
import { useFetch } from "@/lib/api";
import type { ManagerDashboard, Operation } from "@/lib/types";

export default function PlanPage() {
  const { t, hours, date } = useT();
  const ops = useFetch<Operation[]>("/operations", 30000);
  const dash = useFetch<ManagerDashboard>("/dashboard/manager", 30000);
  const [open, setOpen] = useState<string | null>(null);
  const [project, setProject] = useState("");
  const projectList = useMemo(() => {
    const m = new Map<string, string>();
    ops.data?.forEach((o) => m.set(o.projectId, o.projectName));
    return [...m.entries()];
  }, [ops.data]);

  if (ops.loading || dash.loading) return <Loading />;
  if (ops.error || dash.error || !ops.data || !dash.data) return <ErrorBox message={ops.error ?? dash.error ?? t("common.error")} />;

  const capacities = Object.fromEntries(dash.data.resources.map((r) => [r.id, r.capacity]));
  const p = dash.data.progress;

  return (
    <>
      <PageHeader title={t("plan.title")} sub={t("plan.sub")} />
      <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-3">
        <Kpi label={t("kpi.projectedEnd")} value={<span className="text-base">{date(p.projectedEnd)}</span>} />
        <Kpi label={t("kpi.criticalRemaining")} value={hours(p.remainingCriticalHours)} sub={t("plan.continuousClock")} />
        <Kpi label={t("kpi.progress")} value={`${Math.round(p.progressPercent)}%`} />
      </div>
      <Card title={t("plan.timeline")}>
        <select className={`${inputClass} mb-3 max-w-xs`} aria-label={t("op.project")} value={project} onChange={(e) => setProject(e.target.value)}>
          <option value="">{t("ops.allProjects")}</option>
          {projectList.map(([id, name]) => (
            <option key={id} value={id}>
              {name}
            </option>
          ))}
        </select>
        <Gantt ops={project ? ops.data.filter((o) => o.projectId === project) : ops.data} capacities={capacities} onOpen={setOpen} />
        <p className="mt-3 text-xs text-ink-3">{t("plan.note")}</p>
      </Card>
      <OperationDetailModal id={open} mode="manage" onClose={() => setOpen(null)} onChanged={() => { ops.reload(); dash.reload(); }} />
    </>
  );
}

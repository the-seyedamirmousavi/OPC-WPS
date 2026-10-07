"use client";

import { Banner, Card, Empty, Kpi, ProgressBar } from "@/components/ui";
import { useT } from "@/components/Providers";
import type { Impact, PlanMetrics } from "@/lib/types";

/** What a ranking (or a newly imported project) does to every project's finish and to wasted resource time. */
export function ImpactPanel({ impact }: { impact: Impact }) {
  const { t, num, hours, percent, date } = useT();
  const p = impact.proposed;
  const sameAsNow = impact.deltas.every((d) => d.deltaHours === null || Math.abs(d.deltaHours) < 0.01);

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <Kpi label={t("impact.finish")} value={<span className="text-base">{date(p.finishAt)}</span>} sub={hours(p.makespanHours)} />
        <Kpi
          label={t("impact.waste")}
          value={hours(p.wasteHours)}
          sub={`${t("impact.now")}: ${hours(impact.current.wasteHours)}`}
        />
        <Kpi label={t("impact.utilization")} value={percent(p.utilizationPercent)} sub={`${t("impact.now")}: ${percent(impact.current.utilizationPercent)}`} />
        <Kpi
          label={t("impact.vsNaive")}
          value={impact.wasteSavedVsNaive > 0.005 ? `−${hours(impact.wasteSavedVsNaive)}` : "0"}
          tone={impact.wasteSavedVsNaive > 0.005 ? "good" : undefined}
          sub={t("impact.vsNaiveSub", { n: num(p.candidatesTried, 0) })}
        />
      </div>

      <Card title={t("impact.projects")}>
        {impact.deltas.length === 0 ? (
          <Empty>{t("common.none")}</Empty>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[560px] text-sm">
              <thead>
                <tr className="border-b border-line text-xs text-ink-2">
                  <th className="px-2 py-2 text-start font-medium">{t("prio.rank")}</th>
                  <th className="px-2 py-2 text-start font-medium">{t("proj.name")}</th>
                  <th className="px-2 py-2 text-start font-medium">{t("impact.before")}</th>
                  <th className="px-2 py-2 text-start font-medium">{t("impact.after")}</th>
                  <th className="px-2 py-2 text-start font-medium">{t("impact.change")}</th>
                  <th className="px-2 py-2 text-start font-medium">{t("proj.tardiness")}</th>
                </tr>
              </thead>
              <tbody>
                {impact.deltas.map((d) => {
                  const plan = p.projects.find((x) => x.projectId === d.projectId);
                  return (
                    <tr key={d.projectId} className="border-b border-line last:border-0">
                      <td className="px-2 py-2 tabular-nums">
                        {d.currentRank === null ? "—" : num(d.currentRank, 0)} → <strong>{num(d.proposedRank, 0)}</strong>
                      </td>
                      <td className="px-2 py-2">{d.name}</td>
                      <td className="px-2 py-2 tabular-nums">{d.currentFinishHours === null ? t("impact.new") : hours(d.currentFinishHours)}</td>
                      <td className="px-2 py-2 tabular-nums">{hours(d.proposedFinishHours)}</td>
                      <td className="px-2 py-2 tabular-nums">
                        <Delta value={d.deltaHours} />
                      </td>
                      <td className="px-2 py-2 tabular-nums">
                        {plan && plan.tardinessHours > 0 ? <span className="text-bad">⚠ {hours(plan.tardinessHours)}</span> : <span className="text-ink-3">—</span>}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
        {sameAsNow && <p className="mt-3 text-xs text-ink-3">{t("impact.noChange")}</p>}
      </Card>

      <Card title={t("impact.resources")}>
        <ResourceTable metrics={p} />
        <div className="mt-3">
          <Banner tone="info">{t("impact.wasteDef")}</Banner>
        </div>
      </Card>
    </div>
  );
}

function Delta({ value }: { value: number | null }) {
  const { t, hours } = useT();
  if (value === null) return <span className="text-ink-3">—</span>;
  if (Math.abs(value) < 0.01) return <span className="text-ink-3">{t("impact.same")}</span>;
  return value > 0 ? (
    <span className="text-bad">▲ {hours(value)} {t("impact.later")}</span>
  ) : (
    <span className="text-good-ink">▼ {hours(-value)} {t("impact.earlier")}</span>
  );
}

export function ResourceTable({ metrics }: { metrics: PlanMetrics }) {
  const { t, hours, percent } = useT();
  if (metrics.resources.length === 0) return <Empty>{t("common.none")}</Empty>;
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[520px] text-sm">
        <thead>
          <tr className="border-b border-line text-xs text-ink-2">
            <th className="px-2 py-2 text-start font-medium">{t("op.resource")}</th>
            <th className="px-2 py-2 text-start font-medium">{t("res.capacity")}</th>
            <th className="px-2 py-2 text-start font-medium">{t("impact.busy")}</th>
            <th className="px-2 py-2 text-start font-medium">{t("impact.waste")}</th>
            <th className="w-40 px-2 py-2 text-start font-medium">{t("impact.utilization")}</th>
          </tr>
        </thead>
        <tbody>
          {metrics.resources.map((r) => (
            <tr key={r.resourceId} className="border-b border-line last:border-0">
              <td className="px-2 py-2">{r.name}</td>
              <td className="px-2 py-2 tabular-nums">{r.capacity}</td>
              <td className="px-2 py-2 tabular-nums">{hours(r.busyHours)}</td>
              <td className="px-2 py-2 tabular-nums">{hours(r.wasteHours)}</td>
              <td className="px-2 py-2">
                <div className="flex items-center gap-2">
                  <div className="w-24">
                    <ProgressBar value={r.utilizationPercent} label={t("impact.utilization")} />
                  </div>
                  <span className="text-xs tabular-nums">{percent(r.utilizationPercent)}</span>
                </div>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

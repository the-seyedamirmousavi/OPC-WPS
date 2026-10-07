"use client";

import { useMemo, useState } from "react";
import { ImpactPanel, ResourceTable } from "@/components/ImpactPanel";
import { RankingEditor } from "@/components/RankingEditor";
import { DateField } from "@/components/DateField";
import { Banner, Button, Card, Empty, ErrorBox, Field, Kpi, Loading, Mono, PageHeader, ProgressBar } from "@/components/ui";
import { useT, useToast } from "@/components/Providers";
import { api, useFetch } from "@/lib/api";
import { isoToJalaliText } from "@/lib/jalali";
import type { Impact, ProjectSummary, SchedulingOverview } from "@/lib/types";

function dateText(iso: string | null, lang: string) {
  if (!iso) return "";
  if (lang === "fa") return isoToJalaliText(iso);
  return new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Tehran", year: "numeric", month: "2-digit", day: "2-digit" }).format(new Date(iso));
}

export default function ProjectsPage() {
  const { t, lang, num, hours, percent, date } = useT();
  const toast = useToast();
  const projects = useFetch<ProjectSummary[]>("/projects", 20000);
  const overview = useFetch<SchedulingOverview>("/scheduling/overview", 20000);
  const [order, setOrder] = useState<string[] | null>(null);
  const [dues, setDues] = useState<Record<string, string>>({});
  const [impact, setImpact] = useState<Impact | null>(null);
  const [busy, setBusy] = useState<string | null>(null);

  const active = useMemo(() => (projects.data ?? []).filter((p) => p.status === "ACTIVE"), [projects.data]);
  const current = useMemo(() => active.map((p) => p.id), [active]);
  const ranking = order ?? current;
  const byId = useMemo(() => new Map((projects.data ?? []).map((p) => [p.id, p])), [projects.data]);
  const changed = ranking.join() !== current.join();
  const dueChanged = active.some((p) => dues[p.id] !== undefined && dues[p.id] !== dateText(p.dueDate, lang));

  if (projects.loading || overview.loading) return <Loading />;
  if (projects.error || overview.error || !projects.data || !overview.data) {
    return <ErrorBox message={projects.error ?? overview.error ?? t("common.error")} />;
  }

  const guard = async (key: string, fn: () => Promise<void>) => {
    setBusy(key);
    try {
      await fn();
    } catch (e) {
      toast(e instanceof Error ? e.message : String(e), "error");
    } finally {
      setBusy(null);
    }
  };

  const preview = () =>
    guard("preview", async () => {
      setImpact(await api<Impact>("/scheduling/preview", { method: "POST", body: { ranking } }));
    });

  const apply = () =>
    guard("apply", async () => {
      const dueDates: Record<string, string> = {};
      for (const p of active) {
        if (dues[p.id] !== undefined && dues[p.id] !== dateText(p.dueDate, lang)) dueDates[p.id] = dues[p.id];
      }
      await api("/projects/priorities", { method: "PUT", body: { ranking, dueDates } });
      setOrder(null);
      setDues({});
      setImpact(null);
      await Promise.all([projects.reload(), overview.reload()]);
      toast(t("prio.applied"));
    });

  const setStatus = (p: ProjectSummary, status: "ARCHIVED" | "ACTIVE") =>
    guard(`status-${p.id}`, async () => {
      await api(`/projects/${encodeURIComponent(p.id)}`, { method: "PATCH", body: { status } });
      setOrder(null);
      await Promise.all([projects.reload(), overview.reload()]);
      toast(t("common.saved"));
    });

  const opt = overview.data.optimized;
  const naive = overview.data.naive;

  return (
    <>
      <PageHeader title={t("proj.title")} sub={t("proj.sub")} />

      {active.length === 0 && (
        <div className="mb-4">
          <Banner tone="info">{t("proj.none")}</Banner>
        </div>
      )}

      <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4">
        <Kpi label={t("proj.active")} value={num(active.length, 0)} />
        <Kpi label={t("impact.finish")} value={<span className="text-base">{date(opt.finishAt)}</span>} sub={hours(opt.makespanHours)} />
        <Kpi label={t("impact.waste")} value={hours(opt.wasteHours)} sub={`${t("impact.naive")}: ${hours(naive.wasteHours)}`} />
        <Kpi label={t("impact.utilization")} value={percent(opt.utilizationPercent)} sub={`${t("impact.naive")}: ${percent(naive.utilizationPercent)}`} />
      </div>

      {active.length > 0 && (
        <Card
          title={t("prio.title")}
          action={
            <div className="flex flex-wrap gap-2">
              <Button busy={busy === "preview"} disabled={!changed} onClick={preview}>
                {t("prio.preview")}
              </Button>
              <Button variant="primary" busy={busy === "apply"} disabled={!changed && !dueChanged} onClick={apply}>
                {t("prio.apply")}
              </Button>
            </div>
          }
        >
          <p className="mb-3 text-sm text-ink-2">{t("prio.help")}</p>
          <div className="grid gap-4 lg:grid-cols-2">
            <RankingEditor
              items={ranking.map((id) => {
                const p = byId.get(id)!;
                return {
                  id,
                  name: p.name,
                  detail: `${num(p.openOperations, 0)} ${t("proj.openOps")} · ${t("impact.finish")}: ${hours(p.finishHours)}`,
                };
              })}
              onChange={(ids) => {
                setOrder(ids);
                setImpact(null);
              }}
            />
            <div className="space-y-3">
              <h3 className="text-sm font-semibold">{t("proj.dueDates")}</h3>
              {ranking.map((id) => {
                const p = byId.get(id)!;
                return (
                  <Field key={id} label={p.name}>
                    <DateField label={p.name} value={dues[id] ?? dateText(p.dueDate, lang)} onChange={(v) => setDues({ ...dues, [id]: v })} />
                  </Field>
                );
              })}
              <p className="text-xs text-ink-3">{t("proj.dueHelp")}</p>
            </div>
          </div>
        </Card>
      )}

      {impact && (
        <div className="mt-4 space-y-3">
          <h2 className="text-sm font-semibold">{t("prio.previewTitle")}</h2>
          <ImpactPanel impact={impact} />
        </div>
      )}

      <Card title={t("proj.all")} className="mt-4">
        {projects.data.length === 0 ? (
          <Empty>{t("common.none")}</Empty>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[760px] text-sm">
              <thead>
                <tr className="border-b border-line text-xs text-ink-2">
                  <th className="px-2 py-2 text-start font-medium">{t("prio.rank")}</th>
                  <th className="px-2 py-2 text-start font-medium">{t("proj.name")}</th>
                  <th className="px-2 py-2 text-start font-medium">{t("op.progress")}</th>
                  <th className="px-2 py-2 text-start font-medium">{t("proj.ops")}</th>
                  <th className="px-2 py-2 text-start font-medium">{t("proj.finish")}</th>
                  <th className="px-2 py-2 text-start font-medium">{t("proj.due")}</th>
                  <th className="px-2 py-2 text-start font-medium">{t("proj.tardiness")}</th>
                  <th className="px-2 py-2" />
                </tr>
              </thead>
              <tbody>
                {projects.data.map((p) => (
                  <tr key={p.id} className={`border-b border-line last:border-0 ${p.status === "ARCHIVED" ? "text-ink-3" : ""}`}>
                    <td className="px-2 py-2 tabular-nums">{p.status === "ACTIVE" ? num(p.priority, 0) : "—"}</td>
                    <td className="px-2 py-2">
                      {p.name} <Mono>{p.id}</Mono>
                      {p.status === "ARCHIVED" && <span className="ms-2 text-xs">({t("proj.archived")})</span>}
                    </td>
                    <td className="w-40 px-2 py-2">
                      <div className="flex items-center gap-2">
                        <div className="w-20">
                          <ProgressBar value={p.progressPercent} label={t("op.progress")} />
                        </div>
                        <span className="text-xs tabular-nums">{percent(p.progressPercent)}</span>
                      </div>
                    </td>
                    <td className="px-2 py-2 tabular-nums">
                      {num(p.openOperations, 0)} / {num(p.totalOperations, 0)}
                    </td>
                    <td className="px-2 py-2 text-xs">{p.finishAt ? `${date(p.finishAt)} (${hours(p.finishHours)})` : "—"}</td>
                    <td className="px-2 py-2 text-xs">{p.dueDate ? date(p.dueDate) : "—"}</td>
                    <td className="px-2 py-2 tabular-nums">{p.tardinessHours > 0 ? <span className="text-bad">⚠ {hours(p.tardinessHours)}</span> : "—"}</td>
                    <td className="px-2 py-2 text-end">
                      {p.status === "ACTIVE" ? (
                        <Button variant="ghost" disabled={p.openOperations > 0} title={p.openOperations > 0 ? t("proj.archiveBlocked") : ""} busy={busy === `status-${p.id}`} onClick={() => setStatus(p, "ARCHIVED")}>
                          {t("proj.archive")}
                        </Button>
                      ) : (
                        <Button variant="ghost" busy={busy === `status-${p.id}`} onClick={() => setStatus(p, "ACTIVE")}>
                          {t("proj.restore")}
                        </Button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <div className="mt-4 grid gap-4 lg:grid-cols-2">
        <Card title={t("impact.resourcesNow")}>
          <ResourceTable metrics={opt} />
        </Card>
        <Card title={t("impact.how")}>
          <p className="text-sm text-ink-2">{t("impact.howText")}</p>
          <ul className="mt-2 list-disc space-y-1 ps-5 text-sm text-ink-2">
            {opt.projects.map((pl) => (
              <li key={pl.projectId}>
                {pl.name}: {pl.rule ? t(`rule.${pl.rule}`) : "—"}
              </li>
            ))}
          </ul>
        </Card>
      </div>
    </>
  );
}

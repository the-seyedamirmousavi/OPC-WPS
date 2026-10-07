"use client";

import { useState } from "react";
import { Banner, Button, Card, Empty, ErrorBox, Loading, Mono, PageHeader, Segmented } from "@/components/ui";
import { useT, useToast } from "@/components/Providers";
import { api, useFetch } from "@/lib/api";
import type { Mode, Proposal, Run, Settings } from "@/lib/types";

export default function AssignmentPage() {
  const { t, num, date, hours } = useT();
  const toast = useToast();
  const settings = useFetch<Settings>("/settings");
  const latest = useFetch<Run | null>("/assignments/latest");
  const history = useFetch<Run[]>("/assignments/runs?limit=8");
  const [run, setRun] = useState<Run | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [selected, setSelected] = useState<Set<number>>(new Set());

  const current = run ?? latest.data ?? null;
  const mode = settings.data?.assignmentMode;
  const pending = (current?.proposals ?? []).filter((p) => p.status === "PENDING");

  if (settings.loading || latest.loading) return <Loading />;
  if (settings.error || !settings.data || !mode) return <ErrorBox message={settings.error ?? t("common.error")} />;

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

  const switchMode = (m: Mode) =>
    guard("mode", async () => {
      await api("/settings/assignment-mode", { method: "PUT", body: { mode: m } });
      await settings.reload();
      toast(t("asg.modeChanged", { mode: t(`mode.${m}`) }));
    });

  const suggest = (m?: Mode) =>
    guard(`suggest-${m ?? "default"}`, async () => {
      const r = await api<Run>("/assignments/suggest", { method: "POST", body: m ? { mode: m } : {} });
      setRun(r);
      setSelected(new Set(r.proposals.map((p) => p.id)));
      history.reload();
      toast(t("asg.suggested", { n: r.proposals.length }));
    });

  const decide = (kind: "approve" | "reject", ids: number[]) =>
    guard(kind, async () => {
      await api(`/assignments/${kind}`, { method: "POST", body: { proposalIds: ids } });
      toast(kind === "approve" ? t("asg.approved", { n: ids.length }) : t("asg.rejected", { n: ids.length }));
      setRun(null);
      setSelected(new Set());
      await Promise.all([latest.reload(), history.reload()]);
    });

  const toggle = (id: number) =>
    setSelected((s) => {
      const n = new Set(s);
      if (n.has(id)) n.delete(id);
      else n.add(id);
      return n;
    });

  const other: Mode = mode === "ALGORITHM" ? "LLM" : "ALGORITHM";

  return (
    <>
      <PageHeader title={t("asg.title")} sub={t("asg.sub")} />

      <Card title={t("asg.modeTitle")}>
        <div className="flex flex-wrap items-center gap-4">
          <Segmented<Mode>
            label={t("asg.modeTitle")}
            value={mode}
            onChange={switchMode}
            options={[
              { value: "ALGORITHM", label: t("mode.ALGORITHM") },
              { value: "LLM", label: t("mode.LLM"), hint: settings.data.llmAvailable ? settings.data.llmModel : t("mode.noKey") },
            ]}
          />
          <Button variant="primary" busy={busy?.startsWith("suggest-default")} onClick={() => suggest()}>
            {t("asg.suggest")}
          </Button>
          <Button variant="ghost" busy={busy === `suggest-${other}`} onClick={() => suggest(other)}>
            {t("asg.tryOther", { mode: t(`mode.${other}`) })}
          </Button>
        </div>
        <dl className="mt-4 grid gap-3 text-sm md:grid-cols-2">
          <div className={`rounded-lg border p-3 ${mode === "ALGORITHM" ? "border-accent bg-accent-soft" : "border-line"}`}>
            <dt className="font-medium">{t("mode.ALGORITHM")}</dt>
            <dd className="mt-1 text-xs text-ink-2">{t("mode.algoDesc")}</dd>
          </div>
          <div className={`rounded-lg border p-3 ${mode === "LLM" ? "border-accent bg-accent-soft" : "border-line"}`}>
            <dt className="font-medium">
              {t("mode.LLM")} <span className="text-xs font-normal text-ink-3"><Mono>{settings.data.llmModel}</Mono></span>
            </dt>
            <dd className="mt-1 text-xs text-ink-2">{t("mode.llmDesc")}</dd>
          </div>
        </dl>
        {!settings.data.llmAvailable && (
          <div className="mt-3">
            <Banner tone="warn">{t("mode.noKey")}</Banner>
          </div>
        )}
      </Card>

      {current ? (
        <div className="mt-4 space-y-4">
          <Card
            title={t("asg.latestRun")}
            action={
              <span className="text-xs text-ink-3">
                {date(current.createdAt)} · {current.requestedBy} · {num(current.durationMs, 0)} ms
              </span>
            }
          >
            <div className="flex flex-wrap items-center gap-x-6 gap-y-1 text-sm">
              <span>
                {t("asg.requested")}: <strong>{t(`mode.${current.requestedMode}`)}</strong>
              </span>
              <span>
                {t("asg.effective")}: <strong>{t(`mode.${current.effectiveMode}`)}</strong>
              </span>
              {current.model && (
                <span>
                  {t("asg.model")}: <Mono>{current.model}</Mono>
                </span>
              )}
              {current.inputTokens !== null && (
                <span className="text-ink-2">
                  {t("asg.tokens")}: {num(current.inputTokens, 0)} → {num(current.outputTokens, 0)}
                </span>
              )}
            </div>
            {current.fallback && (
              <div className="mt-3">
                <Banner tone="warn">{t("asg.fallback")}</Banner>
              </div>
            )}
            {current.summary && <p className="mt-3 text-sm text-ink-2">{current.summary}</p>}
            {current.warnings.length > 0 && (
              <ul className="mt-3 space-y-1.5">
                {current.warnings.map((w, i) => (
                  <li key={i}>
                    <Banner tone="warn">{w}</Banner>
                  </li>
                ))}
              </ul>
            )}
          </Card>

          <Card
            title={`${t("asg.proposals")} (${num(current.proposals.length, 0)})`}
            action={
              pending.length > 0 && (
                <div className="flex flex-wrap gap-2">
                  <Button variant="primary" busy={busy === "approve"} disabled={selected.size === 0} onClick={() => decide("approve", [...selected])}>
                    {t("asg.approveSelected", { n: selected.size })}
                  </Button>
                  <Button busy={busy === "reject"} disabled={selected.size === 0} onClick={() => decide("reject", [...selected])}>
                    {t("asg.rejectSelected")}
                  </Button>
                </div>
              )
            }
          >
            <ProposalTable proposals={current.proposals} selected={selected} onToggle={toggle} hours={hours} date={date} />
          </Card>

          {current.unassigned.length > 0 && (
            <Card title={`${t("asg.unassigned")} (${num(current.unassigned.length, 0)})`}>
              <ul className="divide-y divide-line">
                {current.unassigned.map((s) => (
                  <li key={s.operationId} className="py-2 text-sm">
                    <Mono>{s.operationId}</Mono> <span className="text-ink-2">{s.operationName}</span>
                    <div className="text-xs text-ink-3">{s.reason}</div>
                  </li>
                ))}
              </ul>
            </Card>
          )}
        </div>
      ) : (
        <div className="mt-4">
          <Card>
            <Empty>{t("asg.noRun")}</Empty>
          </Card>
        </div>
      )}

      {(history.data?.length ?? 0) > 0 && (
        <Card title={t("asg.history")} className="mt-4">
          <ul className="divide-y divide-line text-sm">
            {history.data!.map((r) => (
              <li key={r.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                <span>
                  {date(r.createdAt)} · {t(`mode.${r.effectiveMode}`)}
                  {r.fallback && <span className="ms-2 rounded bg-warn-soft px-1.5 text-xs">{t("asg.fallbackTag")}</span>}
                </span>
                <span className="text-xs text-ink-3">
                  {num(r.proposals.length, 0)} {t("asg.proposals")} · {num(r.durationMs, 0)} ms
                </span>
              </li>
            ))}
          </ul>
        </Card>
      )}
    </>
  );
}

function ProposalTable({
  proposals,
  selected,
  onToggle,
  hours,
  date,
}: {
  proposals: Proposal[];
  selected: Set<number>;
  onToggle: (id: number) => void;
  hours: (h: number) => string;
  date: (s: string | null) => string;
}) {
  const { t } = useT();
  if (proposals.length === 0) return <Empty>{t("asg.noProposals")}</Empty>;
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[760px] text-sm">
        <thead>
          <tr className="border-b border-line text-xs text-ink-2">
            <th className="w-8 px-2 py-2" />
            <th className="px-2 py-2 text-start font-medium">{t("op.name")}</th>
            <th className="px-2 py-2 text-start font-medium">{t("op.project")}</th>
            <th className="px-2 py-2 text-start font-medium">{t("op.resource")}</th>
            <th className="px-2 py-2 text-start font-medium">{t("op.hours")}</th>
            <th className="px-2 py-2 text-start font-medium">{t("asg.suggestedUser")}</th>
            <th className="px-2 py-2 text-start font-medium">{t("asg.reason")}</th>
            <th className="px-2 py-2 text-start font-medium">{t("op.status")}</th>
          </tr>
        </thead>
        <tbody>
          {proposals.map((p) => (
            <tr key={p.id} className="border-b border-line align-top last:border-0">
              <td className="px-2 py-2">
                {p.status === "PENDING" && (
                  <input type="checkbox" aria-label={p.operationId} checked={selected.has(p.id)} onChange={() => onToggle(p.id)} className="size-4 accent-[var(--accent)]" />
                )}
              </td>
              <td className="px-2 py-2">
                <Mono>{p.operationId}</Mono>
                <div className="max-w-56 truncate text-ink-2" title={p.operationName ?? ""}>
                  {p.operationName}
                </div>
              </td>
              <td className="px-2 py-2 text-xs">
                {p.projectName}
                {p.projectRank > 0 && <div className="text-ink-3">{t("prio.rank")} {p.projectRank}</div>}
              </td>
              <td className="px-2 py-2">{p.resourceName}</td>
              <td className="px-2 py-2 tabular-nums">
                {hours(p.hours)}
                <div className="text-xs text-ink-3">
                  {t("op.criticalChain")}: {hours(p.tailHours)}
                </div>
              </td>
              <td className="px-2 py-2 font-medium">{p.userName}</td>
              <td className="max-w-80 px-2 py-2 text-xs text-ink-2">
                {p.reason}
                <div className="mt-0.5 text-ink-3">
                  {date(p.plannedStart)} → {date(p.plannedEnd)}
                </div>
              </td>
              <td className="px-2 py-2 text-xs">
                {t(`proposal.${p.status}`)}
                {p.decisionNote && <div className="text-ink-3">{p.decisionNote}</div>}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

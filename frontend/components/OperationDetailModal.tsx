"use client";

import { useState } from "react";
import { Banner, Button, Field, Loading, Modal, Mono, ProgressBar, StatusBadge, inputClass } from "@/components/ui";
import { useT, useToast } from "@/components/Providers";
import { api, useFetch } from "@/lib/api";
import type { OperationDetail, PredView, User } from "@/lib/types";

/**
 * Operation detail with the actions allowed for the viewer.
 * mode "manage": owner / manager (assign, unassign, unblock, approve, cancel).
 * mode "execute": the assigned executive user (start, progress, complete, report a problem).
 */
export function OperationDetailModal({
  id,
  mode,
  onClose,
  onChanged,
}: {
  id: string | null;
  mode: "manage" | "execute";
  onClose: () => void;
  onChanged: () => void;
}) {
  const { t } = useT();
  const { data, loading, reload } = useFetch<OperationDetail>(id ? `/operations/${encodeURIComponent(id)}` : null);
  const title = data ? `${data.operation.id} — ${data.operation.name}` : (id ?? "");
  return (
    <Modal open={id !== null} title={title} onClose={onClose} wide>
      {loading || !data ? (
        <Loading />
      ) : (
        <Body
          key={data.operation.id + data.operation.status + data.reports.length}
          d={data}
          mode={mode}
          refresh={() => {
            reload();
            onChanged();
          }}
        />
      )}
      <span className="sr-only">{t("op.details")}</span>
    </Modal>
  );
}

function Pred({ p }: { p: PredView }) {
  const { t } = useT();
  return (
    <li className="flex items-center justify-between gap-2 rounded-lg border border-line px-3 py-1.5 text-sm">
      <span className="min-w-0 truncate">
        <Mono>{p.id}</Mono> <span className="text-ink-2">{p.name}</span>
      </span>
      <span className="flex shrink-0 items-center gap-2">
        {!p.mandatory && <span className="text-xs text-ink-3">{t("op.optional")}</span>}
        {p.type === "START_TO_START" && <span className="text-xs text-ink-3">SS</span>}
        {p.status && <StatusBadge status={p.status} />}
        <span className={p.satisfied ? "text-good-ink" : "text-bad"} title={p.satisfied ? t("op.satisfied") : t("op.unsatisfied")}>
          {p.satisfied ? "✓" : "✕"}
        </span>
      </span>
    </li>
  );
}

function Body({ d, mode, refresh }: { d: OperationDetail; mode: "manage" | "execute"; refresh: () => void }) {
  const { t, hours, date, num } = useT();
  const toast = useToast();
  const o = d.operation;
  const [busy, setBusy] = useState(false);
  const [note, setNote] = useState("");
  const [percent, setPercent] = useState(o.progressPercent);
  const [assignee, setAssignee] = useState(o.assignedUserId ?? "");
  const [reason, setReason] = useState("");
  const [comment, setComment] = useState("");
  const users = useFetch<User[]>(mode === "manage" && (o.status === "READY" || o.status === "ASSIGNED") ? "/users" : null);
  const executors = (users.data ?? []).filter((u) => u.active && u.role.startsWith("USER_"));

  const act = async (path: string, body?: unknown, ok?: string) => {
    setBusy(true);
    try {
      await api(`/operations/${encodeURIComponent(o.id)}${path}`, { method: "POST", body: body ?? {} });
      toast(ok ?? t("common.saved"));
      setNote("");
      setReason("");
      setComment("");
      refresh();
    } catch (e) {
      toast(e instanceof Error ? e.message : String(e), "error");
    } finally {
      setBusy(false);
    }
  };

  const rows: [string, React.ReactNode][] = [
    [t("op.status"), <StatusBadge key="s" status={o.status} />],
    [t("op.project"), `${o.projectName} (${t("prio.rank")} ${num(o.projectRank, 0)})`],
    [t("op.resource"), o.resourceName],
    [t("op.item"), o.itemName ?? "—"],
    [t("op.assignee"), o.assignedUserName ?? "—"],
    [t("op.hours"), hours(o.totalHours)],
    [t("op.criticalChain"), hours(o.tailHours)],
    [t("op.plannedWindow"), `${date(o.plannedStart)} → ${date(o.plannedEnd)}`],
    [t("op.projectedWindow"), `${date(o.projectedStart)} → ${date(o.projectedEnd)}`],
  ];

  return (
    <div className="space-y-5">
      {o.delayed && (
        <Banner tone="error">
          {t("op.delayedBy", { h: num(o.delayHours, 1) })}
        </Banner>
      )}
      {o.status === "BLOCKED" && o.blockReason && (
        <Banner tone="warn">
          <strong>{t("op.blockedBecause")}:</strong> {o.blockReason}
        </Banner>
      )}
      {o.status === "CANCELLED" && o.cancelReason && (
        <Banner>
          <strong>{t("op.cancelledBecause")}:</strong> {o.cancelReason}
        </Banner>
      )}

      <dl className="grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2">
        {rows.map(([k, v]) => (
          <div key={k} className="flex items-baseline justify-between gap-3 border-b border-line py-1">
            <dt className="text-xs text-ink-2">{k}</dt>
            <dd className="text-end text-sm">{v}</dd>
          </div>
        ))}
      </dl>
      {(o.status === "IN_PROGRESS" || o.status === "COMPLETED") && <ProgressBar value={o.progressPercent} label={t("op.progress")} />}

      <section>
        <h3 className="mb-2 text-sm font-semibold">{t("op.predecessors")}</h3>
        {o.predecessors.length === 0 ? (
          <p className="text-sm text-ink-3">{t("op.noPredecessors")}</p>
        ) : (
          <ul className="space-y-1.5">{o.predecessors.map((p) => <Pred key={p.id} p={p} />)}</ul>
        )}
      </section>
      {d.successors.length > 0 && (
        <section>
          <h3 className="mb-2 text-sm font-semibold">{t("op.successors")}</h3>
          <ul className="space-y-1.5">{d.successors.map((p) => <Pred key={p.id} p={p} />)}</ul>
        </section>
      )}

      {/* ----- actions ----- */}
      <section className="space-y-3 rounded-xl border border-line bg-surface-2 p-4">
        <h3 className="text-sm font-semibold">{t("op.actions")}</h3>

        {mode === "execute" && o.status === "ASSIGNED" && (
          <Button variant="primary" busy={busy} onClick={() => act("/start", {}, t("op.started"))}>
            {t("op.start")}
          </Button>
        )}

        {mode === "execute" && o.status === "IN_PROGRESS" && (
          <div className="space-y-3">
            <Field label={`${t("op.progress")}: ${num(percent, 0)}%`}>
              <input type="range" min={0} max={100} step={5} value={percent} onChange={(e) => setPercent(Number(e.target.value))} className="w-full accent-[var(--accent)]" />
            </Field>
            <Field label={t("op.note")}>
              <input className={inputClass} value={note} onChange={(e) => setNote(e.target.value)} />
            </Field>
            <div className="flex flex-wrap gap-2">
              <Button busy={busy} onClick={() => act("/progress", { percent, note })}>
                {t("op.reportProgress")}
              </Button>
              <Button variant="primary" busy={busy} onClick={() => act("/complete", { note }, t("op.completedMsg"))}>
                {t("op.complete")}
              </Button>
            </div>
          </div>
        )}

        {mode === "execute" && (o.status === "ASSIGNED" || o.status === "IN_PROGRESS") && (
          <div className="space-y-2 border-t border-line pt-3">
            <Field label={t("op.blockReason")}>
              <input className={inputClass} value={reason} onChange={(e) => setReason(e.target.value)} placeholder={t("op.blockPlaceholder")} />
            </Field>
            <Button variant="danger" busy={busy} disabled={!reason.trim()} onClick={() => act("/block", { reason })}>
              {t("op.reportProblem")}
            </Button>
          </div>
        )}

        {mode === "manage" && (o.status === "READY" || o.status === "ASSIGNED") && (
          <div className="flex flex-wrap items-end gap-2">
            <div className="min-w-48 flex-1">
              <Field label={o.status === "READY" ? t("op.assignTo") : t("op.reassignTo")}>
                <select className={inputClass} value={assignee} onChange={(e) => setAssignee(e.target.value)}>
                  <option value="">—</option>
                  {executors.map((u) => (
                    <option key={u.id} value={u.id}>
                      {u.fullName} ({u.id})
                    </option>
                  ))}
                </select>
              </Field>
            </div>
            <Button variant="primary" busy={busy} disabled={!assignee || assignee === o.assignedUserId} onClick={() => api(`/operations/${encodeURIComponent(o.id)}/assign`, { method: "POST", body: { userId: assignee } }).then(() => { toast(t("op.assigned")); refresh(); }).catch((e) => toast(String(e.message ?? e), "error"))}>
              {t("op.assign")}
            </Button>
            {o.status === "ASSIGNED" && (
              <Button busy={busy} onClick={() => act("/unassign", {}, t("op.unassigned"))}>
                {t("op.unassign")}
              </Button>
            )}
          </div>
        )}

        {mode === "manage" && o.status === "BLOCKED" && (
          <div className="flex flex-wrap items-end gap-2">
            <div className="min-w-48 flex-1">
              <Field label={t("op.note")}>
                <input className={inputClass} value={note} onChange={(e) => setNote(e.target.value)} />
              </Field>
            </div>
            <Button variant="primary" busy={busy} onClick={() => act("/unblock", { note }, t("op.unblocked"))}>
              {t("op.unblock")}
            </Button>
          </div>
        )}

        {mode === "manage" && o.status === "COMPLETED" && !o.completionApproved && (
          <Button variant="primary" busy={busy} onClick={() => act("/approve", {}, t("op.approvedMsg"))}>
            {t("op.approveCompletion")}
          </Button>
        )}
        {o.status === "COMPLETED" && o.completionApproved && <p className="text-sm text-good-ink">✓ {t("op.completionApproved")}</p>}

        {mode === "manage" && o.status !== "COMPLETED" && o.status !== "CANCELLED" && (
          <div className="flex flex-wrap items-end gap-2 border-t border-line pt-3">
            <div className="min-w-48 flex-1">
              <Field label={t("op.cancelReason")}>
                <input className={inputClass} value={reason} onChange={(e) => setReason(e.target.value)} />
              </Field>
            </div>
            <Button variant="danger" busy={busy} disabled={!reason.trim()} onClick={() => act("/cancel", { reason }, t("op.cancelled"))}>
              {t("op.cancel")}
            </Button>
          </div>
        )}

        {o.status !== "CANCELLED" && (
          <div className="flex flex-wrap items-end gap-2 border-t border-line pt-3">
            <div className="min-w-48 flex-1">
              <Field label={t("op.addComment")}>
                <input className={inputClass} value={comment} onChange={(e) => setComment(e.target.value)} />
              </Field>
            </div>
            <Button busy={busy} disabled={!comment.trim()} onClick={() => act("/comment", { reason: comment })}>
              {t("op.comment")}
            </Button>
          </div>
        )}
      </section>

      <section>
        <h3 className="mb-2 text-sm font-semibold">{t("op.history")}</h3>
        {d.reports.length === 0 ? (
          <p className="text-sm text-ink-3">{t("common.none")}</p>
        ) : (
          <ol className="space-y-2 border-s border-line ps-4">
            {[...d.reports].reverse().map((r) => (
              <li key={r.id} className="text-sm">
                <div className="flex flex-wrap items-baseline gap-2">
                  <span className="font-medium">{t(`report.${r.type}`)}</span>
                  {r.progressPercent !== null && <span className="text-xs text-ink-2">{num(r.progressPercent, 0)}%</span>}
                  <span className="text-xs text-ink-3">
                    {r.userName ?? r.userId} · {date(r.createdAt)}
                  </span>
                </div>
                {r.note && <div className="text-ink-2">{r.note}</div>}
              </li>
            ))}
          </ol>
        )}
      </section>
    </div>
  );
}

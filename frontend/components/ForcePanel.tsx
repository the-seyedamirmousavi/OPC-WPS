"use client";

import { useMemo, useState } from "react";
import { DateField } from "@/components/DateField";
import { useT, useToast } from "@/components/Providers";
import { Banner, Button, Card, Empty, Field, inputClass } from "@/components/ui";
import { api, useFetch } from "@/lib/api";
import { isoToJalaliText } from "@/lib/jalali";
import type { Finding, ForceBoard, ForceImpact, ForceView, Operation, User } from "@/lib/types";

const STATE_STYLE: Record<ForceView["state"], string> = {
  HONOURED: "bg-accent-soft text-ink",
  NOT_HONOURED: "bg-bad-soft text-bad",
  DONE: "bg-surface-2 text-ink-3",
};

/** Tehran wall-clock date ("YYYY-MM-DD") and time ("HH:MM") of an instant. */
function tehranParts(iso: string): { date: string; time: string } {
  const f = new Intl.DateTimeFormat("en-CA", {
    timeZone: "Asia/Tehran",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    hourCycle: "h23",
  }).formatToParts(new Date(iso));
  const get = (type: string) => f.find((p) => p.type === type)?.value ?? "";
  return { date: `${get("year")}-${get("month")}-${get("day")}`, time: `${get("hour")}:${get("minute")}` };
}

/**
 * Manager FORCE: list of FORCEs with their state, a form that previews the impact of a new or changed FORCE before
 * it is applied, and "re-optimise from scratch".
 */
export function ForcePanel({ ops, onChanged }: { ops: Operation[]; onChanged: () => void }) {
  const { t, lang, date, hours, num } = useT();
  const toast = useToast();
  const board = useFetch<ForceBoard>("/scheduling/forces", 30000);
  const users = useFetch<User[]>("/users");
  const executors = (users.data ?? []).filter((u) => u.active && u.role.startsWith("USER_"));

  const [opId, setOpId] = useState("");
  const [day, setDay] = useState("");
  const [time, setTime] = useState("");
  const [userId, setUserId] = useState("");
  const [reason, setReason] = useState("");
  const [impact, setImpact] = useState<ForceImpact | null>(null);
  const [busy, setBusy] = useState(false);

  const candidates = useMemo(
    () => ops.filter((o) => ["NOT_READY", "READY", "ASSIGNED", "BLOCKED"].includes(o.status)).sort((a, b) => a.id.localeCompare(b.id)),
    [ops],
  );

  const request = () => {
    const start = day.trim() && time.trim() ? `${day.trim()} ${time.trim()}` : null;
    return { start, userId: userId || null, reason: reason.trim() || null };
  };

  const resetForm = () => {
    setImpact(null);
    setDay("");
    setTime("");
    setUserId("");
    setReason("");
  };

  const edit = (f: ForceView) => {
    setOpId(f.operationId);
    if (f.forcedStart) {
      const p = tehranParts(f.forcedStart);
      setDay(lang === "fa" ? isoToJalaliText(f.forcedStart) : p.date);
      setTime(p.time);
    } else {
      setDay("");
      setTime("");
    }
    setUserId(f.forcedUserId ?? "");
    setReason(f.reason ?? "");
    setImpact(null);
  };

  const call = async <T,>(fn: () => Promise<T>, ok?: string): Promise<T | null> => {
    setBusy(true);
    try {
      const r = await fn();
      if (ok) toast(ok);
      return r;
    } catch (e) {
      toast(e instanceof Error ? e.message : String(e), "error");
      return null;
    } finally {
      setBusy(false);
    }
  };

  const preview = async () => {
    const body = request();
    if (!opId) return;
    if (!body.start && !body.userId) {
      toast(t("force.needTime"), "error");
      return;
    }
    setImpact(await call(() => api<ForceImpact>(`/scheduling/forces/${encodeURIComponent(opId)}/preview`, { method: "POST", body })));
  };

  const apply = async () => {
    const r = await call(() => api<ForceBoard>(`/scheduling/forces/${encodeURIComponent(opId)}`, { method: "PUT", body: request() }), t("force.applied"));
    if (r) {
      resetForm();
      setOpId("");
      board.reload();
      onChanged();
    }
  };

  const remove = async (id: string) => {
    if (!window.confirm(t("force.removeConfirm", { id }))) return;
    const r = await call(() => api<ForceBoard>(`/scheduling/forces/${encodeURIComponent(id)}`, { method: "DELETE" }), t("force.removed"));
    if (r) {
      board.reload();
      onChanged();
    }
  };

  const reoptimize = async () => {
    if (!window.confirm(t("force.reoptimizeConfirm"))) return;
    const r = await call(() => api<ForceBoard>("/scheduling/reoptimize", { method: "POST" }), t("force.reoptimized"));
    if (r) {
      board.reload();
      onChanged();
    }
  };

  const b = board.data;

  return (
    <Card
      title={t("force.title")}
      action={
        <Button onClick={reoptimize} busy={busy} variant="ghost">
          {t("force.reoptimize")}
        </Button>
      }
    >
      <p className="mb-3 text-sm text-ink-2">{t("force.sub")}</p>
      <p className="mb-4 text-xs text-ink-3">{b?.baselineSavedAt ? t("force.reference", { d: date(b.baselineSavedAt) }) : t("force.noReference")}</p>

      {b && b.conflicts.length > 0 && (
        <div className="mb-3">
          <Banner tone="error">
            <p className="font-medium">{t("force.conflicts")}</p>
            <FindingList items={b.conflicts} />
          </Banner>
        </div>
      )}
      {b && b.escalated > 0 && (
        <div className="mb-3">
          <Banner tone="warn">{t("force.escalated", { n: num(b.escalated, 0) })}</Banner>
        </div>
      )}

      <h3 className="mb-2 text-sm font-semibold">{t("force.list")}</h3>
      {!b || b.forces.length === 0 ? (
        <Empty>{t("force.none")}</Empty>
      ) : (
        <div className="mb-5 overflow-x-auto">
          <table className="w-full min-w-[640px] text-sm">
            <thead className="text-xs text-ink-3">
              <tr className="border-b border-line text-start">
                <th className="py-1.5 text-start font-medium">{t("force.operation")}</th>
                <th className="py-1.5 text-start font-medium">{t("force.start")}</th>
                <th className="py-1.5 text-start font-medium">{t("force.user")}</th>
                <th className="py-1.5 text-start font-medium">{t("force.planned")}</th>
                <th className="py-1.5 text-start font-medium">{t("force.reason")}</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {b.forces.map((f) => (
                <tr key={f.operationId} className="border-b border-line align-top">
                  <td className="py-2 pe-2">
                    <div className="font-medium">
                      {f.operationId} · {f.operationName}
                    </div>
                    <div className="text-xs text-ink-3">
                      {f.projectName} · {f.resourceName}
                    </div>
                    <span className={`mt-1 inline-block rounded px-1.5 py-0.5 text-xs ${STATE_STYLE[f.state]}`}>{t(`force.state.${f.state}`)}</span>
                    {f.conflict && <p className="mt-1 text-xs text-bad">{f.conflict.message}</p>}
                  </td>
                  <td className="py-2 pe-2">{f.forcedStart ? date(f.forcedStart) : "—"}</td>
                  <td className="py-2 pe-2">{f.forcedUserName ?? f.forcedUserId ?? "—"}</td>
                  <td className="py-2 pe-2">{date(f.plannedStart)}</td>
                  <td className="py-2 pe-2 text-ink-2">{f.reason ?? "—"}</td>
                  <td className="py-2 text-end whitespace-nowrap">
                    <Button variant="ghost" onClick={() => edit(f)} disabled={busy}>
                      {t("force.edit")}
                    </Button>
                    <Button variant="ghost" onClick={() => remove(f.operationId)} disabled={busy}>
                      {t("force.remove")}
                    </Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
        <Field label={t("force.operation")}>
          <select
            className={inputClass}
            value={opId}
            onChange={(e) => {
              setOpId(e.target.value);
              setImpact(null);
            }}
          >
            <option value="">{t("force.choose")}</option>
            {candidates.map((o) => (
              <option key={o.id} value={o.id}>
                {o.id} · {o.name} ({o.projectName})
              </option>
            ))}
          </select>
        </Field>
        <Field label={t("force.user")}>
          <select
            className={inputClass}
            value={userId}
            onChange={(e) => {
              setUserId(e.target.value);
              setImpact(null);
            }}
          >
            <option value="">{t("force.anyUser")}</option>
            {executors.map((u) => (
              <option key={u.id} value={u.id}>
                {u.fullName} ({u.id})
              </option>
            ))}
          </select>
        </Field>
        <Field label={t("force.date")}>
          <DateField
            label={t("force.date")}
            value={day}
            onChange={(v) => {
              setDay(v);
              setImpact(null);
            }}
          />
        </Field>
        <Field label={t("force.time")}>
          <input
            type="time"
            dir="ltr"
            className={inputClass}
            value={time}
            onChange={(e) => {
              setTime(e.target.value);
              setImpact(null);
            }}
          />
        </Field>
        <div className="md:col-span-2">
          <Field label={t("force.reason")}>
            <input className={inputClass} value={reason} maxLength={500} onChange={(e) => setReason(e.target.value)} />
          </Field>
        </div>
      </div>
      <div className="mt-3 flex flex-wrap gap-2">
        <Button onClick={preview} busy={busy} disabled={!opId}>
          {t("force.preview")}
        </Button>
        <Button variant="primary" onClick={apply} busy={busy} disabled={!impact || !impact.feasible}>
          {t("force.apply")}
        </Button>
      </div>

      {impact && (
        <div className="mt-4 space-y-3">
          {impact.feasible ? (
            <Banner tone="ok">
              {t("force.feasible")} {impact.plannedStart && `${t("force.planned")}: ${date(impact.plannedStart)} → ${date(impact.plannedEnd)}`}
            </Banner>
          ) : (
            <Banner tone="error">
              <p className="font-medium">{t("force.infeasible")}</p>
              <FindingList items={impact.conflicts} withProof />
            </Banner>
          )}
          {impact.warnings.length > 0 && (
            <Banner tone="warn">
              <p className="font-medium">{t("force.warnings")}</p>
              <FindingList items={impact.warnings} />
            </Banner>
          )}
          {impact.feasible && (
            <>
              <p className="text-sm">
                {impact.movedCount === 0
                  ? t("force.noMoves")
                  : t("force.moves", { n: num(impact.movedCount, 0), c: num(impact.committedMoved, 0), h: hours(impact.totalShiftHours) })}{" "}
                <span className="text-ink-3">{t("force.waste", { a: hours(impact.wasteBefore), b: hours(impact.wasteAfter) })}</span>
              </p>
              {impact.moved.length > 0 && (
                <div className="overflow-x-auto">
                  <table className="w-full min-w-[560px] text-sm">
                    <thead className="text-xs text-ink-3">
                      <tr className="border-b border-line">
                        <th className="py-1 text-start font-medium">{t("force.operation")}</th>
                        <th className="py-1 text-start font-medium">{t("force.before")}</th>
                        <th className="py-1 text-start font-medium">{t("force.after")}</th>
                        <th className="py-1 text-end font-medium">{t("force.shift")}</th>
                      </tr>
                    </thead>
                    <tbody>
                      {impact.moved.slice(0, 12).map((m) => (
                        <tr key={m.operationId} className="border-b border-line">
                          <td className="py-1 pe-2">
                            {m.committed && <span title={t("op.assignee")}>● </span>}
                            {m.operationId} · {m.name} <span className="text-xs text-ink-3">({m.projectName})</span>
                          </td>
                          <td className="py-1 pe-2">{date(m.before)}</td>
                          <td className="py-1 pe-2">{date(m.after)}</td>
                          <td className={`py-1 text-end ${m.shiftHours > 0 ? "text-bad" : "text-ink-2"}`}>
                            {m.shiftHours > 0 ? "+" : "−"}
                            {hours(Math.abs(m.shiftHours))}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
              {impact.projects.length > 0 && (
                <div>
                  <h4 className="mb-1 text-xs font-medium text-ink-2">{t("force.projects")}</h4>
                  <ul className="space-y-0.5 text-sm">
                    {impact.projects.map((p) => (
                      <li key={p.projectId} className="flex flex-wrap justify-between gap-2">
                        <span>
                          {p.name} <span className="text-xs text-ink-3">({t("prio.rank")} {num(p.priority, 0)})</span>
                        </span>
                        <span>
                          {date(p.before)} → {date(p.after)}{" "}
                          {Math.abs(p.deltaHours) > 0.02 && (
                            <span className={p.deltaHours > 0 ? "text-bad" : "text-ink-2"}>
                              ({p.deltaHours > 0 ? "+" : "−"}
                              {hours(Math.abs(p.deltaHours))})
                            </span>
                          )}
                        </span>
                      </li>
                    ))}
                  </ul>
                </div>
              )}
            </>
          )}
        </div>
      )}
    </Card>
  );
}

function FindingList({ items, withProof }: { items: Finding[]; withProof?: boolean }) {
  const { t } = useT();
  return (
    <ul className="mt-1 list-disc space-y-1 ps-5">
      {items.map((f, i) => (
        <li key={i}>
          {f.message}
          {withProof && <span className="text-xs text-ink-3"> ({f.proven ? t("force.proven") : t("force.heuristic")})</span>}
        </li>
      ))}
    </ul>
  );
}

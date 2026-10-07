"use client";

import { useState } from "react";
import { StatusBar } from "@/components/StatusBar";
import { Banner, Button, Card, Empty, ErrorBox, Field, Kpi, Loading, Modal, Mono, PageHeader, Segmented, inputClass } from "@/components/ui";
import { useT, useToast } from "@/components/Providers";
import { api, useFetch } from "@/lib/api";
import type { ImportResult, Mode, OwnerDashboard, Settings } from "@/lib/types";

export default function OwnerPage() {
  const { t, num, date, percent } = useT();
  const toast = useToast();
  const dash = useFetch<OwnerDashboard>("/dashboard/owner", 20000);
  const settings = useFetch<Settings>("/settings");
  const [busy, setBusy] = useState<string | null>(null);
  const [form, setForm] = useState<Partial<Settings> | null>(null);
  const [statusModal, setStatusModal] = useState(false);
  const [resetModal, setResetModal] = useState(false);
  const [reason, setReason] = useState("");
  const [confirm, setConfirm] = useState("");

  if (dash.loading || settings.loading) return <Loading />;
  if (dash.error || settings.error || !dash.data || !settings.data) return <ErrorBox message={dash.error ?? settings.error ?? t("common.error")} />;
  const d = dash.data;
  const s = settings.data;
  const f = { ...s, ...form };
  const suspended = d.systemStatus === "SUSPENDED";

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
  const refresh = () => Promise.all([dash.reload(), settings.reload()]);

  const save = () =>
    guard("save", async () => {
      await api("/settings", {
        method: "PATCH",
        body: {
          projectName: f.projectName,
          messengerPlatform: f.messengerPlatform,
          maxActiveTasksPerUser: Number(f.maxActiveTasksPerUser),
          requireCompletionApproval: f.requireCompletionApproval,
          language: f.language,
        },
      });
      setForm(null);
      await refresh();
      toast(t("common.saved"));
    });

  const setMode = (mode: Mode) =>
    guard("mode", async () => {
      await api("/settings/assignment-mode", { method: "PUT", body: { mode } });
      await refresh();
      toast(t("asg.modeChanged", { mode: t(`mode.${mode}`) }));
    });

  const changeStatus = () =>
    guard("status", async () => {
      await api("/admin/system-status", { method: "PUT", body: { status: suspended ? "ACTIVE" : "SUSPENDED", reason } });
      setStatusModal(false);
      setReason("");
      await refresh();
    });

  const demo = () =>
    guard("demo", async () => {
      const r = await api<ImportResult>("/admin/demo-data", { method: "POST" });
      if (r.status === "APPLIED") toast(t("owner.demoLoaded"));
      else toast(r.errors.map((e) => e.message).join("; "), "error");
      await refresh();
    });

  const reset = () =>
    guard("reset", async () => {
      await api("/admin/reset", { method: "POST", body: { reason, confirm } });
      setResetModal(false);
      setReason("");
      setConfirm("");
      await refresh();
      toast(t("owner.resetDone"));
    });

  return (
    <>
      <PageHeader
        title={t("owner.title")}
        sub={`${d.projectName ?? ""} ${d.projectId ? `(${d.projectId})` : ""}`}
        actions={
          <Button variant={suspended ? "primary" : "danger"} onClick={() => setStatusModal(true)}>
            {suspended ? t("owner.resume") : t("owner.suspend")}
          </Button>
        }
      />

      {suspended && (
        <div className="mb-4">
          <Banner tone="warn">{t("owner.suspendedBanner")}</Banner>
        </div>
      )}

      <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4">
        <Kpi label={t("owner.systemStatus")} value={suspended ? t("owner.statusSuspended") : t("owner.statusActive")} tone={suspended ? "bad" : "good"} />
        <Kpi label={t("owner.versions")} value={<span className="ltr text-lg">v{d.dataVersion ?? "0"} / c{d.configurationVersion}</span>} sub={t("owner.versionsSub")} />
        <Kpi label={t("kpi.progress")} value={percent(d.progress.progressPercent)} sub={`${num(d.progress.totalOperations, 0)} ${t("owner.operations")}`} />
        <Kpi label={t("owner.failedMessages")} value={num(d.failedNotifications, 0)} tone={d.failedNotifications > 0 ? "bad" : undefined} sub={t("owner.failedSub")} />
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card title={t("owner.assignmentMode")}>
          <Segmented<Mode>
            label={t("owner.assignmentMode")}
            value={d.assignmentMode}
            onChange={setMode}
            options={[
              { value: "ALGORITHM", label: t("mode.ALGORITHM") },
              { value: "LLM", label: t("mode.LLM") },
            ]}
          />
          <p className="mt-3 text-xs text-ink-2">
            {t("owner.llmStatus")}:{" "}
            {d.llmAvailable ? (
              <span className="text-good-ink">
                ✓ {t("owner.llmReady")} (<Mono>{d.llmModel}</Mono>)
              </span>
            ) : (
              <span className="text-bad">✕ {t("mode.noKey")}</span>
            )}
          </p>
        </Card>

        <Card title={t("owner.settings")}>
          <div className="space-y-3">
            <Field label={t("owner.projectName")}>
              <input className={inputClass} value={f.projectName ?? ""} onChange={(e) => setForm({ ...form, projectName: e.target.value })} />
            </Field>
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label={t("owner.messenger")} hint={t("owner.messengerHint")}>
                <input className={`${inputClass} ltr`} value={f.messengerPlatform} onChange={(e) => setForm({ ...form, messengerPlatform: e.target.value })} />
              </Field>
              <Field label={t("owner.maxTasks")} hint={t("owner.maxTasksHint")}>
                <input type="number" min={1} max={50} className={inputClass} value={f.maxActiveTasksPerUser} onChange={(e) => setForm({ ...form, maxActiveTasksPerUser: Number(e.target.value) })} />
              </Field>
            </div>
            <Field label={t("owner.outputLanguage")} hint={t("owner.outputLanguageHint")}>
              <select className={inputClass} value={f.language} onChange={(e) => setForm({ ...form, language: e.target.value as "fa" | "en" })}>
                <option value="fa">{t("lang.fa")}</option>
                <option value="en">{t("lang.en")}</option>
              </select>
            </Field>
            <label className="flex items-start gap-2 text-sm">
              <input type="checkbox" className="mt-1 size-4 accent-[var(--accent)]" checked={f.requireCompletionApproval} onChange={(e) => setForm({ ...form, requireCompletionApproval: e.target.checked })} />
              <span>
                {t("owner.requireApproval")}
                <span className="block text-xs text-ink-3">{t("owner.requireApprovalHint")}</span>
              </span>
            </label>
            <Button variant="primary" busy={busy === "save"} disabled={!form} onClick={save}>
              {t("common.save")}
            </Button>
          </div>
        </Card>

        <Card title={t("chart.statusDistribution")}>
          <StatusBar counts={d.statusCounts} />
        </Card>

        <Card title={t("owner.users")}>
          <ul className="grid grid-cols-2 gap-2 text-sm">
            {(["OWNER", "MANAGER", "USER_1", "USER_2", "USER_3"] as const).map((r) => (
              <li key={r} className="flex justify-between rounded-lg border border-line px-3 py-2">
                <span>{t(`role.${r}`)}</span>
                <span className="tabular-nums">{num(d.usersByRole[r] ?? 0, 0)}</span>
              </li>
            ))}
          </ul>
          <p className="mt-2 text-xs text-ink-3">
            {t("owner.activeUsers")}: {num(d.activeUsers, 0)}
          </p>
        </Card>

        <Card title={t("owner.recentImports")}>
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

        <Card title={t("owner.recentAudit")}>
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

      <Card title={t("owner.danger")} className="mt-4 border-bad">
        <div className="flex flex-wrap items-center gap-3">
          <Button busy={busy === "demo"} onClick={demo}>
            {t("owner.loadDemo")}
          </Button>
          <Button variant="danger" onClick={() => setResetModal(true)}>
            {t("owner.reset")}
          </Button>
        </div>
        <p className="mt-2 text-xs text-ink-3">{t("owner.dangerHint")}</p>
      </Card>

      <Modal
        open={statusModal}
        title={suspended ? t("owner.resume") : t("owner.suspend")}
        onClose={() => setStatusModal(false)}
        footer={
          <>
            <Button onClick={() => setStatusModal(false)}>{t("common.cancel")}</Button>
            <Button variant={suspended ? "primary" : "danger"} busy={busy === "status"} disabled={!reason.trim()} onClick={changeStatus}>
              {t("common.confirm")}
            </Button>
          </>
        }
      >
        <Field label={t("common.reason")}>
          <input className={inputClass} value={reason} onChange={(e) => setReason(e.target.value)} autoFocus />
        </Field>
      </Modal>

      <Modal
        open={resetModal}
        title={t("owner.reset")}
        onClose={() => setResetModal(false)}
        footer={
          <>
            <Button onClick={() => setResetModal(false)}>{t("common.cancel")}</Button>
            <Button variant="danger" busy={busy === "reset"} disabled={!reason.trim() || confirm !== "RESET"} onClick={reset}>
              {t("owner.reset")}
            </Button>
          </>
        }
      >
        <div className="space-y-3">
          <Banner tone="warn">{t("owner.resetWarning")}</Banner>
          <Field label={t("common.reason")}>
            <input className={inputClass} value={reason} onChange={(e) => setReason(e.target.value)} />
          </Field>
          <Field label={t("owner.typeReset")}>
            <input className={`${inputClass} ltr`} value={confirm} onChange={(e) => setConfirm(e.target.value)} placeholder="RESET" />
          </Field>
        </div>
      </Modal>
    </>
  );
}

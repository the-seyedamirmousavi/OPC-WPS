
import { useState } from "react";
import { AiPermissions } from "@/components/AiPermissions";
import { Banner, Button, Card, ErrorBox, Field, Kpi, Loading, Modal, Mono, PageHeader, Segmented, inputClass } from "@/components/ui";
import { useT, useToast } from "@/components/Providers";
import { api, useFetch } from "@/lib/api";
import type { ImportResult, Mode, OwnerDashboard, Settings } from "@/lib/types";

export default function SystemAdministrationPage() {
  const { t } = useT();
  const toast = useToast();
  const dash = useFetch<OwnerDashboard>("/dashboard/owner", 20000);
  const settings = useFetch<Settings>("/settings");
  const [busy, setBusy] = useState<string | null>(null);
  const [form, setForm] = useState<Partial<Settings> | null>(null);
  const [statusModal, setStatusModal] = useState(false);
  const [resetModal, setResetModal] = useState(false);
  const [reason, setReason] = useState("");
  const [confirm, setConfirm] = useState("");
  // AI service form: the token is write-only, so the field starts empty and only a typed value is sent
  const [llmToken, setLlmToken] = useState("");
  const [llmModel, setLlmModel] = useState<string | null>(null);
  const [baleToken, setBaleToken] = useState("");

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

  const saveLlm = () =>
    guard("llm", async () => {
      await api("/settings/llm", { method: "PUT", body: { apiKey: llmToken.trim() || undefined, model: llmModel ?? undefined } });
      setLlmToken("");
      setLlmModel(null);
      await refresh();
      toast(t("owner.llm.saved"));
    });

  const removeLlmToken = () =>
    guard("llmRemove", async () => {
      await api("/settings/llm", { method: "PUT", body: { clearKey: true } });
      await refresh();
      toast(t("owner.llm.removed"));
    });

  const saveBale = () =>
    guard("bale", async () => {
      await api("/settings/bale", { method: "PUT", body: { token: baleToken.trim() || undefined } });
      setBaleToken("");
      await refresh();
      toast(t("owner.bale.saved"));
    });

  const removeBaleToken = () =>
    guard("baleRemove", async () => {
      await api("/settings/bale", { method: "PUT", body: { clearToken: true } });
      await refresh();
      toast(t("owner.bale.removed"));
    });

  const checkBale = () =>
    guard("baleCheck", async () => {
      const bot = await api<{ username: string; isBot: boolean }>("/settings/bale/check", { method: "POST" });
      toast(t("owner.bale.checked", { name: bot.username }));
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
        title={t("owner.system.title")}
        sub={t("owner.system.sub")}
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
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <div className="lg:col-span-2">
          <AiPermissions />
        </div>

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

        <Card title={t("owner.llm.title")}>
          <p className="mb-3 text-xs text-ink-2">{t("owner.llm.hint")}</p>
          <div className="space-y-3">
            <Field
              label={t("owner.llm.token")}
              hint={s.llmKeySet && s.llmKeySource ? `${t("owner.llmReady")}: ${t(`owner.llm.source.${s.llmKeySource}`)}` : undefined}
            >
              <input
                type="password"
                autoComplete="new-password"
                spellCheck={false}
                className={`${inputClass} ltr`}
                value={llmToken}
                placeholder={s.llmKeySet ? t("owner.llm.tokenSaved", { hint: s.llmKeyHint ?? "" }) : t("owner.llm.tokenNew")}
                onChange={(e) => setLlmToken(e.target.value)}
              />
            </Field>
            <Field label={t("owner.llm.model")} hint={t("owner.llm.modelHint")}>
              <input className={`${inputClass} ltr`} value={llmModel ?? s.llmModel ?? ""} onChange={(e) => setLlmModel(e.target.value)} />
            </Field>
            <p className="text-xs text-ink-3">{t("owner.llm.privacy")}</p>
            <div className="flex flex-wrap gap-2">
              <Button variant="primary" busy={busy === "llm"} disabled={!llmToken.trim() && llmModel === null} onClick={saveLlm}>
                {t("owner.llm.save")}
              </Button>
              {s.llmKeySource === "settings" && (
                <Button variant="danger" busy={busy === "llmRemove"} onClick={removeLlmToken}>
                  {t("owner.llm.remove")}
                </Button>
              )}
            </div>
          </div>
        </Card>

        <Card title={t("owner.bale.title")}>
          <p className="mb-3 text-xs text-ink-2">{t("owner.bale.hint")}</p>
          <div className="space-y-3">
            <Field
              label={t("owner.bale.token")}
              hint={s.baleKeySet && s.baleKeySource ? `${t("owner.bale.ready")}: ${t(`owner.bale.source.${s.baleKeySource}`)}` : undefined}
            >
              <input
                type="password"
                autoComplete="new-password"
                spellCheck={false}
                className={`${inputClass} ltr`}
                value={baleToken}
                placeholder={s.baleKeySet ? t("owner.bale.tokenSaved", { hint: s.baleKeyHint ?? "" }) : t("owner.bale.tokenNew")}
                onChange={(e) => setBaleToken(e.target.value)}
              />
            </Field>
            <p className="text-xs text-ink-3">{t("owner.bale.privacy")}</p>
            <div className="flex flex-wrap gap-2">
              <Button variant="primary" busy={busy === "bale"} disabled={!baleToken.trim()} onClick={saveBale}>
                {t("owner.bale.save")}
              </Button>
              <Button disabled={!s.baleKeySet || busy !== null} busy={busy === "baleCheck"} onClick={checkBale}>
                {t("owner.bale.check")}
              </Button>
              {s.baleKeySource === "settings" && (
                <Button variant="danger" busy={busy === "baleRemove"} onClick={removeBaleToken}>
                  {t("owner.bale.remove")}
                </Button>
              )}
            </div>
          </div>
        </Card>

        <Card title={t("owner.settings")}>
          <div className="space-y-3">
            <Field label={t("owner.projectName")}>
              <input className={inputClass} value={f.projectName ?? ""} onChange={(e) => setForm({ ...form, projectName: e.target.value })} />
            </Field>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
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

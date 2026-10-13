import { useState } from "react";
import { Badge } from "@/components/Badge";
import { Button, Card, Empty, ErrorBox, Field, Loading, Modal, Mono, inputClass } from "@/components/ui";
import { useT, useToast } from "@/components/Providers";
import { api, useFetch } from "@/lib/api";
import type { Agent, DepartmentMenuItem } from "@/lib/types";

/** The slug the AI agent system uses for a department: hr, production, or dept-{id}. */
const slugOf = (d: DepartmentMenuItem) => (d.code === "HR" ? "hr" : d.code === "PRODUCTION" ? "production" : `dept-${d.id}`);

/**
 * AI agents: virtual users, labelled as agents in the users list. They do not sign in and receive no operations. They do
 * jobs: a task is run by the AI, reading only the departments the agent was given, and the result is sent to whoever
 * gave it. The owner makes and manages them here; managers make them by telling the assistant.
 */
export function AgentsPanel() {
  const { t, date } = useT();
  const toast = useToast();
  const agents = useFetch<Agent[]>("/agents", 8000);
  const menu = useFetch<DepartmentMenuItem[]>("/departments/menu");
  const [form, setForm] = useState<{ id: string | null; name: string; instructions: string; scope: string[] } | null>(null);
  const [taskFor, setTaskFor] = useState<Agent | null>(null);
  const [task, setTask] = useState("");
  const [busy, setBusy] = useState(false);

  if (agents.loading || menu.loading) return <Loading />;
  if (agents.error || !agents.data || !menu.data) return <ErrorBox message={agents.error ?? menu.error ?? t("common.error")} />;

  const deptName = new Map(menu.data.map((d) => [slugOf(d), d.name]));
  const guard = async (fn: () => Promise<void>, done: string) => {
    setBusy(true);
    try {
      await fn();
      await agents.reload();
      toast(t(done));
    } catch (e) {
      toast(e instanceof Error ? e.message : String(e), "error");
    } finally {
      setBusy(false);
    }
  };

  const openCreate = () => setForm({ id: null, name: "", instructions: "", scope: menu.data!.map(slugOf) });
  const openEdit = (a: Agent) => setForm({ id: a.id, name: a.name, instructions: a.instructions ?? "", scope: a.scope });
  const toggleScope = (slug: string) =>
    setForm((f) => f && { ...f, scope: f.scope.includes(slug) ? f.scope.filter((s) => s !== slug) : [...f.scope, slug] });

  const save = () =>
    guard(async () => {
      const f = form!;
      if (f.id === null) await api("/agents", { method: "POST", body: { name: f.name, instructions: f.instructions, scope: f.scope } });
      else await api(`/agents/${encodeURIComponent(f.id)}`, { method: "PATCH", body: { name: f.name, instructions: f.instructions, scope: f.scope } });
      setForm(null);
    }, "common.saved");

  const setActive = (a: Agent, active: boolean) =>
    guard(async () => {
      await api(`/agents/${encodeURIComponent(a.id)}`, { method: "PATCH", body: { active } });
    }, "common.saved");

  const give = () =>
    guard(async () => {
      await api(`/agents/${encodeURIComponent(taskFor!.id)}/tasks`, { method: "POST", body: { task } });
      setTaskFor(null);
      setTask("");
    }, "agents.taskGiven");

  return (
    <>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
        <p className="max-w-3xl text-sm text-ink-2">{t("agents.sub")}</p>
        <Button variant="primary" onClick={openCreate}>{t("agents.add")}</Button>
      </div>

      {agents.data.length === 0 ? (
        <Card>
          <Empty>{t("agents.empty")}</Empty>
        </Card>
      ) : (
        <div className="grid gap-4 xl:grid-cols-2">
          {agents.data.map((a) => (
            <Card
              key={a.id}
              title={
                <span className="flex flex-wrap items-center gap-2">
                  {a.name} <Badge tone="agent">{t("agents.badge")}</Badge>
                  {!a.active && <Badge>{t("agents.inactive")}</Badge>}
                </span>
              }
              action={
                <div className="flex gap-1">
                  <Button variant="ghost" disabled={busy} onClick={() => openEdit(a)}>{t("agents.edit")}</Button>
                  <Button variant="ghost" disabled={busy} onClick={() => setActive(a, !a.active)}>
                    {a.active ? t("agents.deactivate") : t("agents.activate")}
                  </Button>
                  <Button variant="primary" disabled={busy || !a.active} onClick={() => setTaskFor(a)}>{t("agents.give")}</Button>
                </div>
              }
            >
              <p className="text-xs text-ink-3"><Mono>{a.id}</Mono> · {t("agents.createdBy")}: <Mono>{a.createdBy}</Mono></p>
              <p className="mt-2 whitespace-pre-line text-sm text-ink-2">{a.instructions}</p>
              <p className="mt-2 text-xs text-ink-3">
                {t("agents.scope")}: {a.scope.length === 0 ? t("agents.noScope") : a.scope.map((s) => deptName.get(s) ?? s).join("، ")}
              </p>

              <div className="mt-3 border-t border-line pt-2">
                {a.tasks.length === 0 ? (
                  <p className="text-xs text-ink-3">{t("agents.noTasks")}</p>
                ) : (
                  <ul className="space-y-2">
                    {a.tasks.map((k) => (
                      <li key={k.id} className="text-sm">
                        <div className="flex items-start justify-between gap-2">
                          <span className="min-w-0 flex-1 truncate">{k.instruction}</span>
                          <span className={`shrink-0 rounded-full px-2 py-0.5 text-xs ${k.status === "FAILED" ? "bg-bad-soft text-bad" : k.status === "DONE" ? "bg-surface-2 text-good-ink" : "bg-warn-soft text-ink"}`}>
                            {t(`agents.status.${k.status}`)}
                          </span>
                        </div>
                        <div className="text-xs text-ink-3">{date(k.createdAt)}</div>
                        {(k.result || k.error) && (
                          <details className="mt-1">
                            <summary className="cursor-pointer text-xs text-accent">{t("agents.result")}</summary>
                            <p className="mt-1 whitespace-pre-line rounded-lg bg-surface-2 p-2 text-xs text-ink-2">{k.result ?? k.error}</p>
                          </details>
                        )}
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            </Card>
          ))}
        </div>
      )}

      <Modal
        open={form !== null}
        title={form?.id ? t("agents.edit") : t("agents.add")}
        onClose={() => setForm(null)}
        footer={
          <>
            <Button onClick={() => setForm(null)}>{t("common.cancel")}</Button>
            <Button variant="primary" busy={busy} disabled={!form?.name.trim() || !form?.instructions.trim()} onClick={save}>
              {t("common.save")}
            </Button>
          </>
        }
      >
        {form && (
          <div className="space-y-3">
            <Field label={t("agents.name")}>
              <input className={inputClass} value={form.name} maxLength={100} onChange={(e) => setForm({ ...form, name: e.target.value })} />
            </Field>
            <Field label={t("agents.instructions")} hint={t("agents.instructionsHint")}>
              <textarea className={inputClass} rows={4} maxLength={1000} value={form.instructions} onChange={(e) => setForm({ ...form, instructions: e.target.value })} />
            </Field>
            <div>
              <div className="mb-1 text-xs font-medium text-ink-2">{t("agents.scope")}</div>
              <ul className="space-y-1">
                {menu.data!.map((d) => (
                  <li key={d.id}>
                    <label className="flex cursor-pointer items-center gap-2 text-sm">
                      <input type="checkbox" className="size-4 accent-[var(--accent)]" checked={form.scope.includes(slugOf(d))} onChange={() => toggleScope(slugOf(d))} />
                      {d.name}
                    </label>
                  </li>
                ))}
              </ul>
            </div>
          </div>
        )}
      </Modal>

      <Modal
        open={taskFor !== null}
        title={taskFor ? `${t("agents.give")}: ${taskFor.name}` : ""}
        onClose={() => setTaskFor(null)}
        footer={
          <>
            <Button onClick={() => setTaskFor(null)}>{t("common.cancel")}</Button>
            <Button variant="primary" busy={busy} disabled={!task.trim()} onClick={give}>{t("agents.send")}</Button>
          </>
        }
      >
        <Field label={t("agents.task")} hint={t("agents.taskHint")}>
          <textarea className={inputClass} rows={4} maxLength={1000} value={task} onChange={(e) => setTask(e.target.value)} />
        </Field>
      </Modal>
    </>
  );
}

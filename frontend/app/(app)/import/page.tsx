"use client";

import Link from "next/link";
import { useMemo, useRef, useState } from "react";
import { DateField } from "@/components/DateField";
import { ImpactPanel } from "@/components/ImpactPanel";
import { RankingEditor } from "@/components/RankingEditor";
import { Banner, Button, Card, Empty, Field, PageHeader, inputClass } from "@/components/ui";
import { useT, useToast } from "@/components/Providers";
import { upload, useFetch } from "@/lib/api";
import type { ImportLog, ImportResult, ProjectSummary } from "@/lib/types";

type Target = "existing" | "new";

export default function ImportPage() {
  const { t, lang, date, num } = useT();
  const toast = useToast();
  const history = useFetch<ImportLog[]>("/import/history");
  const projects = useFetch<ProjectSummary[]>("/projects");
  const input = useRef<HTMLInputElement>(null);
  const [file, setFile] = useState<File | null>(null);
  const [result, setResult] = useState<ImportResult | null>(null);
  const [busy, setBusy] = useState<"validate" | "apply" | "preview" | null>(null);

  const active = useMemo(() => (projects.data ?? []).filter((p) => p.status === "ACTIVE"), [projects.data]);
  const [target, setTarget] = useState<Target | null>(null);
  const [existingId, setExistingId] = useState("");
  const [newName, setNewName] = useState("");
  const [newId, setNewId] = useState("");
  const [due, setDue] = useState("");
  const [ranking, setRanking] = useState<string[] | null>(null);

  // sensible defaults: the only active project, otherwise a new project
  const mode: Target = target ?? (active.length === 1 ? "existing" : "new");
  const projectId = mode === "existing" ? existingId || (active.length === 1 ? active[0].id : "") : "";
  const needsChoice = mode === "existing" && !projectId;
  const needsName = mode === "new" && active.length > 0 && !newName.trim();

  const run = async (kind: "validate" | "apply" | "preview", order: string[] | null = ranking) => {
    if (!file) return;
    setBusy(kind);
    try {
      const q = new URLSearchParams({ apply: String(kind === "apply") });
      if (mode === "existing" && projectId) q.set("projectId", projectId);
      if (mode === "new") {
        if (newName.trim()) q.set("newProjectName", newName.trim());
        if (newId.trim()) q.set("newProjectId", newId.trim());
        if (due.trim()) q.set("dueDate", due.trim());
        if (order) q.set("ranking", order.join(","));
      }
      const form = new FormData();
      form.append("file", file);
      const r = await upload<ImportResult>(`/import?${q.toString()}`, form);
      setResult(r);
      if (r.status === "PRIORITY_REQUIRED" && !order) {
        setRanking([...r.activeProjects.map((p) => p.id), "NEW"]); // new project last until the manager decides
      }
      history.reload();
      if (r.status === "APPLIED") {
        projects.reload();
        toast(t("imp.applied"));
      }
    } catch (e) {
      toast(e instanceof Error ? e.message : String(e), "error");
    } finally {
      setBusy(null);
    }
  };

  const onFile = (f: File | null) => {
    setFile(f);
    setResult(null);
    setRanking(null);
  };

  const rankItems = (result?.activeProjects ?? []).length
    ? (ranking ?? []).map((id) => {
        const p = result!.activeProjects.find((x) => x.id === id);
        return id === "NEW"
          ? { id, name: result!.projectName ?? newName, badge: t("prio.newBadge"), detail: t("prio.newDetail") }
          : { id, name: p?.name ?? id, detail: `${t("prio.rank")} ${num(p?.priority ?? 0, 0)}` };
      })
    : [];
  const askPriorities = result?.newProject && result.activeProjects.length > 0 && ranking && result.status !== "REJECTED" && result.status !== "APPLIED";

  return (
    <>
      <PageHeader
        title={t("imp.title")}
        sub={t("imp.sub")}
        actions={
          <a href={`/api/import/template?lang=${lang}`} download className="rounded-lg border border-line-strong bg-surface px-3.5 py-2 text-sm font-medium hover:bg-surface-2">
            {t("imp.template")}
          </a>
        }
      />

      <Card title={t("imp.target")}>
        <div className="grid gap-4 md:grid-cols-2">
          <label className={`block cursor-pointer rounded-lg border p-3 ${mode === "existing" ? "border-accent bg-accent-soft" : "border-line"} ${active.length === 0 ? "opacity-50" : ""}`}>
            <span className="flex items-center gap-2 text-sm font-medium">
              <input type="radio" name="target" checked={mode === "existing"} disabled={active.length === 0} onChange={() => { setTarget("existing"); setResult(null); setRanking(null); }} className="accent-[var(--accent)]" />
              {t("imp.existingProject")}
            </span>
            <select className={`${inputClass} mt-2`} aria-label={t("imp.existingProject")} disabled={mode !== "existing"} value={projectId} onChange={(e) => { setExistingId(e.target.value); setResult(null); }}>
              <option value="">—</option>
              {active.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.priority}. {p.name}
                </option>
              ))}
            </select>
          </label>

          <label className={`block cursor-pointer rounded-lg border p-3 ${mode === "new" ? "border-accent bg-accent-soft" : "border-line"}`}>
            <span className="flex items-center gap-2 text-sm font-medium">
              <input type="radio" name="target" checked={mode === "new"} onChange={() => { setTarget("new"); setResult(null); setRanking(null); }} className="accent-[var(--accent)]" />
              {t("imp.newProject")}
            </span>
            <div className="mt-2 grid gap-2 sm:grid-cols-2">
              <Field label={t("proj.name")}>
                <input className={inputClass} disabled={mode !== "new"} value={newName} onChange={(e) => { setNewName(e.target.value); setResult(null); setRanking(null); }} />
              </Field>
              <Field label={t("imp.projectIdOptional")}>
                <input className={`${inputClass} ltr`} disabled={mode !== "new"} value={newId} onChange={(e) => { setNewId(e.target.value); setResult(null); }} />
              </Field>
              <div className="sm:col-span-2">
                <Field label={t("proj.dueOptional")}>
                  <DateField label={t("proj.due")} value={due} onChange={setDue} />
                </Field>
              </div>
            </div>
          </label>
        </div>
        {active.length > 0 && mode === "new" && <p className="mt-3 text-xs text-ink-3">{t("imp.priorityWillBeAsked")}</p>}
      </Card>

      <div className="mt-4" />
      <Card title={t("imp.upload")}>
        <div className="flex flex-wrap items-center gap-3">
          <input
            ref={input}
            type="file"
            accept=".xlsx,.xls"
            aria-label={t("imp.upload")}
            onChange={(e) => onFile(e.target.files?.[0] ?? null)}
            className="max-w-full text-sm file:me-3 file:rounded-lg file:border file:border-line-strong file:bg-surface file:px-3 file:py-2 file:text-sm file:font-medium"
          />
          <Button busy={busy === "validate"} disabled={!file || needsChoice || needsName} onClick={() => run("validate", null)}>
            {t("imp.validate")}
          </Button>
          <Button variant="primary" busy={busy === "apply"} disabled={!file || needsChoice || needsName || result?.status === "REJECTED" || !!askPriorities}
            onClick={() => run("apply")}>
            {t("imp.apply")}
          </Button>
        </div>
        <p className="mt-3 text-xs text-ink-3">{t("imp.formats")}</p>
      </Card>

      {result && (
        <div className="mt-4 space-y-4">
          {result.status === "REJECTED" && <Banner tone="error">{t("imp.rejected", { n: num(result.errors.length, 0) })}</Banner>}
          {result.status === "VALID" && <Banner tone="ok">{t("imp.valid")}</Banner>}
          {result.status === "APPLIED" && (
            <Banner tone="ok">
              {t("imp.applied")} · {result.projectName}{" "}
              <Link href="/manager/projects" className="font-medium underline">{t("nav.projects")}</Link>
            </Banner>
          )}
          {result.status === "PRIORITY_REQUIRED" && <Banner tone="warn">{t("imp.priorityRequired")}</Banner>}

          {askPriorities && (
            <Card
              title={t("prio.title")}
              action={
                <div className="flex flex-wrap gap-2">
                  <Button busy={busy === "preview"} onClick={() => run("preview")}>{t("prio.preview")}</Button>
                  <Button variant="primary" busy={busy === "apply"} onClick={() => run("apply")}>{t("imp.applyWithPriority")}</Button>
                </div>
              }
            >
              <p className="mb-3 text-sm text-ink-2">{t("prio.help")}</p>
              <RankingEditor
                items={rankItems}
                onChange={(ids) => {
                  setRanking(ids);
                  run("preview", ids);
                }}
              />
            </Card>
          )}

          {result.impact && result.status !== "REJECTED" && result.status !== "APPLIED" && (
            <div className="space-y-3">
              <h2 className="text-sm font-semibold">{t("prio.previewTitle")}</h2>
              <ImpactPanel impact={result.impact} />
            </div>
          )}

          {result.errors.length > 0 && (
            <Card title={t("imp.errors")}>
              <div className="overflow-x-auto">
                <table className="w-full min-w-[560px] text-sm">
                  <thead>
                    <tr className="border-b border-line text-xs text-ink-2">
                      <th className="px-2 py-2 text-start font-medium">{t("imp.sheet")}</th>
                      <th className="px-2 py-2 text-start font-medium">{t("imp.row")}</th>
                      <th className="px-2 py-2 text-start font-medium">{t("imp.column")}</th>
                      <th className="px-2 py-2 text-start font-medium">{t("imp.problem")}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {result.errors.map((e, i) => (
                      <tr key={i} className="border-b border-line align-top last:border-0">
                        <td className="px-2 py-2">{e.sheet}</td>
                        <td className="px-2 py-2 tabular-nums">{num(e.row, 0)}</td>
                        <td className="px-2 py-2">{e.column}</td>
                        <td className="px-2 py-2">{e.message}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </Card>
          )}

          {result.warnings.length > 0 && (
            <Card title={t("imp.warnings")}>
              <ul className="list-disc space-y-1 ps-5 text-sm text-ink-2">
                {result.warnings.map((w, i) => (
                  <li key={i}>{w}</li>
                ))}
              </ul>
            </Card>
          )}

          {result.counts.length > 0 && result.status !== "REJECTED" && (
            <Card title={result.status === "APPLIED" ? t("imp.summary") : t("imp.wouldImport")}>
              <ul className="grid gap-2 sm:grid-cols-5">
                {result.counts.map((c) => (
                  <li key={c.entity} className="rounded-lg border border-line p-3 text-sm">
                    <div className="text-xs text-ink-2">{t(`imp.entity.${c.entity}`)}</div>
                    <div className="font-semibold tabular-nums">
                      +{num(c.created, 0)} <span className="text-ink-3">/ ~{num(c.updated, 0)}</span>
                    </div>
                  </li>
                ))}
              </ul>
            </Card>
          )}

          {result.newUsers.length > 0 && (
            <Card title={t("imp.newUsers")}>
              <Banner tone="warn">{t("imp.newUsersHint")}</Banner>
              <table className="mt-3 w-full text-sm">
                <tbody>
                  {result.newUsers.map((u) => (
                    <tr key={u.userId} className="border-b border-line last:border-0">
                      <td className="px-2 py-2">{u.fullName}</td>
                      <td className="px-2 py-2 font-mono text-xs"><span className="ltr">{u.userId}</span></td>
                      <td className="px-2 py-2 font-mono text-xs"><span className="ltr select-all">{u.temporaryPassword}</span></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </Card>
          )}
        </div>
      )}

      <Card title={t("imp.history")} className="mt-4">
        {(history.data?.length ?? 0) === 0 ? (
          <Empty>{t("common.none")}</Empty>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[560px] text-sm">
              <tbody>
                {history.data!.map((l) => (
                  <tr key={l.id} className="border-b border-line align-top last:border-0">
                    <td className="whitespace-nowrap px-2 py-2 text-xs text-ink-2">{date(l.importedAt)}</td>
                    <td className="px-2 py-2">{l.fileName}</td>
                    <td className="px-2 py-2 text-xs">{l.format}</td>
                    <td className="px-2 py-2">
                      <span className={l.status === "REJECTED" ? "font-medium text-bad" : ""}>{t(`imp.status.${l.status}`)}</span>
                      {l.errorCount > 0 && <span className="ms-1 text-xs text-ink-3">({num(l.errorCount, 0)})</span>}
                      {l.details && (
                        <details className="mt-1">
                          <summary className="cursor-pointer text-xs text-accent">{t("imp.details")}</summary>
                          <pre className="ltr mt-1 max-h-40 overflow-auto whitespace-pre-wrap text-xs text-ink-2">{l.details}</pre>
                        </details>
                      )}
                    </td>
                    <td className="px-2 py-2 text-xs text-ink-3">{l.actorId}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </>
  );
}

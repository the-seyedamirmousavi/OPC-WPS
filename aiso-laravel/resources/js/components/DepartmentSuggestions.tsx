import { useState } from "react";
import { Button, Card, Empty, ErrorBox, Loading, Modal, inputClass } from "@/components/ui";
import { useT, useToast } from "@/components/Providers";
import { api, useFetch } from "@/lib/api";
import type { DepartmentSuggestion } from "@/lib/types";

/** Suggestions the departments sent the manager, waiting for a decision. Shown on the manager dashboard. */
export function DepartmentSuggestions() {
  const { t, date } = useT();
  const toast = useToast();
  const { data, error, loading, reload } = useFetch<DepartmentSuggestion[]>("/department-suggestions?status=PENDING", 15000);
  const [deciding, setDeciding] = useState<{ item: DepartmentSuggestion; decision: "ACCEPTED" | "REJECTED" } | null>(null);
  const [note, setNote] = useState("");
  const [busy, setBusy] = useState(false);

  if (loading) return <Loading />;
  if (error || !data) return <ErrorBox message={error ?? t("common.error")} />;

  const decide = async () => {
    if (!deciding) return;
    setBusy(true);
    try {
      await api(`/department-suggestions/${deciding.item.id}/decide`, {
        method: "POST",
        body: { decision: deciding.decision, note: note.trim() || undefined },
      });
      setDeciding(null);
      setNote("");
      await reload();
      toast(t("mgr.sug.decided"));
    } catch (e) {
      toast(e instanceof Error ? e.message : String(e), "error");
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <Card
        title={t("mgr.sug.title")}
        className="mb-4"
        action={data.length > 0 ? <span className="rounded-full bg-warn-soft px-2 py-0.5 text-xs text-ink">{data.length}</span> : null}
      >
        <p className="mb-3 text-xs text-ink-3">{t("mgr.sug.sub")}</p>
        {data.length === 0 ? (
          <Empty>{t("mgr.sug.empty")}</Empty>
        ) : (
          <ul className="divide-y divide-line">
            {data.map((s) => (
              <li key={s.id} className="flex flex-wrap items-start gap-3 py-3">
                <div className="min-w-0 flex-1">
                  <div className="text-sm font-medium">{s.title}</div>
                  <div className="mt-0.5 text-xs text-ink-3">
                    {t("mgr.sug.from", { dept: s.departmentName ?? "", author: s.authorName ?? s.authorId })}
                    {s.resourceName && ` · ${s.resourceName}`}
                    {` · ${date(s.createdAt)}`}
                  </div>
                  <p className="mt-1 whitespace-pre-line text-sm text-ink-2">{s.body}</p>
                </div>
                <div className="flex shrink-0 gap-2">
                  <Button variant="primary" disabled={busy} onClick={() => setDeciding({ item: s, decision: "ACCEPTED" })}>
                    {t("mgr.sug.accept")}
                  </Button>
                  <Button variant="secondary" disabled={busy} onClick={() => setDeciding({ item: s, decision: "REJECTED" })}>
                    {t("mgr.sug.reject")}
                  </Button>
                </div>
              </li>
            ))}
          </ul>
        )}
      </Card>

      <Modal
        open={deciding !== null}
        title={deciding ? `${deciding.decision === "ACCEPTED" ? t("mgr.sug.accept") : t("mgr.sug.reject")}: ${deciding.item.title}` : ""}
        onClose={() => setDeciding(null)}
        footer={
          <>
            <Button onClick={() => setDeciding(null)}>{t("common.cancel")}</Button>
            <Button
              variant={deciding?.decision === "REJECTED" ? "danger" : "primary"}
              busy={busy}
              onClick={decide}
            >
              {t("common.confirm")}
            </Button>
          </>
        }
      >
        <textarea
          className={inputClass}
          rows={3}
          aria-label={t("mgr.sug.noteLabel")}
          placeholder={t("mgr.sug.noteLabel")}
          value={note}
          onChange={(e) => setNote(e.target.value)}
        />
      </Modal>
    </>
  );
}

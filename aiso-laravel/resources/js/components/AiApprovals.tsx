import { useState } from "react";
import { Button, Card, Empty, ErrorBox, Field, Loading, Modal, Mono, inputClass } from "@/components/ui";
import { useT, useToast } from "@/components/Providers";
import { api, useFetch } from "@/lib/api";
import type { AiApproval } from "@/lib/types";

/**
 * Requests the assistant made for someone and that need the owner's approval. Approving runs the action as the person who
 * asked, after the server checks again that they may and that the owner's policy still allows it.
 */
export function AiApprovals() {
  const { t, date } = useT();
  const toast = useToast();
  const { data, error, loading, reload } = useFetch<AiApproval[]>("/ai/approvals", 15000);
  const [deciding, setDeciding] = useState<{ item: AiApproval; approve: boolean } | null>(null);
  const [note, setNote] = useState("");
  const [busy, setBusy] = useState(false);

  if (loading) return <Loading />;
  if (error || !data) return <ErrorBox message={error ?? t("common.error")} />;

  const decide = async () => {
    if (!deciding) return;
    setBusy(true);
    try {
      await api(`/ai/approvals/${deciding.item.id}/${deciding.approve ? "approve" : "reject"}`, { method: "POST", body: { note: note.trim() || undefined } });
      setDeciding(null);
      setNote("");
      toast(t(deciding.approve ? "aiappr.approved" : "aiappr.rejected"));
    } catch (e) {
      toast(e instanceof Error ? e.message : String(e), "error");
    } finally {
      setBusy(false);
      await reload();
    }
  };

  const label = (op: string) => {
    const v = t(`chat.action.${op}`);
    return v === `chat.action.${op}` ? op : v;
  };

  return (
    <>
      <Card
        title={t("aiappr.title")}
        action={data.length > 0 ? <span className="rounded-full bg-warn-soft px-2 py-0.5 text-xs text-ink">{data.length}</span> : null}
      >
        <p className="mb-3 text-xs text-ink-3">{t("aiappr.sub")}</p>
        {data.length === 0 ? (
          <Empty>{t("aiappr.empty")}</Empty>
        ) : (
          <ul className="divide-y divide-line">
            {data.map((a) => (
              <li key={a.id} className="flex flex-wrap items-start gap-3 py-3">
                <div className="min-w-0 flex-1">
                  <div className="text-sm font-medium">{label(a.operation)}</div>
                  <div className="mt-0.5 text-xs text-ink-3">
                    {t("aiappr.by", { name: a.requested_by_name })} · {t(`chat.risk.${a.risk}`)} · {date(a.expires_at)}
                  </div>
                  <p className="mt-1 text-xs text-ink-2 ltr">
                    <Mono>{Object.entries(a.params).map(([k, v]) => `${k}: ${String(v)}`).join(" · ")}</Mono>
                  </p>
                  {a.reason && <p className="mt-1 text-xs text-ink-3">{a.reason}</p>}
                </div>
                <div className="flex shrink-0 gap-2">
                  <Button variant="primary" onClick={() => setDeciding({ item: a, approve: true })}>{t("aiappr.approve")}</Button>
                  <Button onClick={() => setDeciding({ item: a, approve: false })}>{t("aiappr.reject")}</Button>
                </div>
              </li>
            ))}
          </ul>
        )}
      </Card>

      <Modal
        open={deciding !== null}
        title={deciding ? `${deciding.approve ? t("aiappr.approve") : t("aiappr.reject")}: ${label(deciding.item.operation)}` : ""}
        onClose={() => setDeciding(null)}
        footer={
          <>
            <Button onClick={() => setDeciding(null)}>{t("common.cancel")}</Button>
            <Button variant={deciding?.approve ? "primary" : "danger"} busy={busy} onClick={decide}>{t("common.confirm")}</Button>
          </>
        }
      >
        <Field label={t("aiappr.note")}>
          <textarea className={inputClass} rows={3} maxLength={500} value={note} onChange={(e) => setNote(e.target.value)} />
        </Field>
      </Modal>
    </>
  );
}

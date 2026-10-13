import { useState } from "react";
import { Button, Card, ErrorBox, Loading, inputClass } from "@/components/ui";
import { useT, useToast } from "@/components/Providers";
import { api, useFetch } from "@/lib/api";
import type { AiPolicies, AiPolicyMode } from "@/lib/types";

const KINDS = ["production", "hr", "general"] as const;

/**
 * What the owner lets the assistant do. A master switch turns every write off (the assistant only reads). Each action is
 * disabled, confirmed by the person who asked, or needs the owner's approval. Without a choice the mode follows the risk.
 */
export function AiPermissions() {
  const { t } = useT();
  const toast = useToast();
  const { data, error, loading, reload } = useFetch<AiPolicies>("/ai/policies");
  const [draft, setDraft] = useState<Record<string, AiPolicyMode>>({});
  const [master, setMaster] = useState<boolean | null>(null);
  const [busy, setBusy] = useState(false);

  if (loading) return <Loading />;
  if (error || !data) return <ErrorBox message={error ?? t("common.error")} />;

  const key = (kind: string, action: string) => `${kind}|${action}`;
  const modeOf = (kind: string, action: string, current: AiPolicyMode) => draft[key(kind, action)] ?? current;
  const writes = master ?? data.writesEnabled;
  const dirty = master !== null || Object.keys(draft).length > 0;

  const save = async () => {
    setBusy(true);
    try {
      await api("/ai/policies", {
        method: "PUT",
        body: {
          writesEnabled: writes,
          actions: data.actions.map((a) => ({ kind: a.kind, action: a.action, mode: modeOf(a.kind, a.action, a.mode) })),
        },
      });
      setDraft({});
      setMaster(null);
      await reload();
      toast(t("common.saved"));
    } catch (e) {
      toast(e instanceof Error ? e.message : String(e), "error");
    } finally {
      setBusy(false);
    }
  };

  const label = (op: string) => {
    const v = t(`chat.action.${op}`);
    return v === `chat.action.${op}` ? op : v;
  };

  return (
    <Card title={t("aiperm.title")}>
      <p className="mb-3 text-xs text-ink-2">{t("aiperm.hint")}</p>
      <label className="mb-4 flex items-start gap-2 rounded-lg border border-line p-3 text-sm">
        <input type="checkbox" className="mt-1 size-4 accent-[var(--accent)]" checked={writes} onChange={(e) => setMaster(e.target.checked)} />
        <span>
          {t("aiperm.master")}
          <span className="block text-xs text-ink-3">{t("aiperm.masterHint")}</span>
        </span>
      </label>

      <div className={writes ? "" : "pointer-events-none opacity-50"}>
        {KINDS.map((kind) => {
          const rows = data.actions.filter((a) => a.kind === kind);
          if (rows.length === 0) return null;
          return (
            <div key={kind} className="mb-4">
              <div className="mb-1 text-xs font-medium text-ink-2">{t(`aiperm.kind.${kind}`)}</div>
              <table className="w-full text-sm">
                <tbody>
                  {rows.map((a) => (
                    <tr key={a.action} className="border-b border-line last:border-0">
                      <td className="px-2 py-2">{label(a.action)}</td>
                      <td className="px-2 py-2 text-xs text-ink-3">{t(`aiperm.risk.${a.risk}`)}</td>
                      <td className="px-2 py-2 text-end">
                        <select
                          className={`${inputClass} w-auto`}
                          aria-label={label(a.action)}
                          value={modeOf(a.kind, a.action, a.mode)}
                          onChange={(e) => setDraft({ ...draft, [key(a.kind, a.action)]: e.target.value as AiPolicyMode })}
                        >
                          <option value="DENY">{t("aiperm.mode.DENY")}</option>
                          <option value="SELF">{t("aiperm.mode.SELF")}</option>
                          <option value="OWNER">{t("aiperm.mode.OWNER")}</option>
                          {a.risk === "low" && <option value="AUTO">{t("aiperm.mode.AUTO")}</option>}
                        </select>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          );
        })}
      </div>

      <div className="flex justify-end gap-2">
        <Button disabled={!dirty || busy} onClick={() => { setDraft({}); setMaster(null); }}>{t("common.cancel")}</Button>
        <Button variant="primary" busy={busy} disabled={!dirty} onClick={save}>{t("common.save")}</Button>
      </div>
    </Card>
  );
}

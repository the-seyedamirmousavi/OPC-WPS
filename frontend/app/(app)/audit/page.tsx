"use client";

import { useState } from "react";
import { Card, Empty, ErrorBox, Loading, Mono, PageHeader, inputClass } from "@/components/ui";
import { useT } from "@/components/Providers";
import { useFetch } from "@/lib/api";
import type { AuditEvent } from "@/lib/types";

/** Translated label, or the raw code when no translation exists. */
function label(t: (k: string) => string, key: string, fallback: string) {
  const v = t(key);
  return v === key ? fallback : v;
}

export default function AuditPage() {
  const { t, date } = useT();
  const [q, setQ] = useState("");
  const { data, error, loading } = useFetch<AuditEvent[]>("/audit?limit=300", 30000);

  if (loading) return <Loading />;
  if (error || !data) return <ErrorBox message={error ?? t("common.error")} />;

  const needle = q.trim().toLowerCase();
  const rows = needle
    ? data.filter((e) => [e.action, e.entityType, e.entityId, e.actorId, e.reason, e.previousValue, e.newValue].some((v) => v?.toLowerCase().includes(needle)))
    : data;

  return (
    <>
      <PageHeader title={t("audit.title")} sub={t("audit.sub")} />
      <Card>
        <input className={`${inputClass} mb-4 max-w-sm`} placeholder={t("common.search")} aria-label={t("common.search")} value={q} onChange={(e) => setQ(e.target.value)} />
        {rows.length === 0 ? (
          <Empty>{t("common.none")}</Empty>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[820px] text-sm">
              <thead>
                <tr className="border-b border-line text-xs text-ink-2">
                  <th className="px-2 py-2 text-start font-medium">{t("audit.time")}</th>
                  <th className="px-2 py-2 text-start font-medium">{t("audit.actor")}</th>
                  <th className="px-2 py-2 text-start font-medium">{t("audit.action")}</th>
                  <th className="px-2 py-2 text-start font-medium">{t("audit.entity")}</th>
                  <th className="px-2 py-2 text-start font-medium">{t("audit.change")}</th>
                  <th className="px-2 py-2 text-start font-medium">{t("common.reason")}</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((e) => (
                  <tr key={e.eventId} className="border-b border-line align-top last:border-0">
                    <td className="whitespace-nowrap px-2 py-2 text-xs text-ink-2">{date(e.occurredAt)}</td>
                    <td className="px-2 py-2"><Mono>{e.actorId ?? "—"}</Mono></td>
                    <td className="px-2 py-2 font-medium">{label(t, `audit.action.${e.action}`, e.action)}</td>
                    <td className="px-2 py-2"><Mono>{e.entityId ?? ""}</Mono> <span className="text-xs text-ink-3">{label(t, `audit.entity.${e.entityType}`, e.entityType)}</span></td>
                    <td className="max-w-72 px-2 py-2 text-xs text-ink-2">
                      {(e.previousValue || e.newValue) && (
                        <span className="ltr break-words">
                          {e.previousValue ?? "∅"} → {e.newValue ?? "∅"}
                        </span>
                      )}
                    </td>
                    <td className="max-w-56 px-2 py-2 text-xs text-ink-2">{e.reason}</td>
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

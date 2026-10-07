"use client";

import { useMemo, useState } from "react";
import { OperationDetailModal } from "@/components/OperationDetailModal";
import { OperationTable } from "@/components/OperationTable";
import { Card, ErrorBox, Loading, PageHeader, inputClass } from "@/components/ui";
import { useT } from "@/components/Providers";
import { useFetch } from "@/lib/api";
import { OP_STATUSES, type Operation, type OpStatus } from "@/lib/types";

export default function OperationsPage() {
  const { t, num } = useT();
  const { data, error, loading, reload } = useFetch<Operation[]>("/operations", 20000);
  const [status, setStatus] = useState<OpStatus | "">("");
  const [resource, setResource] = useState("");
  const [project, setProject] = useState("");
  const [user, setUser] = useState("");
  const [q, setQ] = useState("");
  const [open, setOpen] = useState<string | null>(null);

  const resources = useMemo(() => {
    const m = new Map<string, string>();
    data?.forEach((o) => m.set(o.resourceId, o.resourceName));
    return [...m.entries()];
  }, [data]);
  const projectList = useMemo(() => {
    const m = new Map<string, string>();
    data?.forEach((o) => m.set(o.projectId, o.projectName));
    return [...m.entries()];
  }, [data]);
  const users = useMemo(() => {
    const m = new Map<string, string>();
    data?.forEach((o) => o.assignedUserId && m.set(o.assignedUserId, o.assignedUserName ?? o.assignedUserId));
    return [...m.entries()];
  }, [data]);

  if (loading) return <Loading />;
  if (error || !data) return <ErrorBox message={error ?? t("common.error")} />;

  const needle = q.trim().toLowerCase();
  const rows = data.filter(
    (o) =>
      (!status || o.status === status) &&
      (!resource || o.resourceId === resource) &&
      (!project || o.projectId === project) &&
      (!user || o.assignedUserId === user) &&
      (!needle || o.id.toLowerCase().includes(needle) || o.name.toLowerCase().includes(needle)),
  );

  return (
    <>
      <PageHeader title={t("ops.title")} sub={t("ops.sub", { n: num(rows.length, 0), total: num(data.length, 0) })} />
      <Card>
        <div className="mb-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
          <input className={inputClass} placeholder={t("common.search")} aria-label={t("common.search")} value={q} onChange={(e) => setQ(e.target.value)} />
          <select className={inputClass} aria-label={t("op.status")} value={status} onChange={(e) => setStatus(e.target.value as OpStatus | "")}>
            <option value="">{t("ops.allStatuses")}</option>
            {OP_STATUSES.map((s) => (
              <option key={s} value={s}>
                {t(`status.${s}`)}
              </option>
            ))}
          </select>
          <select className={inputClass} aria-label={t("op.project")} value={project} onChange={(e) => setProject(e.target.value)}>
            <option value="">{t("ops.allProjects")}</option>
            {projectList.map(([id, name]) => (
              <option key={id} value={id}>
                {name}
              </option>
            ))}
          </select>
          <select className={inputClass} aria-label={t("op.resource")} value={resource} onChange={(e) => setResource(e.target.value)}>
            <option value="">{t("ops.allResources")}</option>
            {resources.map(([id, name]) => (
              <option key={id} value={id}>
                {name}
              </option>
            ))}
          </select>
          <select className={inputClass} aria-label={t("op.assignee")} value={user} onChange={(e) => setUser(e.target.value)}>
            <option value="">{t("ops.allUsers")}</option>
            {users.map(([id, name]) => (
              <option key={id} value={id}>
                {name}
              </option>
            ))}
          </select>
        </div>
        <OperationTable ops={rows} onOpen={setOpen} />
      </Card>
      <OperationDetailModal id={open} mode="manage" onClose={() => setOpen(null)} onChanged={reload} />
    </>
  );
}

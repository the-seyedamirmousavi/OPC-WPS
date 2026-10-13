import { useState } from "react";
import { AiPermissions } from "@/components/AiPermissions";
import { Button, Card, ErrorBox, Field, Loading, Modal, PageHeader, inputClass } from "@/components/ui";
import { useT, useToast } from "@/components/Providers";
import { api, useFetch } from "@/lib/api";
import { BUILTIN_ROLES, roleLabel } from "@/lib/capabilities";
import type { DepartmentPermission, DepartmentRole, Role } from "@/lib/types";

interface RoleRow {
  key: Role;
  name: string;
  description: string | null;
  receivesTasks: boolean;
  capabilities: string[];
  builtin: boolean;
  locked: boolean;
  total: number;
  active: number;
}

interface DepartmentRoles {
  id: number;
  name: string;
  code: string | null;
  status: "ACTIVE" | "INACTIVE";
  permissions: Record<DepartmentRole, DepartmentPermission[]>;
}

interface RolesOverview {
  roles: RoleRow[];
  capabilities: string[];
  departments: DepartmentRoles[];
  departmentRoles: DepartmentRole[];
  catalog: DepartmentPermission[];
}

interface RoleForm {
  name: string;
  description: string;
  receivesTasks: boolean;
  capabilities: string[];
}

type Matrix = Record<DepartmentRole, DepartmentPermission[]>;

const EMPTY_FORM: RoleForm = { name: "", description: "", receivesTasks: false, capabilities: [] };

/**
 * The owner's one place for roles and permissions. The company defines its own roles: a name, what they hold (the
 * capabilities), and whether their people receive tasks. The owner and the agent stay locked. Below: what each
 * department's head and member may do, saved per department; and what the assistant may do.
 */
export default function OwnerRolesPage() {
  const { t } = useT();
  const toast = useToast();
  const { data, error, loading, reload } = useFetch<RolesOverview>("/roles");
  // unsaved changes to the departments, per department; a department without an entry shows what is saved
  const [drafts, setDrafts] = useState<Record<number, Matrix>>({});
  const [busy, setBusy] = useState(false);
  // the role being edited: null when closed, "new" for a new role, otherwise the key of the role
  const [editing, setEditing] = useState<Role | "new" | null>(null);
  const [form, setForm] = useState<RoleForm>(EMPTY_FORM);

  if (loading) return <Loading />;
  if (error || !data) return <ErrorBox message={error ?? t("common.error")} />;

  const guard = async (fn: () => Promise<void>) => {
    setBusy(true);
    try {
      await fn();
    } catch (e) {
      toast(e instanceof Error ? e.message : String(e), "error");
    } finally {
      setBusy(false);
    }
  };

  const shown = (d: DepartmentRoles): Matrix => drafts[d.id] ?? d.permissions;

  const toggle = (d: DepartmentRoles, role: DepartmentRole, permission: DepartmentPermission) => {
    const base = shown(d);
    const list = base[role];
    const next = list.includes(permission) ? list.filter((p) => p !== permission) : [...list, permission];
    setDrafts((all) => ({ ...all, [d.id]: { ...base, [role]: next } }));
  };

  const saveDepartment = (d: DepartmentRoles) =>
    guard(async () => {
      await api(`/departments/${d.id}/permissions`, { method: "PUT", body: { permissions: drafts[d.id] } });
      setDrafts((all) => {
        const rest = { ...all };
        delete rest[d.id];

        return rest;
      });
      await reload();
      toast(t("common.saved"));
    });

  const openNew = () => {
    setForm(EMPTY_FORM);
    setEditing("new");
  };

  const openEdit = (r: RoleRow) => {
    setForm({ name: r.name, description: r.description ?? "", receivesTasks: r.receivesTasks, capabilities: r.capabilities });
    setEditing(r.key);
  };

  const toggleCapability = (c: string) =>
    setForm((f) => ({
      ...f,
      capabilities: f.capabilities.includes(c) ? f.capabilities.filter((x) => x !== c) : [...f.capabilities, c],
    }));

  const saveRole = () =>
    guard(async () => {
      const body = { name: form.name.trim(), description: form.description.trim() || null, receivesTasks: form.receivesTasks, capabilities: form.capabilities };
      if (editing === "new") {
        await api("/roles", { method: "POST", body });
        toast(t("roles.created"));
      } else {
        await api(`/roles/${encodeURIComponent(editing!)}`, { method: "PATCH", body });
        toast(t("roles.updated"));
      }
      setEditing(null);
      await reload();
    });

  const deleteRole = (r: RoleRow) => {
    if (!window.confirm(t("roles.deleteConfirm", { name: r.name }))) return;
    void guard(async () => {
      await api(`/roles/${encodeURIComponent(r.key)}`, { method: "DELETE" });
      await reload();
      toast(t("roles.deleted"));
    });
  };

  return (
    <>
      <PageHeader title={t("roles.title")} sub={t("roles.sub")} />

      <div className="grid grid-cols-1 gap-4">
        <Card
          title={t("roles.system")}
          action={<Button variant="primary" onClick={openNew}>{t("roles.add")}</Button>}
        >
          <p className="mb-3 text-xs text-ink-3">{t("roles.systemHint")}</p>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[640px] text-sm">
              <thead>
                <tr className="border-b border-line text-xs text-ink-2">
                  <th className="px-2 py-2 text-start font-medium">{t("roles.col.role")}</th>
                  <th className="px-2 py-2 text-center font-medium">{t("roles.col.users")}</th>
                  <th className="px-2 py-2 text-start font-medium">{t("roles.col.what")}</th>
                  <th className="px-2 py-2" />
                </tr>
              </thead>
              <tbody>
                {data.roles.map((r) => (
                  <tr key={r.key} className="border-b border-line align-top last:border-0">
                    <td className="px-2 py-2">
                      <div className="font-medium">{roleLabel(t, r.key, r.name)}</div>
                      {r.receivesTasks && <div className="text-xs text-ink-3">{t("roles.receivesTasksTag")}</div>}
                    </td>
                    <td className="px-2 py-2 text-center">
                      {r.total}
                      {r.total > 0 && <div className="text-xs text-ink-3">{t("roles.activeOf", { active: r.active })}</div>}
                    </td>
                    <td className="px-2 py-2 text-ink-2">
                      {BUILTIN_ROLES.includes(r.key) && <div>{t(`roles.desc.${r.key}`)}</div>}
                      {r.key === "OWNER" ? (
                        <span className="text-xs text-ink-3">{t("roles.ownerHolds")}</span>
                      ) : (
                        <div className="flex flex-wrap gap-1">
                          {r.capabilities.length === 0 && <span className="text-xs text-ink-3">{t("roles.noCapabilities")}</span>}
                          {r.capabilities.map((c) => (
                            <span key={c} className="rounded-full bg-surface-2 px-2 py-0.5 text-xs">{t(`cap.${c}`)}</span>
                          ))}
                        </div>
                      )}
                    </td>
                    <td className="px-2 py-2 text-end">
                      {r.locked ? (
                        <span className="text-xs text-ink-3">{t("roles.locked")}</span>
                      ) : (
                        <div className="flex justify-end gap-1">
                          <Button variant="ghost" disabled={busy} onClick={() => openEdit(r)}>{t("roles.edit")}</Button>
                          {!r.builtin && <Button variant="ghost" className="text-bad" disabled={busy} onClick={() => deleteRole(r)}>{t("roles.delete")}</Button>}
                        </div>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>

        <Card title={t("roles.departments")}>
          <p className="mb-4 text-xs text-ink-3">{t("roles.departmentsHint")}</p>
          {data.departments.length === 0 && <p className="text-sm text-ink-3">{t("roles.noDepartments")}</p>}
          <div className="space-y-5">
            {data.departments.map((d) => {
              const perms = shown(d);
              const dirty = drafts[d.id] !== undefined;
              return (
                <section key={d.id} className="rounded-xl border border-line p-3">
                  <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <div className="flex items-center gap-2">
                      <h3 className="font-medium">{d.name}</h3>
                      {d.code && <span className="rounded-full bg-surface-2 px-2 py-0.5 text-xs ltr">{d.code}</span>}
                      {d.status === "INACTIVE" && <span className="text-xs text-ink-3">{t("dept.status.INACTIVE")}</span>}
                    </div>
                    {dirty && (
                      <div className="flex gap-2">
                        <Button onClick={() => setDrafts((all) => {
                          const rest = { ...all };
                          delete rest[d.id];

                          return rest;
                        })} disabled={busy}>{t("common.cancel")}</Button>
                        <Button variant="primary" busy={busy} onClick={() => void saveDepartment(d)}>{t("common.save")}</Button>
                      </div>
                    )}
                  </div>
                  <div className="overflow-x-auto">
                    <table className="w-full min-w-[320px] text-sm">
                      <thead>
                        <tr className="border-b border-line text-xs text-ink-2">
                          <th className="px-2 py-2 text-start font-medium">{t("roles.col.permission")}</th>
                          {data.departmentRoles.map((r) => (
                            <th key={r} className="px-2 py-2 text-center font-medium">{t(`dept.role.${r}`)}</th>
                          ))}
                        </tr>
                      </thead>
                      <tbody>
                        {data.catalog.map((p) => (
                          <tr key={p} className="border-b border-line last:border-0">
                            <td className="px-2 py-2">{t(`dept.perm.${p}`)}</td>
                            {data.departmentRoles.map((r) => (
                              <td key={r} className="px-2 py-2 text-center">
                                <input
                                  type="checkbox"
                                  aria-label={`${d.name} · ${t(`dept.role.${r}`)}: ${t(`dept.perm.${p}`)}`}
                                  className="size-4 accent-[var(--accent)]"
                                  checked={perms[r].includes(p)}
                                  disabled={busy}
                                  onChange={() => toggle(d, r, p)}
                                />
                              </td>
                            ))}
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </section>
              );
            })}
          </div>
        </Card>

        <section>
          <h2 className="mb-2 text-sm font-medium text-ink-2">{t("roles.assistant")}</h2>
          <AiPermissions />
        </section>
      </div>

      <Modal
        open={editing !== null}
        title={editing === "new" ? t("roles.add") : t("roles.edit")}
        onClose={() => setEditing(null)}
        footer={
          <>
            <Button onClick={() => setEditing(null)}>{t("common.cancel")}</Button>
            <Button variant="primary" busy={busy} disabled={!form.name.trim()} onClick={saveRole}>{t("common.save")}</Button>
          </>
        }
      >
        <div className="space-y-3">
          <Field label={t("roles.name")}>
            <input className={inputClass} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
          </Field>
          <Field label={t("roles.description")}>
            <input className={inputClass} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
          </Field>
          <label className="flex items-start gap-2 text-sm">
            <input
              type="checkbox"
              className="mt-1 size-4 accent-[var(--accent)]"
              checked={form.receivesTasks}
              onChange={(e) => setForm({ ...form, receivesTasks: e.target.checked })}
            />
            <span>
              {t("roles.receivesTasks")}
              <span className="block text-xs text-ink-3">{t("roles.receivesTasksHint")}</span>
            </span>
          </label>
          <fieldset>
            <legend className="mb-1 text-sm font-medium">{t("roles.capabilities")}</legend>
            <div className="space-y-2">
              {data.capabilities.map((c) => (
                <label key={c} className="flex items-start gap-2 text-sm">
                  <input
                    type="checkbox"
                    className="mt-1 size-4 accent-[var(--accent)]"
                    checked={form.capabilities.includes(c)}
                    onChange={() => toggleCapability(c)}
                  />
                  <span>
                    {t(`cap.${c}`)}
                    <span className="block text-xs text-ink-3">{t(`cap.${c}.hint`)}</span>
                  </span>
                </label>
              ))}
            </div>
          </fieldset>
        </div>
      </Modal>
    </>
  );
}

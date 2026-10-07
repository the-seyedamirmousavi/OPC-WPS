"use client";

import { useState } from "react";
import { Button, Card, ErrorBox, Field, Loading, Modal, Mono, PageHeader, inputClass } from "@/components/ui";
import { useT, useToast } from "@/components/Providers";
import { api, useFetch } from "@/lib/api";
import type { Role, User } from "@/lib/types";

const ASSIGNABLE: Role[] = ["MANAGER", "USER_1", "USER_2", "USER_3"];

export default function UsersPage() {
  const { t, date } = useT();
  const toast = useToast();
  const { data, error, loading, reload } = useFetch<User[]>("/users");
  const [create, setCreate] = useState(false);
  const [pwFor, setPwFor] = useState<User | null>(null);
  const [busy, setBusy] = useState(false);
  const [form, setForm] = useState({ id: "", fullName: "", role: "USER_1" as Role, password: "" });
  const [newPw, setNewPw] = useState("");

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

  const submitCreate = () =>
    guard(async () => {
      await api("/users", { method: "POST", body: form });
      setCreate(false);
      setForm({ id: "", fullName: "", role: "USER_1", password: "" });
      await reload();
      toast(t("common.saved"));
    });

  const patch = (u: User, body: Partial<Pick<User, "role" | "active">>) =>
    guard(async () => {
      await api(`/users/${encodeURIComponent(u.id)}`, { method: "PATCH", body });
      await reload();
      toast(t("common.saved"));
    });

  const resetPw = () =>
    guard(async () => {
      await api(`/users/${encodeURIComponent(pwFor!.id)}/reset-password`, { method: "POST", body: { newPassword: newPw } });
      setPwFor(null);
      setNewPw("");
      toast(t("users.passwordReset"));
    });

  return (
    <>
      <PageHeader title={t("users.title")} sub={t("users.sub")} actions={<Button variant="primary" onClick={() => setCreate(true)}>{t("users.add")}</Button>} />
      <Card>
        <div className="overflow-x-auto">
          <table className="w-full min-w-[640px] text-sm">
            <thead>
              <tr className="border-b border-line text-xs text-ink-2">
                <th className="px-2 py-2 text-start font-medium">{t("login.userId")}</th>
                <th className="px-2 py-2 text-start font-medium">{t("user.name")}</th>
                <th className="px-2 py-2 text-start font-medium">{t("users.role")}</th>
                <th className="px-2 py-2 text-start font-medium">{t("users.active")}</th>
                <th className="px-2 py-2 text-start font-medium">{t("users.created")}</th>
                <th className="px-2 py-2" />
              </tr>
            </thead>
            <tbody>
              {data.map((u) => {
                const owner = u.role === "OWNER";
                return (
                  <tr key={u.id} className="border-b border-line last:border-0">
                    <td className="px-2 py-2"><Mono>{u.id}</Mono></td>
                    <td className="px-2 py-2">{u.fullName}</td>
                    <td className="px-2 py-2">
                      {owner ? (
                        t("role.OWNER")
                      ) : (
                        <select className={`${inputClass} w-auto`} aria-label={t("users.role")} value={u.role} disabled={busy} onChange={(e) => patch(u, { role: e.target.value as Role })}>
                          {ASSIGNABLE.map((r) => (
                            <option key={r} value={r}>{t(`role.${r}`)}</option>
                          ))}
                        </select>
                      )}
                    </td>
                    <td className="px-2 py-2">
                      <input type="checkbox" aria-label={t("users.active")} className="size-4 accent-[var(--accent)]" checked={u.active} disabled={owner || busy} onChange={(e) => patch(u, { active: e.target.checked })} />
                    </td>
                    <td className="px-2 py-2 text-xs text-ink-3">{date(u.createdAt)}</td>
                    <td className="px-2 py-2 text-end">
                      <Button variant="ghost" onClick={() => setPwFor(u)}>{t("users.resetPassword")}</Button>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </Card>

      <Modal
        open={create}
        title={t("users.add")}
        onClose={() => setCreate(false)}
        footer={
          <>
            <Button onClick={() => setCreate(false)}>{t("common.cancel")}</Button>
            <Button variant="primary" busy={busy} disabled={!form.id || !form.fullName || form.password.length < 8} onClick={submitCreate}>{t("common.save")}</Button>
          </>
        }
      >
        <div className="space-y-3">
          <Field label={t("login.userId")} hint={t("users.idHint")}>
            <input className={`${inputClass} ltr`} value={form.id} onChange={(e) => setForm({ ...form, id: e.target.value })} />
          </Field>
          <Field label={t("user.name")}>
            <input className={inputClass} value={form.fullName} onChange={(e) => setForm({ ...form, fullName: e.target.value })} />
          </Field>
          <Field label={t("users.role")}>
            <select className={inputClass} value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value as Role })}>
              {ASSIGNABLE.map((r) => (
                <option key={r} value={r}>{t(`role.${r}`)}</option>
              ))}
            </select>
          </Field>
          <Field label={t("users.initialPassword")} hint={t("user.passwordRule")}>
            <input type="password" autoComplete="new-password" className={`${inputClass} ltr`} value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} />
          </Field>
        </div>
      </Modal>

      <Modal
        open={pwFor !== null}
        title={`${t("users.resetPassword")}: ${pwFor?.id ?? ""}`}
        onClose={() => setPwFor(null)}
        footer={
          <>
            <Button onClick={() => setPwFor(null)}>{t("common.cancel")}</Button>
            <Button variant="primary" busy={busy} disabled={newPw.length < 8} onClick={resetPw}>{t("common.save")}</Button>
          </>
        }
      >
        <Field label={t("user.newPassword")} hint={t("user.passwordRule")}>
          <input type="password" autoComplete="new-password" className={`${inputClass} ltr`} value={newPw} onChange={(e) => setNewPw(e.target.value)} />
        </Field>
      </Modal>
    </>
  );
}

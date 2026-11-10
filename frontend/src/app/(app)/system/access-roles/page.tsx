"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { App, Switch, Tooltip } from "antd";
import { Lock } from "lucide-react";
import { Fragment, useState } from "react";
import { useI18n } from "@/i18n";
import { apiData } from "@/lib/api";
import { ApiError, problemMessage } from "@/lib/problem";
import { permissionsKey } from "@/lib/session";
import type { AccessRole, PermissionDef } from "@/lib/types";

type Matrix = { roles: AccessRole[]; permissions: PermissionDef[] };
const key = ["access-roles"] as const;

export default function AccessRolesPage() {
  const { t } = useI18n();
  const { message } = App.useApp();
  const qc = useQueryClient();
  const matrix = useQuery<Matrix, ApiError>({ queryKey: key, queryFn: () => apiData<Matrix>("/access-roles") });
  const [saving, setSaving] = useState<string | null>(null);

  if (matrix.isLoading) return <p className="mw-muted">{t("common.loading")}</p>;
  if (matrix.isError || !matrix.data) return <p style={{ color: "var(--bad)" }}>{problemMessage(matrix.error, t)}</p>;

  const { roles, permissions } = matrix.data;

  async function toggle(role: AccessRole, permission: string, on: boolean) {
    const next = on ? [...role.permissions, permission] : role.permissions.filter((p) => p !== permission);
    setSaving(`${role.key}:${permission}`);
    try {
      const res = await apiData<{ key: string; version: number; permissions: string[] }>(`/access-roles/${role.key}/permissions`, {
        method: "PUT",
        body: { permissions: next },
        ifMatch: role.version,
      });
      qc.setQueryData<Matrix>(key, (old) =>
        old ? { ...old, roles: old.roles.map((r) => (r.key === role.key ? { ...r, version: res.version, permissions: res.permissions } : r)) } : old,
      );
      // The menu follows permissions; refresh ours in case we changed our own role.
      qc.invalidateQueries({ queryKey: permissionsKey });
      message.success(t("access.updated"));
    } catch (err) {
      if (err instanceof ApiError && err.status === 412) {
        await qc.invalidateQueries({ queryKey: key });
        message.warning(t("access.stale"));
      } else message.error(problemMessage(err, t));
    } finally {
      setSaving(null);
    }
  }

  const rows = permissions.map((p, i) => ({ ...p, startsGroup: i === 0 || permissions[i - 1].group !== p.group }));
  return (
    <>
      <div className="grid gap-4 sm:grid-cols-3 xl:grid-cols-5 mb-4">
        {roles.map((r) => (
          <div key={r.key} className="mw-role-card">
            <div className="flex items-center justify-between gap-2">
              <span className="font-bold">{r.name}</span>
              {r.locked && (
                <Tooltip title={t("access.locked")}>
                  <Lock size={16} className="mw-muted" aria-label={t("access.locked")} />
                </Tooltip>
              )}
            </div>
            {r.note && <div className="text-xs mw-muted mt-1">{r.note}</div>}
            <div className="text-xs mt-3">{t("access.users", { n: r.users })}</div>
          </div>
        ))}
      </div>

      <section className="mw-section" style={{ padding: 0, overflow: "hidden" }}>
        <div style={{ overflowX: "auto" }}>
          <table className="mw-matrix">
            <thead>
              <tr>
                <th>{t("access.permission")}</th>
                {roles.map((r) => (
                  <th key={r.key} className="text-center">
                    {r.name}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.map((p) => {
                return (
                  <Fragment key={p.key}>
                    {p.startsGroup && (
                      <tr className="mw-matrix__group">
                        <td colSpan={roles.length + 1}>{p.group}</td>
                      </tr>
                    )}
                    <tr>
                      <td>
                        {p.label}
                        <div className="text-xs mw-muted mw-mono">{p.key}</div>
                      </td>
                      {roles.map((r) => (
                        <td key={r.key} className="text-center">
                          <Switch
                            size="small"
                            checked={r.permissions.includes(p.key)}
                            disabled={r.locked || saving !== null}
                            loading={saving === `${r.key}:${p.key}`}
                            onChange={(on) => toggle(r, p.key, on)}
                            aria-label={t("access.toggle", { permission: p.label, role: r.name })}
                          />
                        </td>
                      ))}
                    </tr>
                  </Fragment>
                );
              })}
            </tbody>
          </table>
        </div>
      </section>
      <p className="text-xs mw-muted mt-3">{t("access.note")}</p>
    </>
  );
}

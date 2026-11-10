"use client";

import { Button, Form, Input } from "antd";
import { Lock } from "lucide-react";
import { useState } from "react";
import { useI18n } from "@/i18n";
import { apiData } from "@/lib/api";
import { ApiError, toFormFields } from "@/lib/problem";

/** The 2FA routes need a recently confirmed password (423 PASSWORD_CONFIRMATION_REQUIRED). */
export function needsPasswordConfirm(err: unknown): boolean {
  return err instanceof ApiError && err.code === "PASSWORD_CONFIRMATION_REQUIRED";
}

export default function PasswordConfirm({ onConfirmed }: { onConfirmed: () => void }) {
  const { t } = useI18n();
  const [form] = Form.useForm<{ password: string }>();
  const [busy, setBusy] = useState(false);

  async function confirm({ password }: { password: string }) {
    setBusy(true);
    try {
      await apiData("/auth/confirm-password", { method: "POST", body: { password } });
      form.resetFields();
      onConfirmed();
    } catch (err) {
      if (err instanceof ApiError && err.isValidation) form.setFields(toFormFields(err));
      else form.setFields([{ name: ["password"], errors: [err instanceof Error ? err.message : String(err)] }]);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="mw-alert mw-alert--info mt-4" role="group" aria-label={t("tfa.confirmTitle")}>
      <Lock />
      <div className="flex-1">
        <b>{t("tfa.confirmTitle")}</b>
        <div className="mb-3">{t("tfa.confirmBody")}</div>
        <Form form={form} layout="inline" onFinish={confirm} className="gap-y-2">
          <Form.Item name="password" rules={[{ required: true }]} style={{ flex: "1 1 200px" }}>
            <Input.Password autoComplete="current-password" placeholder={t("login.password")} aria-label={t("login.password")} />
          </Form.Item>
          <Button type="primary" htmlType="submit" loading={busy}>
            {t("common.confirm")}
          </Button>
        </Form>
      </div>
    </div>
  );
}

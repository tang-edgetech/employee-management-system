"use client";

import { useQueryClient } from "@tanstack/react-query";
import { App, Button, Form, Input } from "antd";
import { ArrowLeft, Fingerprint, ShieldCheck } from "lucide-react";
import { useRouter } from "next/navigation";
import { useState } from "react";
import AuthFrame from "@/components/AuthFrame";
import { useI18n } from "@/i18n";
import { apiData, resetCsrf } from "@/lib/api";
import { ApiError, problemMessage, toFormFields } from "@/lib/problem";

type Values = { code?: string; recovery_code?: string };

export default function TwoFactorPage() {
  const { t } = useI18n();
  const { message } = App.useApp();
  const router = useRouter();
  const qc = useQueryClient();
  const [form] = Form.useForm<Values>();
  const [recovery, setRecovery] = useState(false);
  const [busy, setBusy] = useState(false);

  async function verify(values: Values) {
    setBusy(true);
    try {
      const body = recovery ? { recovery_code: values.recovery_code?.trim() } : { code: values.code };
      await apiData("/auth/two-factor-challenge", { method: "POST", body });
      resetCsrf();
      qc.clear();
      router.replace("/dashboard/");
    } catch (err) {
      if (err instanceof ApiError && err.isValidation) {
        form.setFields(toFormFields(err));
      } else {
        message.error(problemMessage(err, t));
      }
    } finally {
      setBusy(false);
    }
  }

  return (
    <AuthFrame>
      <span className="mw-device__icon mb-4" style={{ width: 48, height: 48 }}>
        <Fingerprint size={22} />
      </span>
      <h2 className="mw-login__heading">{t("twofa.title")}</h2>
      <p className="mw-muted mt-1 mb-6">{t(recovery ? "twofa.recoveryBody" : "twofa.body")}</p>
      <Form<Values> form={form} layout="vertical" requiredMark={false} onFinish={verify}>
        {recovery ? (
          <Form.Item name="recovery_code" label={t("twofa.recovery")} rules={[{ required: true }]}>
            <Input autoComplete="one-time-code" className="mw-mono" autoFocus />
          </Form.Item>
        ) : (
          <Form.Item name="code" label={t("twofa.code")} rules={[{ required: true, len: 6 }]}>
            <Input.OTP length={6} autoFocus formatter={(v) => v.replace(/\D/g, "")} />
          </Form.Item>
        )}
        <Button type="primary" htmlType="submit" block size="large" loading={busy} icon={<ShieldCheck size={16} />}>
          {t("twofa.submit")}
        </Button>
      </Form>
      <Button type="link" block className="mt-2" onClick={() => setRecovery((r) => !r)}>
        {t(recovery ? "twofa.useCode" : "twofa.useRecovery")}
      </Button>
      <Button block className="mt-1" icon={<ArrowLeft size={16} />} onClick={() => router.replace("/login/")}>
        {t("twofa.back")}
      </Button>
    </AuthFrame>
  );
}

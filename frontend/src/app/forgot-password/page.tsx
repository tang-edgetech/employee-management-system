"use client";

import { App, Button, Form, Input } from "antd";
import { ArrowLeft, Mail } from "lucide-react";
import { useRouter } from "next/navigation";
import { useState } from "react";
import AuthFrame from "@/components/AuthFrame";
import { useI18n } from "@/i18n";
import { apiData } from "@/lib/api";
import { ApiError, problemMessage, toFormFields } from "@/lib/problem";

export default function ForgotPasswordPage() {
  const { t } = useI18n();
  const { message } = App.useApp();
  const router = useRouter();
  const [form] = Form.useForm<{ email: string }>();
  const [busy, setBusy] = useState(false);
  const [sent, setSent] = useState(false);

  async function send({ email }: { email: string }) {
    setBusy(true);
    try {
      await apiData("/auth/forgot-password", { method: "POST", body: { email: email.trim() } });
      setSent(true);
    } catch (err) {
      if (err instanceof ApiError && err.isValidation) form.setFields(toFormFields(err));
      else message.error(problemMessage(err, t));
    } finally {
      setBusy(false);
    }
  }

  return (
    <AuthFrame>
      <h2 className="mw-login__heading">{t("forgot.title")}</h2>
      <p className="mw-muted mt-1 mb-6">{t("forgot.body")}</p>
      {sent ? (
        <div className="mw-alert mw-alert--info" role="status">
          <Mail />
          <div>{t("forgot.sent")}</div>
        </div>
      ) : (
        <Form form={form} layout="vertical" requiredMark={false} onFinish={send}>
          <Form.Item name="email" label={t("login.email")} rules={[{ required: true, type: "email" }]}>
            <Input autoComplete="username" autoFocus />
          </Form.Item>
          <Button type="primary" htmlType="submit" block size="large" loading={busy} icon={<Mail size={16} />}>
            {t("forgot.submit")}
          </Button>
        </Form>
      )}
      <Button block className="mt-2" icon={<ArrowLeft size={16} />} onClick={() => router.push("/login/")}>
        {t("twofa.back")}
      </Button>
    </AuthFrame>
  );
}

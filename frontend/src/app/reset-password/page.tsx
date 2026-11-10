"use client";

import { App, Button, Form, Input } from "antd";
import { ArrowLeft, KeyRound } from "lucide-react";
import { useRouter, useSearchParams } from "next/navigation";
import { Suspense, useState } from "react";
import AuthFrame from "@/components/AuthFrame";
import PasswordRules from "@/components/PasswordRules";
import { useI18n } from "@/i18n";
import { apiData } from "@/lib/api";
import { passwordRules } from "@/lib/password";
import { ApiError, problemMessage, toFormFields } from "@/lib/problem";

type Values = { password: string; password_confirmation: string };

function ResetForm() {
  const { t } = useI18n();
  const { message } = App.useApp();
  const router = useRouter();
  const params = useSearchParams();
  const token = params.get("token") ?? "";
  const email = params.get("email") ?? "";
  const [form] = Form.useForm<Values>();
  const pw = Form.useWatch("password", form) ?? "";
  const repeat = Form.useWatch("password_confirmation", form) ?? "";
  const rules = passwordRules(pw, repeat);
  const [busy, setBusy] = useState(false);

  async function reset(values: Values) {
    setBusy(true);
    try {
      await apiData("/auth/reset-password", { method: "POST", body: { token, email, ...values } });
      message.success(t("reset.done"));
      router.replace("/login/");
    } catch (err) {
      if (err instanceof ApiError && err.isValidation) {
        form.setFields(toFormFields(err).filter((f) => f.name[0] !== "email" && f.name[0] !== "token"));
        const linkError = err.fieldErrors.email?.[0] ?? err.fieldErrors.token?.[0];
        if (linkError) message.error(linkError);
      } else message.error(problemMessage(err, t));
    } finally {
      setBusy(false);
    }
  }

  if (!token || !email) {
    return (
      <div className="mw-alert mw-alert--warning" role="alert">
        <KeyRound />
        <div>{t("reset.invalid")}</div>
      </div>
    );
  }

  return (
    <Form form={form} layout="vertical" requiredMark={false} onFinish={reset}>
      <div className="mw-field__readonly mb-4">{email}</div>
      <Form.Item name="password" label={t("pw.new")} rules={[{ required: true }]}>
        <Input.Password autoComplete="new-password" autoFocus />
      </Form.Item>
      <Form.Item name="password_confirmation" label={t("pw.repeat")} rules={[{ required: true }]}>
        <Input.Password autoComplete="new-password" />
      </Form.Item>
      <PasswordRules rules={rules} />
      <Button type="primary" htmlType="submit" block size="large" className="mt-4" loading={busy} disabled={!rules.every((r) => r.met)} icon={<KeyRound size={16} />}>
        {t("reset.submit")}
      </Button>
    </Form>
  );
}

export default function ResetPasswordPage() {
  const { t } = useI18n();
  const router = useRouter();
  return (
    <AuthFrame>
      <h2 className="mw-login__heading mb-6">{t("reset.title")}</h2>
      {/* useSearchParams needs a Suspense boundary in a static export. */}
      <Suspense fallback={null}>
        <ResetForm />
      </Suspense>
      <Button block className="mt-2" icon={<ArrowLeft size={16} />} onClick={() => router.push("/login/")}>
        {t("twofa.back")}
      </Button>
    </AuthFrame>
  );
}

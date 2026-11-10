"use client";

import { useQueryClient } from "@tanstack/react-query";
import { App, Button, Checkbox, Form, Input } from "antd";
import { LogIn } from "lucide-react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import AuthFrame from "@/components/AuthFrame";
import Avatar from "@/components/Avatar";
import { useI18n } from "@/i18n";
import { apiData, resetCsrf } from "@/lib/api";
import { ApiError, problemMessage, toFormFields } from "@/lib/problem";

type Values = { email: string; password: string; remember: boolean };

// Demo logins are listed in development builds only (D6). Real staff names: replace
// with fictional ones before any client or prospect sees the demo (D14).
const DEMO_ACCOUNTS =
  process.env.NODE_ENV === "development"
    ? [
        { email: "tang@hajimi.dev", name: "Tang", role: "System Admin" },
        { email: "ahfa@hajimi.my", name: "Ah Fa", role: "Executive" },
        { email: "ellie@hajimi.my", name: "Ellie", role: "HR" },
        { email: "teechienyap@hajimi.my", name: "Tee Chien Yap", role: "Team Lead" },
        { email: "jeffyeoh@hajimi.my", name: "Jeff Yeoh", role: "Staff" },
        { email: "sinyee@hajimi.my", name: "Sin Yee", role: "Staff" },
      ]
    : [];
const DEMO_PASSWORD = "Demo#Meridian2026";

export default function LoginPage() {
  const { t } = useI18n();
  const { message } = App.useApp();
  const router = useRouter();
  const qc = useQueryClient();
  const [form] = Form.useForm<Values>();
  const [busy, setBusy] = useState(false);

  async function signIn(values: Values) {
    setBusy(true);
    try {
      const res = await apiData<{ two_factor: boolean }>("/auth/login", {
        method: "POST",
        body: { email: values.email.trim(), password: values.password, remember: values.remember },
      });
      resetCsrf();
      if (res.two_factor) {
        router.push("/two-factor/");
        return;
      }
      qc.clear();
      router.replace("/dashboard/");
    } catch (err) {
      if (err instanceof ApiError && err.code === "LOGIN_LOCKED") {
        message.error(t("login.locked", { seconds: err.retryAfter ?? 60 }));
      } else if (err instanceof ApiError && err.isValidation) {
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
      <h2 className="mw-login__heading">{t("login.title")}</h2>
      <p className="mw-muted mt-1 mb-6">{t("login.subtitle")}</p>
      <Form<Values> form={form} layout="vertical" requiredMark={false} initialValues={{ remember: true }} onFinish={signIn} className="mw-login__fields">
        <Form.Item name="email" label={t("login.email")} rules={[{ required: true, type: "email" }]}>
          <Input autoComplete="username" placeholder="you@company.com" autoFocus />
        </Form.Item>
        <Form.Item name="password" label={t("login.password")} rules={[{ required: true }]}>
          <Input.Password autoComplete="current-password" placeholder="••••••••" />
        </Form.Item>
        <div className="flex items-center justify-between text-sm mb-4">
          <Form.Item name="remember" valuePropName="checked" noStyle>
            <Checkbox>{t("login.remember")}</Checkbox>
          </Form.Item>
          <Link href="/forgot-password/" className="font-semibold" style={{ color: "var(--accent)" }}>
            {t("login.forgot")}
          </Link>
        </div>
        <Button type="primary" htmlType="submit" block size="large" loading={busy} icon={<LogIn size={16} />}>
          {t("login.submit")}
        </Button>
      </Form>

      {DEMO_ACCOUNTS.length > 0 && (
        <div className="mt-8">
          <div className="text-xs font-bold uppercase tracking-wider mw-muted">{t("login.demo")}</div>
          <div className="text-xs mw-muted mb-2">{t("login.demoHint")}</div>
          <div className="mw-login__accounts">
            {DEMO_ACCOUNTS.map((a) => (
              <button
                key={a.email}
                type="button"
                className="mw-login__account"
                disabled={busy}
                onClick={() => signIn({ email: a.email, password: DEMO_PASSWORD, remember: false })}
              >
                <Avatar name={a.name} size={28} />
                <span className="flex-1 min-w-0">
                  <span className="block text-sm font-semibold">{a.name}</span>
                  <span className="block text-xs mw-muted truncate">{a.email}</span>
                </span>
                <span className="mw-chip mw-chip--accent">{a.role}</span>
              </button>
            ))}
          </div>
        </div>
      )}
    </AuthFrame>
  );
}

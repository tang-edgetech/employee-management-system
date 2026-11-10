"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { App, Button, Form, Input, Modal, Radio } from "antd";
import { Badge, Briefcase, Check, Fingerprint, KeyRound, Languages, LifeBuoy, Plus, RefreshCw, ShieldAlert, ShieldCheck, Smartphone, Trash2, Upload, UserRound } from "lucide-react";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { Suspense, useRef, useState } from "react";
import Avatar from "@/components/Avatar";
import PasswordConfirm, { needsPasswordConfirm } from "@/components/PasswordConfirm";
import PasswordRules from "@/components/PasswordRules";
import { useI18n, type Locale, type MessageKey } from "@/i18n";
import { apiData } from "@/lib/api";
import { passwordRules } from "@/lib/password";
import { ApiError, problemMessage, toFormFields } from "@/lib/problem";
import { meKey, useChangeLocale, useMe } from "@/lib/session";
import type { Me, NewDevice, TwoFactorStatus } from "@/lib/types";
import { ScanPending, UploadRejected, uploadFile } from "@/lib/upload";

type Tab = "overview" | "security" | "prefs";
const TABS: { key: Tab; label: MessageKey; icon: typeof UserRound }[] = [
  { key: "overview", label: "profile.tab.overview", icon: UserRound },
  { key: "security", label: "profile.tab.security", icon: ShieldCheck },
  { key: "prefs", label: "profile.tab.prefs", icon: Languages },
];

export default function ProfilePage() {
  return (
    <Suspense fallback={null}>
      <Profile />
    </Suspense>
  );
}

function Profile() {
  const { t } = useI18n();
  const { data: me } = useMe();
  const params = useSearchParams();
  const router = useRouter();
  const pathname = usePathname();
  const requested = params.get("tab") as Tab | null;
  const setupRequired = !!me?.two_factor.setup_required;
  const tab: Tab = setupRequired ? "security" : TABS.some((x) => x.key === requested) ? requested! : "overview";

  if (!me) return null;

  return (
    <>
      {setupRequired && (
        <div className="mw-alert mw-alert--warning" role="alert">
          <ShieldAlert />
          <div>
            <b>{t("profile.setupRequired")}</b>
            <div>{t("profile.setupRequiredBody")}</div>
          </div>
        </div>
      )}
      <div className="mw-tabs" role="tablist">
        {TABS.map(({ key, label, icon: Icon }) => (
          <button
            key={key}
            type="button"
            role="tab"
            aria-selected={tab === key}
            disabled={setupRequired && key !== "security"}
            className={`mw-tabs__item${tab === key ? " is-active" : ""}`}
            onClick={() => router.replace(`${pathname}?tab=${key}`)}
          >
            <Icon />
            {t(label)}
            {key === "security" && setupRequired && <span className="mw-dot" aria-hidden="true" />}
          </button>
        ))}
      </div>
      {tab === "overview" && <Overview me={me} />}
      {tab === "security" && <Security me={me} />}
      {tab === "prefs" && <Preferences />}
    </>
  );
}

/* ---------------- Overview ---------------- */

const AVATAR_TYPES = ["image/jpeg", "image/png", "image/webp"];
const AVATAR_MAX = 3 * 1024 * 1024;

function Overview({ me }: { me: Me }) {
  const { t } = useI18n();
  const { message } = App.useApp();
  const qc = useQueryClient();
  const [form] = Form.useForm<{ display_name: string }>();
  const fileInput = useRef<HTMLInputElement>(null);
  const [picStatus, setPicStatus] = useState<null | "uploading" | "checking">(null);

  const save = useMutation({
    mutationFn: (body: { display_name?: string; avatar_file_id?: string | null }) => apiData<Me>("/me", { method: "PATCH", body }),
    onSuccess: (data) => qc.setQueryData(meKey, data),
  });

  async function saveName({ display_name }: { display_name: string }) {
    try {
      await save.mutateAsync({ display_name: display_name.trim() });
      message.success(t("common.saved"));
    } catch (err) {
      if (err instanceof ApiError && err.isValidation) form.setFields(toFormFields(err));
      else message.error(problemMessage(err, t));
    }
  }

  async function pickPicture(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    e.target.value = "";
    if (!file) return;
    if (!AVATAR_TYPES.includes(file.type)) return void message.error(t("profile.pictureType"));
    if (file.size > AVATAR_MAX) return void message.error(t("profile.pictureTooBig"));
    setPicStatus("uploading");
    try {
      const stored = await uploadFile(file, "avatar", () => setPicStatus("checking"));
      await save.mutateAsync({ avatar_file_id: stored.id });
      message.success(t("profile.pictureSaved"));
    } catch (err) {
      message.error(err instanceof UploadRejected ? t("profile.pictureRejected") : err instanceof ScanPending ? t("profile.stillChecking") : problemMessage(err, t));
    } finally {
      setPicStatus(null);
    }
  }

  async function removePicture() {
    try {
      await save.mutateAsync({ avatar_file_id: null });
      message.success(t("profile.pictureRemoved"));
    } catch (err) {
      message.error(problemMessage(err, t));
    }
  }

  return (
    <div className="grid gap-4 xl:grid-cols-2">
      <section className="mw-section">
        <h2 className="mw-section__title">
          <Badge />
          {t("profile.section")}
        </h2>
        <div className="mw-avatar-editor">
          <Avatar name={me.display_name} fileId={me.avatar_file_id} size={80} />
          <div className="flex flex-col gap-2">
            <div className="text-sm font-semibold">{t("profile.picture")}</div>
            <div className="text-xs mw-muted" style={{ maxWidth: 300 }}>
              {t("profile.pictureHint")}
            </div>
            <div className="flex gap-2">
              <Button icon={<Upload size={16} />} loading={picStatus !== null} onClick={() => fileInput.current?.click()}>
                {picStatus === "uploading" ? t("profile.uploading") : picStatus === "checking" ? t("profile.checking") : t("profile.upload")}
              </Button>
              {me.avatar_file_id && (
                <Button onClick={removePicture} disabled={picStatus !== null}>
                  {t("common.remove")}
                </Button>
              )}
              <input ref={fileInput} type="file" accept={AVATAR_TYPES.join(",")} className="sr-only" onChange={pickPicture} aria-label={t("profile.upload")} />
            </div>
          </div>
        </div>
        <Form form={form} layout="vertical" requiredMark={false} initialValues={{ display_name: me.display_name }} onFinish={saveName} className="mt-4">
          <Form.Item name="display_name" label={t("profile.displayName")} extra={t("profile.displayNameHint")} rules={[{ required: true, whitespace: true, max: 60 }]}>
            <Input maxLength={60} />
          </Form.Item>
          <Button type="primary" htmlType="submit" loading={save.isPending && picStatus === null} icon={<Check size={16} />}>
            {t("common.save")}
          </Button>
        </Form>
      </section>

      <section className="mw-section">
        <h2 className="mw-section__title">
          <Briefcase />
          {t("profile.employment")}
        </h2>
        <div className="mw-section__hint">{t("profile.managedByHr")}</div>
        <dl className="mw-dl">
          {me.employee ? (
            <>
              <dt>{t("profile.legalName")}</dt>
              <dd>{me.employee.full_name}</dd>
              <dt>{t("profile.staffNo")}</dt>
              <dd className="mw-mono">{me.employee.employee_no ?? t("common.none")}</dd>
              <dt>{t("profile.department")}</dt>
              <dd>{me.employee.department ?? t("common.none")}</dd>
            </>
          ) : (
            <>
              <dt>{t("profile.legalName")}</dt>
              <dd>{me.name}</dd>
            </>
          )}
          <dt>{t("profile.email")}</dt>
          <dd>{me.email}</dd>
          <dt>{t("profile.role")}</dt>
          <dd>{me.role.name}</dd>
        </dl>
        <p className="text-xs mw-muted mt-4 mb-0">{me.employee ? t("profile.moreSoon") : t("profile.noEmployee")}</p>
      </section>
    </div>
  );
}

/* ---------------- Security ---------------- */

function Security({ me }: { me: Me }) {
  return (
    <div className="grid gap-4 xl:grid-cols-2">
      <ChangePassword disabled={me.two_factor.setup_required} />
      <TwoFactor me={me} />
    </div>
  );
}

type PwValues = { current_password: string; password: string; password_confirmation: string };

function ChangePassword({ disabled }: { disabled: boolean }) {
  const { t } = useI18n();
  const { message } = App.useApp();
  const [form] = Form.useForm<PwValues>();
  const current = Form.useWatch("current_password", form) ?? "";
  const next = Form.useWatch("password", form) ?? "";
  const repeat = Form.useWatch("password_confirmation", form) ?? "";
  const rules = passwordRules(next, repeat, current);
  const [busy, setBusy] = useState(false);

  async function change(values: PwValues) {
    setBusy(true);
    try {
      await apiData("/auth/password", { method: "PUT", body: values });
      form.resetFields();
      message.success(t("pw.done"));
    } catch (err) {
      if (err instanceof ApiError && err.isValidation) form.setFields(toFormFields(err));
      else message.error(problemMessage(err, t));
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="mw-section">
      <h2 className="mw-section__title">
        <KeyRound />
        {t("pw.title")}
      </h2>
      <Form form={form} layout="vertical" requiredMark={false} onFinish={change} className="mt-4" disabled={disabled}>
        <Form.Item name="current_password" label={t("pw.current")} rules={[{ required: true }]}>
          <Input.Password autoComplete="current-password" />
        </Form.Item>
        <Form.Item name="password" label={t("pw.new")} rules={[{ required: true }]}>
          <Input.Password autoComplete="new-password" />
        </Form.Item>
        <Form.Item name="password_confirmation" label={t("pw.repeat")} rules={[{ required: true }]}>
          <Input.Password autoComplete="new-password" />
        </Form.Item>
        <PasswordRules rules={rules} />
        <Button type="primary" htmlType="submit" className="mt-4" loading={busy} disabled={disabled || !current || !rules.every((r) => r.met)} icon={<Check size={16} />}>
          {t("pw.submit")}
        </Button>
      </Form>
    </section>
  );
}

const devicesKey = ["2fa", "devices"] as const;

function TwoFactor({ me }: { me: Me }) {
  const { t, locale } = useI18n();
  const { message, modal } = App.useApp();
  const qc = useQueryClient();
  const status = useQuery<TwoFactorStatus, ApiError>({ queryKey: devicesKey, queryFn: () => apiData<TwoFactorStatus>("/auth/2fa/devices") });
  const [adding, setAdding] = useState(false);
  const [codes, setCodes] = useState<string[] | null>(null);

  const date = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString(locale, { day: "numeric", month: "short", year: "numeric" }) : "");
  const refreshAll = () => {
    qc.invalidateQueries({ queryKey: devicesKey });
    qc.invalidateQueries({ queryKey: meKey });
  };
  const onError = (err: unknown) => {
    if (needsPasswordConfirm(err)) qc.invalidateQueries({ queryKey: devicesKey });
    else message.error(problemMessage(err, t));
  };

  const enforced = status.data?.enforced ?? me.two_factor.enforced;
  const devices = status.data?.devices ?? [];
  const max = status.data?.max_devices ?? 2;
  const locked = needsPasswordConfirm(status.error);

  function remove(id: string, name: string) {
    if (enforced && devices.length === 1) return void message.warning(t("tfa.lastRequired"));
    modal.confirm({
      title: t("tfa.removeTitle", { name }),
      content: t("tfa.removeBody"),
      okText: t("common.remove"),
      okButtonProps: { danger: true },
      cancelText: t("common.cancel"),
      onOk: async () => {
        try {
          await apiData(`/auth/2fa/devices/${id}`, { method: "DELETE" });
          message.success(t("tfa.removed"));
          refreshAll();
        } catch (err) {
          onError(err);
        }
      },
    });
  }

  async function showCodes(regenerate: boolean) {
    const load = async () => {
      try {
        const res = await apiData<{ recovery_codes: string[] }>("/auth/2fa/recovery-codes", { method: regenerate ? "POST" : "GET" });
        setCodes(res.recovery_codes);
      } catch (err) {
        onError(err);
      }
    };
    if (!regenerate) return load();
    modal.confirm({ title: t("tfa.regenerateTitle"), content: t("tfa.regenerateBody"), okText: t("tfa.regenerate"), cancelText: t("common.cancel"), onOk: load });
  }

  return (
    <section className="mw-section">
      <div className="flex items-start justify-between gap-3">
        <div>
          <h2 className="mw-section__title">
            <Fingerprint />
            {t("tfa.title")}
          </h2>
          <div className="mw-section__hint">
            {t(enforced ? "tfa.required" : "tfa.optional")} · {t("tfa.max", { n: max })}
          </div>
        </div>
        <Button type="primary" icon={<Plus size={16} />} disabled={locked || !status.data || devices.length >= max} onClick={() => setAdding(true)}>
          {t("tfa.add")}
        </Button>
      </div>

      {locked ? (
        <PasswordConfirm onConfirmed={() => qc.invalidateQueries({ queryKey: devicesKey })} />
      ) : status.isError ? (
        <p className="text-sm mt-3" style={{ color: "var(--bad)" }}>
          {problemMessage(status.error, t)}
        </p>
      ) : (
        <>
          <div className="text-xs font-bold uppercase tracking-wider mw-muted mt-4">
            {t("tfa.apps")} · {devices.length}/{max}
          </div>
          {status.isLoading ? (
            <p className="text-sm mw-muted mt-2">{t("common.loading")}</p>
          ) : devices.length === 0 ? (
            <p className="text-sm mw-muted mt-2">{t("tfa.none")}</p>
          ) : (
            devices.map((d) => (
              <div key={d.id} className="mw-device">
                <span className="mw-device__icon">
                  <Smartphone size={20} />
                </span>
                <div className="flex-1 min-w-0">
                  <div className="font-semibold text-sm">{d.name}</div>
                  <div className="text-xs mw-muted">
                    {t("tfa.addedOn", { date: date(d.added_at) })} · {d.last_used_at ? t("tfa.lastUsed", { date: date(d.last_used_at) }) : t("tfa.neverUsed")}
                  </div>
                </div>
                <button type="button" className="mw-icon-btn mw-icon-btn--danger" onClick={() => remove(d.id, d.name)} aria-label={`${t("common.remove")} ${d.name}`}>
                  <Trash2 />
                </button>
              </div>
            ))
          )}
          {devices.length > 0 && (
            <div className="flex flex-wrap gap-2 mt-4">
              <Button icon={<LifeBuoy size={16} />} onClick={() => showCodes(false)}>
                {t("tfa.viewCodes")}
              </Button>
              <Button icon={<RefreshCw size={16} />} onClick={() => showCodes(true)}>
                {t("tfa.regenerate")}
              </Button>
            </div>
          )}
        </>
      )}

      {adding && (
        <AddDevice
          onClose={() => setAdding(false)}
          onAdded={(recovery) => {
            setAdding(false);
            message.success(t("tfa.added"));
            refreshAll();
            if (recovery?.length) setCodes(recovery);
          }}
          onError={onError}
        />
      )}
      <Modal open={!!codes} title={t("tfa.codesTitle")} onCancel={() => setCodes(null)} footer={<Button type="primary" onClick={() => setCodes(null)}>{t("common.ok")}</Button>}>
        <p className="text-sm mw-muted">{t("tfa.codesBody")}</p>
        <div className="mw-code-list">
          {codes?.map((c) => (
            <span key={c}>{c}</span>
          ))}
        </div>
      </Modal>
    </section>
  );
}

function AddDevice({ onClose, onAdded, onError }: { onClose: () => void; onAdded: (codes: string[] | null) => void; onError: (e: unknown) => void }) {
  const { t } = useI18n();
  const [form] = Form.useForm<{ name: string; code: string }>();
  const [device, setDevice] = useState<NewDevice | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(values: { name: string; code?: string }) {
    setBusy(true);
    try {
      if (!device) {
        setDevice(await apiData<NewDevice>("/auth/2fa/devices", { method: "POST", body: { name: values.name.trim() } }));
      } else {
        const res = await apiData<{ id: string; recovery_codes: string[] | null }>(`/auth/2fa/devices/${device.id}/confirm`, { method: "POST", body: { code: values.code } });
        onAdded(res.recovery_codes);
      }
    } catch (err) {
      if (err instanceof ApiError && err.isValidation) form.setFields(toFormFields(err));
      else {
        onError(err);
        if (needsPasswordConfirm(err)) onClose();
      }
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal
      open
      title={t("tfa.addTitle")}
      onCancel={onClose}
      okText={device ? t("tfa.verify") : t("tfa.next")}
      cancelText={t("common.cancel")}
      confirmLoading={busy}
      onOk={() => form.submit()}
      destroyOnHidden
    >
      <p className="text-sm mw-muted">{t("tfa.addIntro")}</p>
      <Form form={form} layout="vertical" requiredMark={false} onFinish={submit}>
        <Form.Item name="name" label={t("tfa.deviceName")} rules={[{ required: true, whitespace: true, max: 60 }]}>
          <Input placeholder={t("tfa.deviceNamePlaceholder")} disabled={!!device} autoFocus />
        </Form.Item>
        {device && (
          <div className="flex flex-wrap gap-5 items-start">
            <div className="mw-qr">
              {/* The server renders the QR code; shown as an image so its markup can't run. */}
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img src={`data:image/svg+xml;charset=utf-8,${encodeURIComponent(device.qr_svg)}`} alt={t("tfa.addTitle")} />
            </div>
            <div className="flex flex-col gap-3 flex-1 min-w-[200px]">
              <div className="mw-secret">{device.secret.replace(/(.{4})/g, "$1 ").trim()}</div>
              <Form.Item name="code" label={t("tfa.code")} rules={[{ required: true, len: 6 }]}>
                <Input.OTP length={6} formatter={(v) => v.replace(/\D/g, "")} />
              </Form.Item>
            </div>
          </div>
        )}
      </Form>
    </Modal>
  );
}

/* ---------------- Preferences ---------------- */

function Preferences() {
  const { t, locale } = useI18n();
  const { message } = App.useApp();
  const change = useChangeLocale();
  return (
    <section className="mw-section" style={{ maxWidth: 560 }}>
      <h2 className="mw-section__title">
        <Languages />
        {t("prefs.language")}
      </h2>
      <Radio.Group
        className="mt-4"
        value={locale}
        onChange={(e) => {
          change(e.target.value as Locale);
          message.success(t("prefs.saved"));
        }}
        options={[
          { value: "en", label: "English" },
          { value: "zh-CN", label: "中文" },
        ]}
      />
    </section>
  );
}

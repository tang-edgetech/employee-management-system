"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { App, Button, Checkbox, Form, Input, InputNumber, Select, Switch } from "antd";
import { Building2, Fingerprint, Gauge, Hash, Hourglass, ListChecks, Save, UserPlus, type LucideIcon } from "lucide-react";
import { cloneElement, useMemo, useState } from "react";
import { useI18n, type MessageKey } from "@/i18n";
import { api, apiData } from "@/lib/api";
import { ApiError, problemMessage, toFormFields } from "@/lib/problem";
import { meKey } from "@/lib/session";
import { KPI_COMPONENTS, type AccessRole, type PermissionDef, type Settings, type SettingsMeta } from "@/lib/types";

type Loaded = { data: Settings; meta: SettingsMeta };
type Values = Record<string, unknown>;

const settingsKey = ["settings"] as const;

// Which permission may change a field (mirrors SettingsRules::WRITE_PERMISSIONS).
const permissionFor = (field: string) => (field.startsWith("kpi_") ? "kpi.configure" : "system.settings");

/** The API nests settings by section; the form works with flat field names. */
function flatten(data: Settings): Values {
  return Object.values(data).reduce<Values>((all, section) => ({ ...all, ...section }), {});
}

function same(a: unknown, b: unknown) {
  return JSON.stringify(a) === JSON.stringify(b);
}

export default function SettingsPage() {
  const { t, locale } = useI18n();
  const { message, modal } = App.useApp();
  const qc = useQueryClient();
  const [form] = Form.useForm<Values>();
  const [busy, setBusy] = useState(false);

  const settings = useQuery<Loaded, ApiError>({
    queryKey: settingsKey,
    queryFn: async () => {
      const res = await api<Settings>("/settings");
      return { data: res.data, meta: (res.raw as { meta: SettingsMeta }).meta };
    },
  });
  const roles = useQuery({
    queryKey: ["access-roles"],
    queryFn: () => apiData<{ roles: AccessRole[]; permissions: PermissionDef[] }>("/access-roles"),
    enabled: !!settings.data?.data.security,
  });

  const initial = useMemo(() => (settings.data ? flatten(settings.data.data) : {}), [settings.data]);
  const values = Form.useWatch([], form) as Values | undefined;
  const changed = useMemo(() => Object.keys(values ?? {}).filter((k) => k in initial && !same(values![k], initial[k])), [values, initial]);

  if (settings.isLoading) return <p className="mw-muted">{t("common.loading")}</p>;
  if (settings.isError || !settings.data) return <p style={{ color: "var(--bad)" }}>{problemMessage(settings.error, t)}</p>;

  const { data, meta } = settings.data;
  const canEdit = (field: string) => meta.can_edit.includes(permissionFor(field));
  const anyEditable = meta.can_edit.length > 0;

  async function reload() {
    await qc.invalidateQueries({ queryKey: settingsKey });
    const fresh = qc.getQueryData<Loaded>(settingsKey);
    if (fresh) form.setFieldsValue(flatten(fresh.data));
  }

  async function save() {
    if (!changed.length) return;
    let body: Values;
    try {
      const all = await form.validateFields();
      body = Object.fromEntries(changed.map((k) => [k, all[k]]));
    } catch {
      return;
    }
    setBusy(true);
    try {
      const res = await api<Settings>("/settings", { method: "PATCH", body, ifMatch: meta.version });
      const next = { data: res.data, meta: { ...meta, version: Number(res.etag ?? meta.version + 1) } };
      qc.setQueryData(settingsKey, next);
      form.setFieldsValue(flatten(res.data));
      // The session timeout and 2FA rules are part of /me.
      qc.invalidateQueries({ queryKey: meKey });
      message.success(t("settings.saved"));
    } catch (err) {
      if (err instanceof ApiError && err.status === 412) {
        await reload();
        message.warning(t("settings.stale"));
      } else if (err instanceof ApiError && err.isValidation) {
        form.setFields(toFormFields(err));
        message.error(problemMessage(err, t));
      } else message.error(problemMessage(err, t));
    } finally {
      setBusy(false);
    }
  }

  function renumber() {
    const numberFields = ["employee_no_format", "employee_no_digits", "employee_no_next"];
    if (changed.some((k) => numberFields.includes(k))) return void message.warning(t("settings.renumberSaveFirst"));
    modal.confirm({
      title: t("settings.renumberTitle"),
      content: t("settings.renumberBody"),
      okText: t("settings.renumber"),
      cancelText: t("common.cancel"),
      onOk: async () => {
        try {
          const res = await apiData<{ changed: number }>("/employee-numbers/renumber", {
            method: "POST",
            body: { format: initial.employee_no_format, digits: initial.employee_no_digits },
            ifMatch: meta.version,
          });
          await reload();
          message.success(t("settings.renumberDone", { n: res.changed }));
        } catch (err) {
          if (err instanceof ApiError && err.status === 412) {
            await reload();
            message.warning(t("settings.stale"));
          } else message.error(problemMessage(err, t));
        }
      },
    });
  }

  const opt = (key: MessageKey, n?: number) => t(key, n === undefined ? undefined : { n });
  const months = Array.from({ length: 12 }, (_, i) => ({ value: i + 1, label: new Date(2026, i, 1).toLocaleString(locale, { month: "long" }) }));
  const weights = (values?.kpi_weights as Record<string, number> | undefined) ?? (initial.kpi_weights as Record<string, number> | undefined);
  const weightSum = weights ? KPI_COMPONENTS.reduce((s, k) => s + (Number(weights[k]) || 0), 0) : 0;

  return (
    <Form form={form} initialValues={initial} onFinish={save} requiredMark={false} className="mw-settings">
      {!anyEditable && (
        <div className="mw-alert mw-alert--info">
          <Fingerprint />
          <div>{t("settings.readOnly")}</div>
        </div>
      )}
      <div className="grid gap-4 xl:grid-cols-2">
        {data.company && (
          <Section icon={Building2} title="settings.company">
            <Row label="settings.companyName" hint="settings.companyNameHint" name="company_name" editable={canEdit("company_name")} rules={[{ required: true, max: 150 }]}>
              <Input style={{ width: 220 }} maxLength={150} />
            </Row>
            <Row label="settings.timezone" hint="settings.timezoneHint" name="timezone" editable={canEdit("timezone")}>
              <Select style={{ width: 220 }} showSearch options={timezones().map((z) => ({ value: z, label: z }))} />
            </Row>
            <Row label="settings.country" hint="settings.countryHint" name="country" editable={canEdit("country")} rules={[{ pattern: /^[A-Z]{2}$/ }]}>
              <Input style={{ width: 90 }} maxLength={2} className="mw-mono" />
            </Row>
            <Row label="settings.currency" hint="settings.currencyHint" name="currency" editable={canEdit("currency")} rules={[{ pattern: /^[A-Z]{3}$/ }]}>
              <Input style={{ width: 90 }} maxLength={3} className="mw-mono" />
            </Row>
            <Row label="settings.workingDays" hint="settings.workingDaysHint" name="working_days" editable={canEdit("working_days")} rules={[{ required: true, type: "array", min: 1 }]}>
              <Checkbox.Group options={[1, 2, 3, 4, 5, 6, 7].map((d) => ({ value: d, label: t(`day.${d}` as MessageKey) }))} />
            </Row>
            <Row label="settings.holidayRegion" hint="settings.holidayRegionHint" name="holiday_region" editable={canEdit("holiday_region")} rules={[{ pattern: /^[A-Z]{2}(-[0-9A-Z]{1,3})?$/ }]}>
              <Input style={{ width: 120 }} maxLength={6} className="mw-mono" />
            </Row>
          </Section>
        )}

        {data.security && (
          <Section icon={Fingerprint} title="settings.security">
            <Row label="settings.twoFa" hint="settings.twoFaHint" name="two_fa_enabled" valuePropName="checked" editable={canEdit("two_fa_enabled")}>
              <LabelledSwitch label={t("settings.twoFa")} />
            </Row>
            <Row label="settings.twoFaRoles" hint="settings.twoFaRolesHint" name="two_fa_roles" editable={canEdit("two_fa_roles")}>
              <Checkbox.Group options={(roles.data?.roles ?? []).map((r) => ({ value: r.key, label: r.name }))} />
            </Row>
            <Row label="settings.timeout" hint="settings.timeoutHint" name="session_timeout_minutes" editable={canEdit("session_timeout_minutes")}>
              <Select
                style={{ width: 140 }}
                options={[
                  { value: 15, label: opt("settings.opt.minutes", 15) },
                  { value: 30, label: opt("settings.opt.minutes", 30) },
                  { value: 60, label: opt("settings.opt.hour") },
                  { value: 120, label: opt("settings.opt.hours", 2) },
                  { value: 240, label: opt("settings.opt.hours", 4) },
                ]}
              />
            </Row>
          </Section>
        )}

        {data.work && (
          <Section icon={ListChecks} title="settings.work">
            <Row label="settings.checkin" hint="settings.checkinHint" name="checkin_every_workdays" editable={canEdit("checkin_every_workdays")}>
              <Select style={{ width: 160 }} options={[1, 2, 3, 4, 5].map((n) => ({ value: n, label: n === 1 ? opt("settings.opt.daily") : opt("settings.opt.everyNDays", n) }))} />
            </Row>
            <Row label="settings.checkinMandatory" hint="settings.checkinMandatoryHint" name="checkin_mandatory" valuePropName="checked" editable={canEdit("checkin_mandatory")}>
              <LabelledSwitch label={t("settings.checkinMandatory")} />
            </Row>
            <Row label="settings.extension" hint="settings.extensionHint" name="extension_approval" editable={canEdit("extension_approval")}>
              <Select style={{ width: 160 }} options={(["both", "pm", "sup"] as const).map((v) => ({ value: v, label: opt(`settings.opt.${v}`) }))} />
            </Row>
            <Row label="settings.lateDelay" hint="settings.lateDelayHint" name="auto_late_delay" valuePropName="checked" editable={canEdit("auto_late_delay")}>
              <LabelledSwitch label={t("settings.lateDelay")} />
            </Row>
          </Section>
        )}

        {data.employment && (
          <Section icon={Hourglass} title="settings.employment">
            <Row label="settings.probation" hint="settings.probationHint" name="default_probation_months" editable={canEdit("default_probation_months")}>
              <Select style={{ width: 140 }} options={[0, 1, 2, 3, 4, 5, 6].map((n) => ({ value: n, label: opt("settings.opt.months", n) }))} />
            </Row>
            <Row label="settings.probationReminder" hint="settings.probationReminderHint" name="probation_reminder_days" editable={canEdit("probation_reminder_days")}>
              <Select style={{ width: 140 }} options={[7, 14, 30].map((n) => ({ value: n, label: opt("settings.opt.days", n) }))} />
            </Row>
            <Row label="settings.pip" hint="settings.pipHint" name="pip_default_days" editable={canEdit("pip_default_days")}>
              <Select style={{ width: 140 }} options={[30, 60, 90].map((n) => ({ value: n, label: opt("settings.opt.days", n) }))} />
            </Row>
            <Row label="settings.reviewMonth" hint="settings.reviewMonthHint" name="data_review_month" editable={canEdit("data_review_month")}>
              <Select style={{ width: 140 }} options={months} />
            </Row>
            <Row label="settings.reviewWindow" hint="settings.reviewWindowHint" name="data_review_window_days" editable={canEdit("data_review_window_days")}>
              <Select style={{ width: 140 }} options={[14, 30, 45].map((n) => ({ value: n, label: opt("settings.opt.days", n) }))} />
            </Row>
          </Section>
        )}

        {data.employee_numbers && (
          <Section icon={Hash} title="settings.empNo" hint="settings.empNoHint">
            <Row label="settings.empNoFormat" hint="settings.empNoFormatHint" name="employee_no_format" editable={canEdit("employee_no_format")} rules={[{ required: true, max: 20 }]}>
              <Input style={{ width: 180 }} maxLength={20} className="mw-mono" />
            </Row>
            <Row label="settings.empNoDigits" name="employee_no_digits" editable={canEdit("employee_no_digits")}>
              <Select style={{ width: 140 }} options={[3, 4, 5, 6, 7, 8].map((n) => ({ value: n, label: opt("settings.opt.digits", n) }))} />
            </Row>
            <Row label="settings.empNoNext" name="employee_no_next" editable={canEdit("employee_no_next")}>
              <InputNumber style={{ width: 140 }} min={1} max={99999999} precision={0} />
            </Row>
            <div className="mw-setting-row">
              <div className="flex-1 min-w-0">
                <div className="mw-setting-row__label">{t("settings.empNoPreview")}</div>
              </div>
              <div className="mw-field__readonly mw-mono">{previewNumber(values ?? initial, t("settings.empNoNeedsSeq"))}</div>
            </div>
            {canEdit("employee_no_format") && (
              <div className="flex justify-end mt-2">
                <Button icon={<Hash size={16} />} onClick={renumber}>
                  {t("settings.renumber")}
                </Button>
              </div>
            )}
          </Section>
        )}

        {data.recruitment && (
          <Section icon={UserPlus} title="settings.recruitment">
            <Row label="settings.resume" hint="settings.resumeHint" name="require_resume" valuePropName="checked" editable={canEdit("require_resume")}>
              <LabelledSwitch label={t("settings.resume")} />
            </Row>
            <Row label="settings.retention" hint="settings.retentionHint" name="candidate_retention_months" editable={canEdit("candidate_retention_months")}>
              <Select style={{ width: 140 }} options={[6, 12, 24].map((n) => ({ value: n, label: opt("settings.opt.months", n) }))} />
            </Row>
            <Row label="settings.former" hint="settings.formerHint" name="former_employee_policy" editable={canEdit("former_employee_policy")}>
              <Select style={{ width: 180 }} options={(["interview", "choose", "direct"] as const).map((v) => ({ value: v, label: opt(`settings.opt.${v}`) }))} />
            </Row>
          </Section>
        )}

        {data.kpi && (
          <Section icon={Gauge} title="settings.kpi">
            <Row label="settings.kpiMode" hint="settings.kpiModeHint" name="kpi_mode" editable={canEdit("kpi_mode")}>
              <Select style={{ width: 170 }} options={(["system", "external"] as const).map((v) => ({ value: v, label: opt(`settings.opt.${v}`) }))} />
            </Row>
            <div className="mw-setting-row" style={{ alignItems: "flex-start", flexWrap: "wrap" }}>
              <div className="flex-1 min-w-0">
                <div className="mw-setting-row__label">{t("settings.kpiWeights")}</div>
                <div className="mw-setting-row__hint" style={{ color: weightSum === 100 ? undefined : "var(--bad)" }}>
                  {t("settings.kpiWeightsHint", { sum: weightSum })}
                </div>
              </div>
              <div className="mw-weights">
                {KPI_COMPONENTS.map((k) => (
                  <label key={k} className="mw-weights__item">
                    <span className="mw-weights__label">{t(`kpi.${k}`)}</span>
                    <Form.Item name={["kpi_weights", k]} noStyle>
                      <InputNumber min={0} max={100} precision={0} style={{ width: 72 }} disabled={!canEdit("kpi_weights")} />
                    </Form.Item>
                  </label>
                ))}
              </div>
            </div>
          </Section>
        )}
      </div>

      {anyEditable && (
        <div className="flex items-center justify-end gap-3 mt-4">
          {changed.length > 0 && <span className="text-sm mw-muted">{t("settings.unsaved")}</span>}
          <Button type="primary" htmlType="submit" icon={<Save size={16} />} loading={busy} disabled={!changed.length}>
            {t("settings.save")}
          </Button>
        </div>
      )}
    </Form>
  );
}

function Section({ icon: Icon, title, hint, children }: { icon: LucideIcon; title: MessageKey; hint?: MessageKey; children: React.ReactNode }) {
  const { t } = useI18n();
  return (
    <section className="mw-section">
      <h2 className="mw-section__title">
        <Icon />
        {t(title)}
      </h2>
      {hint && <div className="mw-section__hint">{t(hint)}</div>}
      <div className="mt-1">{children}</div>
    </section>
  );
}

function Row({
  label,
  hint,
  name,
  editable,
  valuePropName,
  rules,
  children,
}: {
  label: MessageKey;
  hint?: MessageKey;
  name: string;
  editable: boolean;
  valuePropName?: string;
  rules?: React.ComponentProps<typeof Form.Item>["rules"];
  children: React.ReactElement<{ disabled?: boolean }>;
}) {
  const { t } = useI18n();
  const id = `set-${name}`;
  return (
    <div className="mw-setting-row">
      <div className="flex-1 min-w-0">
        <label className="mw-setting-row__label" htmlFor={id}>
          {t(label)}
        </label>
        {hint && <div className="mw-setting-row__hint">{t(hint)}</div>}
      </div>
      <div className="flex-none flex justify-end" style={{ maxWidth: "60%" }}>
        <Form.Item name={name} valuePropName={valuePropName} rules={rules} className="mb-0" htmlFor={id}>
          {cloneElement(children, { disabled: !editable, id } as { disabled: boolean })}
        </Form.Item>
      </div>
    </div>
  );
}

/** A switch with its On/Off state written next to it (mw-switch-field). */
function LabelledSwitch({ checked, onChange, disabled, id, label }: { checked?: boolean; onChange?: (v: boolean) => void; disabled?: boolean; id?: string; label: string }) {
  const { t } = useI18n();
  return (
    <span className={`mw-switch-field${checked ? " is-on" : ""}`}>
      <span className="mw-switch-field__state">{t(checked ? "common.on" : "common.off")}</span>
      <Switch id={id} checked={checked} onChange={onChange} disabled={disabled} aria-label={label} />
    </span>
  );
}

function timezones(): string[] {
  try {
    return Intl.supportedValuesOf("timeZone");
  } catch {
    return ["Asia/Kuala_Lumpur", "Asia/Singapore", "UTC"];
  }
}

function previewNumber(v: Values, needsSeq: string): string {
  const format = String(v.employee_no_format ?? "");
  if (!format.includes("{seq}")) return needsSeq;
  const digits = Number(v.employee_no_digits) || 4;
  const seq = String(Number(v.employee_no_next) || 1).padStart(digits, "0");
  const year = String(new Date().getFullYear());
  return format.replaceAll("{seq}", seq).replaceAll("{yyyy}", year).replaceAll("{yy}", year.slice(2)).replaceAll("{dept}", "HR");
}

// Response shapes of the Foundation API. Hand-written until M7 generates them from OpenAPI.

import type { Locale } from "@/i18n";

export type RoleKey = "sysadmin" | "exec" | "hr" | "lead" | "staff";

export type Me = {
  id: string;
  name: string;
  display_name: string;
  email: string;
  avatar_file_id: string | null;
  locale: Locale;
  role: { key: RoleKey; name: string };
  employee: { id: string; employee_no: string | null; full_name: string; department: string | null } | null;
  two_factor: { enabled: boolean; enforced: boolean; setup_required: boolean };
  session_timeout_minutes: number;
};

export type MyPermissions = {
  permissions: string[];
  scope: { all: boolean; has_reports: boolean; leads_teams: boolean; heads_department: boolean };
};

export type Version = { version: string; build: string; commit: string };

export type TwoFactorDevice = { id: string; name: string; added_at: string | null; last_used_at: string | null };
export type TwoFactorStatus = { enabled: boolean; enforced: boolean; max_devices: number; devices: TwoFactorDevice[] };
export type NewDevice = { id: string; name: string; secret: string; otpauth_url: string; qr_svg: string };

export type StoredFile = {
  id: string;
  purpose: string;
  name: string;
  mime: string;
  size: number;
  status: "awaiting_upload" | "pending" | "clean" | "infected";
};
export type Presigned = {
  file: StoredFile;
  upload: { method: "PUT"; url: string; headers: Record<string, string> };
};

export type Settings = {
  company?: {
    company_name: string;
    timezone: string;
    country: string;
    currency: string;
    working_days: number[];
    holiday_region: string | null;
  };
  work?: {
    checkin_every_workdays: number;
    checkin_mandatory: boolean;
    extension_approval: "both" | "pm" | "sup";
    auto_late_delay: boolean;
  };
  security?: { two_fa_enabled: boolean; two_fa_roles: RoleKey[]; session_timeout_minutes: number };
  employment?: {
    default_probation_months: number;
    probation_reminder_days: number;
    pip_default_days: number;
    data_review_month: number;
    data_review_window_days: number;
  };
  employee_numbers?: { employee_no_format: string; employee_no_digits: number; employee_no_next: number };
  recruitment?: {
    require_resume: boolean;
    candidate_retention_months: number;
    former_employee_policy: "interview" | "choose" | "direct";
  };
  kpi?: { kpi_mode: "system" | "external"; kpi_weights: Record<KpiComponent, number> };
};
export type SettingsMeta = { version: number; can_edit: string[] };

export const KPI_COMPONENTS = ["delivery", "ontime", "delay", "checkin", "quality", "rating", "meeting"] as const;
export type KpiComponent = (typeof KPI_COMPONENTS)[number];

export type AccessRole = {
  key: RoleKey;
  name: string;
  note: string | null;
  locked: boolean;
  version: number;
  users: number;
  permissions: string[];
};
export type PermissionDef = { key: string; group: string; label: string };

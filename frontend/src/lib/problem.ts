// RFC 9457 Problem Details from the Laravel API (plan 01 F2), and how the app shows them.

import type { MessageKey } from "@/i18n";

export type ProblemBody = {
  type?: string;
  title?: string;
  status?: number;
  code?: string;
  detail?: string;
  errors?: Record<string, string[]>;
  [extension: string]: unknown;
};

export class ApiError extends Error {
  readonly status: number;
  readonly code: string;
  readonly detail?: string;
  readonly fieldErrors: Record<string, string[]>;
  readonly retryAfter?: number;

  constructor(status: number, body: ProblemBody | null, retryAfter?: number) {
    super(body?.detail ?? body?.title ?? `HTTP ${status}`);
    this.name = "ApiError";
    this.status = status;
    this.code = body?.code ?? (status === 0 ? "NETWORK_ERROR" : `HTTP_${status}`);
    this.detail = body?.detail;
    this.fieldErrors = body?.errors ?? {};
    this.retryAfter = retryAfter;
  }

  get isValidation(): boolean {
    return this.status === 422 && Object.keys(this.fieldErrors).length > 0;
  }
}

/** Codes that mean "you're no longer signed in": send the user back to the sign-in page. */
export const SIGNED_OUT_CODES = new Set(["UNAUTHENTICATED", "SESSION_EXPIRED", "ACCOUNT_DISABLED"]);

/**
 * Field errors in the shape AntD Form.setFields expects. Laravel's dotted names
 * ("kpi_weights.delivery") become name paths so nested fields light up too.
 */
// Name paths are typed per form by AntD; the server decides which fields come back.
// eslint-disable-next-line @typescript-eslint/no-explicit-any
export function toFormFields(err: ApiError): { name: any; errors: string[] }[] {
  return Object.entries(err.fieldErrors).map(([field, errors]) => ({
    name: field.split(".").map((p) => (/^\d+$/.test(p) ? Number(p) : p)),
    errors,
  }));
}

/**
 * One line for a toast. The API's own detail is already written for people; we only
 * fall back to our dictionary when there is nothing better.
 */
export function problemMessage(err: unknown, t: (k: MessageKey) => string): string {
  if (!(err instanceof ApiError)) return t("error.generic");
  if (err.status === 0) return t("error.network");
  if (err.isValidation) {
    const first = Object.values(err.fieldErrors)[0]?.[0];
    return first ?? t("error.validation");
  }
  if (err.detail) return err.detail;
  if (err.status === 403) return t("error.forbidden");
  if (err.status === 404) return t("error.notFound");
  return t("error.generic");
}

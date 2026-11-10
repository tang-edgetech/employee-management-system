// Thin client for /api/v1. The web app signs in with Sanctum's session cookie: the
// first state-changing request fetches /sanctum/csrf-cookie, then every write sends
// the XSRF token back as a header. Typed clients from OpenAPI replace the hand-written
// types in M7.

import { ApiError, type ProblemBody } from "./problem";

const BASE = "/api/v1";
const WRITE = new Set(["POST", "PUT", "PATCH", "DELETE"]);

type Options = {
  method?: "GET" | "POST" | "PUT" | "PATCH" | "DELETE";
  body?: unknown;
  /** Version for optimistic concurrency; sent as If-Match: "<version>". */
  ifMatch?: number | string;
  /** Sent on create/commit POSTs so a retried click never does the work twice. */
  idempotent?: boolean;
};

export type ApiResponse<T> = { data: T; etag: string | null; status: number; /** The whole JSON body, e.g. for `meta`. */ raw: unknown };

function readCookie(name: string): string | null {
  const match = document.cookie.split("; ").find((c) => c.startsWith(name + "="));
  return match ? decodeURIComponent(match.slice(name.length + 1)) : null;
}

let csrfReady: Promise<void> | null = null;

function ensureCsrf(force = false): Promise<void> {
  if (force || !csrfReady || !readCookie("XSRF-TOKEN")) {
    csrfReady = fetch("/sanctum/csrf-cookie", { credentials: "same-origin" }).then((r) => {
      if (!r.ok) throw new ApiError(r.status, null);
    });
  }
  return csrfReady;
}

async function send<T>(path: string, opts: Options, retried: boolean): Promise<ApiResponse<T>> {
  const method = opts.method ?? "GET";
  const headers: Record<string, string> = { Accept: "application/json" };

  if (WRITE.has(method)) {
    await ensureCsrf();
    const token = readCookie("XSRF-TOKEN");
    if (token) headers["X-XSRF-TOKEN"] = token;
  }
  if (opts.body !== undefined) headers["Content-Type"] = "application/json";
  if (opts.ifMatch !== undefined) headers["If-Match"] = `"${opts.ifMatch}"`;
  if (opts.idempotent) headers["Idempotency-Key"] = crypto.randomUUID();

  let res: Response;
  try {
    res = await fetch(BASE + path, {
      method,
      headers,
      credentials: "same-origin",
      body: opts.body === undefined ? undefined : JSON.stringify(opts.body),
    });
  } catch {
    throw new ApiError(0, null);
  }

  // 419 = the CSRF token went stale (e.g. the session was renewed). Fetch a new one once.
  if (res.status === 419 && !retried) {
    await ensureCsrf(true);
    return send<T>(path, opts, true);
  }

  const text = await res.text();
  const json = text ? (JSON.parse(text) as unknown) : null;

  if (!res.ok) {
    const retryAfter = Number(res.headers.get("Retry-After")) || undefined;
    throw new ApiError(res.status, json as ProblemBody | null, retryAfter);
  }

  const etag = res.headers.get("ETag");
  const payload = json && typeof json === "object" && "data" in json ? (json as { data: T }).data : (json as T);
  return { data: payload, etag: etag ? etag.replace(/^W\//, "").replace(/"/g, "") : null, status: res.status, raw: json };
}

export function api<T>(path: string, opts: Options = {}): Promise<ApiResponse<T>> {
  return send<T>(path, opts, false);
}

/** Shortcut when only the body matters. */
export async function apiData<T>(path: string, opts: Options = {}): Promise<T> {
  return (await api<T>(path, opts)).data;
}

/** After sign-in or sign-out the session ID changes; the next write needs a fresh token. */
export function resetCsrf(): void {
  csrfReady = null;
}

import { describe, expect, it } from "vitest";
import en from "@/i18n/en.json";
import { ApiError, problemMessage, toFormFields } from "./problem";
import { passwordRules } from "./password";

const t = (k: keyof typeof en) => en[k];

describe("Problem Details mapping", () => {
  it("reads code, detail and field errors", () => {
    const err = new ApiError(422, { code: "VALIDATION_FAILED", detail: "Check the fields.", errors: { email: ["Required."] } });
    expect(err.code).toBe("VALIDATION_FAILED");
    expect(err.isValidation).toBe(true);
    expect(toFormFields(err)).toEqual([{ name: ["email"], errors: ["Required."] }]);
  });

  it("turns dotted field names into name paths", () => {
    const err = new ApiError(422, { errors: { "kpi_weights.delivery": ["Too high."], "working_days.0": ["Bad day."] } });
    expect(toFormFields(err).map((f) => f.name)).toEqual([["kpi_weights", "delivery"], ["working_days", 0]]);
  });

  it("prefers the first field error, then the API's detail, then our own text", () => {
    expect(problemMessage(new ApiError(422, { errors: { name: ["Name is taken."] } }), t)).toBe("Name is taken.");
    expect(problemMessage(new ApiError(409, { code: "ROLE_LOCKED", detail: "Locked role." }), t)).toBe("Locked role.");
    expect(problemMessage(new ApiError(403, { code: "FORBIDDEN" }), t)).toBe(en["error.forbidden"]);
    expect(problemMessage(new ApiError(0, null), t)).toBe(en["error.network"]);
    expect(problemMessage(new Error("boom"), t)).toBe(en["error.generic"]);
  });

  it("names network failures and keeps Retry-After", () => {
    expect(new ApiError(0, null).code).toBe("NETWORK_ERROR");
    expect(new ApiError(429, { code: "LOGIN_LOCKED" }, 120).retryAfter).toBe(120);
  });
});

describe("password rules", () => {
  it("checks every rule the server enforces", () => {
    const met = (pw: string, repeat = pw, current = "Old#Pass1") => passwordRules(pw, repeat, current).filter((r) => !r.met).map((r) => r.key);
    expect(met("New#Pass2026")).toEqual([]);
    expect(met("short")).toEqual(["pw.rule.length", "pw.rule.case", "pw.rule.number", "pw.rule.symbol"]);
    expect(met("New#Pass2026", "different")).toEqual(["pw.rule.match"]);
    expect(met("Old#Pass1", "Old#Pass1", "Old#Pass1")).toEqual(["pw.rule.different"]);
  });
});

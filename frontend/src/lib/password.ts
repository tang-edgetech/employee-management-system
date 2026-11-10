// Live checklist for a new password. Mirrors the server's Password::defaults()
// (min 8, mixed case, numbers, symbols); the server still decides.

import type { MessageKey } from "@/i18n";

export type PasswordRule = { key: MessageKey; met: boolean };

export function passwordRules(next: string, repeat: string, current?: string): PasswordRule[] {
  const rules: PasswordRule[] = [
    { key: "pw.rule.length", met: next.length >= 8 && next.length <= 128 },
    { key: "pw.rule.case", met: /[a-z]/.test(next) && /[A-Z]/.test(next) },
    { key: "pw.rule.number", met: /\d/.test(next) },
    { key: "pw.rule.symbol", met: /[^A-Za-z0-9]/.test(next) },
    { key: "pw.rule.match", met: next !== "" && next === repeat },
  ];
  if (current !== undefined) rules.push({ key: "pw.rule.different", met: next !== "" && next !== current });
  return rules;
}

"use client";

import { useI18n } from "@/i18n";
import type { PasswordRule } from "@/lib/password";

export default function PasswordRules({ rules }: { rules: PasswordRule[] }) {
  const { t } = useI18n();
  return (
    <div className="mw-pw-rules" aria-live="polite">
      {rules.map((r) => (
        <span key={r.key} className={`mw-pw-rules__item${r.met ? " is-met" : ""}`}>
          {t(r.key)}
        </span>
      ))}
    </div>
  );
}

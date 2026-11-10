"use client";

import { useI18n, type Locale } from "@/i18n";
import { useChangeLocale } from "@/lib/session";

const OPTIONS: [Locale, string][] = [
  ["en", "EN"],
  ["zh-CN", "中文"],
];

export default function LangSwitch({ floating = false }: { floating?: boolean }) {
  const { locale, t } = useI18n();
  const change = useChangeLocale();
  return (
    <div className={`mw-lang-switch${floating ? " mw-lang-switch--floating" : ""}`} role="group" aria-label={t("common.language")}>
      {OPTIONS.map(([key, label]) => (
        <button
          key={key}
          type="button"
          className={`mw-lang-switch__opt${locale === key ? " is-active" : ""}`}
          aria-pressed={locale === key}
          onClick={() => change(key)}
        >
          {label}
        </button>
      ))}
    </div>
  );
}

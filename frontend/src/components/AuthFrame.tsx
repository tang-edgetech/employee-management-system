"use client";

import { useI18n } from "@/i18n";
import LangSwitch from "./LangSwitch";

// Sign-in pages: brand panel on the left (wide screens), the form on the right.
// The page fits the window; only the form panel scrolls.
export default function AuthFrame({ children }: { children: React.ReactNode }) {
  const { t } = useI18n();
  return (
    <div className="mw-login">
      <section className="mw-login__art">
        <div className="flex items-center gap-3">
          <span className="mw-logo">M</span>
          <div className="text-[17px] font-extrabold">{t("app.name")}</div>
        </div>
        <div className="max-w-md">
          <h1 className="text-4xl xl:text-5xl font-extrabold leading-[1.05] m-0">{t("app.tagline")}</h1>
          <p className="mt-4 opacity-75 text-base leading-relaxed">{t("app.blurb")}</p>
        </div>
        <div className="text-xs opacity-60">
          {t("common.version")} {process.env.NEXT_PUBLIC_APP_VERSION}
        </div>
      </section>
      <section className="mw-login__panel">
        <LangSwitch floating />
        <div className="mw-login__form">
          <div className="lg:hidden flex items-center gap-3 mb-8">
            <span className="mw-logo">M</span>
            <span className="text-[17px] font-extrabold">{t("app.name")}</span>
          </div>
          {children}
        </div>
      </section>
    </div>
  );
}

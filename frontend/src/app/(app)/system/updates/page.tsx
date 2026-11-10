"use client";

import { useQuery } from "@tanstack/react-query";
import { Clock, Package, ScrollText } from "lucide-react";
import { useI18n } from "@/i18n";
import { apiData } from "@/lib/api";
import { problemMessage } from "@/lib/problem";
import type { Version } from "@/lib/types";

export default function UpdatesPage() {
  const { t } = useI18n();
  const version = useQuery({ queryKey: ["version"], queryFn: () => apiData<Version>("/version"), staleTime: Infinity });

  return (
    <div className="grid gap-4">
      <div className="grid gap-4 xl:grid-cols-[1.2fr_1fr]">
        <section className="mw-section">
          <h2 className="mw-section__title">
            <Package />
            {t("updates.installed")}
          </h2>
          {version.isError ? (
            <p style={{ color: "var(--bad)" }}>{problemMessage(version.error, t)}</p>
          ) : (
            <>
              <div className="mw-version mt-3">
                <span className="mw-version__number">{version.data ? `v${version.data.version}` : "…"}</span>
              </div>
              <dl className="mw-dl">
                <dt>{t("updates.build")}</dt>
                <dd className="mw-mono">{version.data?.build ?? "…"}</dd>
                <dt>{t("updates.commit")}</dt>
                <dd className="mw-mono">{version.data?.commit ?? "…"}</dd>
              </dl>
            </>
          )}
        </section>
        <section className="mw-section">
          <h2 className="mw-section__title">
            <Clock />
            {t("updates.auto")}
          </h2>
          <p className="text-sm mw-muted mt-3 mb-0">{t("updates.autoSoon")}</p>
        </section>
      </div>
      <section className="mw-section">
        <h2 className="mw-section__title">
          <ScrollText />
          {t("updates.log")}
        </h2>
        <p className="text-sm mw-muted mt-3 mb-0">{t("updates.logEmpty")}</p>
      </section>
    </div>
  );
}

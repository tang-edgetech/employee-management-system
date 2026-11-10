"use client";

import { MutationCache, QueryCache, QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { App as AntApp, ConfigProvider, type ThemeConfig } from "antd";
import enUS from "antd/locale/en_US";
import zhCN from "antd/locale/zh_CN";
import { useState } from "react";
import { I18nProvider, useI18n } from "@/i18n";
import { ApiError, SIGNED_OUT_CODES } from "@/lib/problem";
import AntdRegistry from "./antd-registry";

// Ant Design tokens from the design decision (option 6, Straits colours).
const theme: ThemeConfig = {
  token: {
    colorPrimary: "#0b5d5e",
    colorInfo: "#1f6fb2",
    colorSuccess: "#0f7a5c",
    colorWarning: "#a8620a",
    colorError: "#c2372d",
    colorText: "#12302f",
    colorTextSecondary: "#4f6e6c",
    colorBorder: "#d6e2e0",
    colorBgLayout: "#eff4f3",
    borderRadius: 10,
    fontFamily: "var(--font-body)",
    fontSize: 14,
    controlHeight: 38,
  },
  components: {
    Button: { fontWeight: 600, primaryShadow: "none", defaultShadow: "none" },
    Modal: { borderRadiusLG: 16 },
  },
};

const AUTH_PAGES = ["/login", "/two-factor", "/forgot-password", "/reset-password"];

/** Any request that finds the session gone (idle timeout, disabled account) returns to sign-in. */
function onApiError(err: unknown) {
  if (!(err instanceof ApiError) || err.status !== 401 || !SIGNED_OUT_CODES.has(err.code)) return;
  if (AUTH_PAGES.some((p) => window.location.pathname.startsWith(p))) return;
  // A full page load on purpose: it drops every cached query of the old session.
  // eslint-disable-next-line @next/next/no-location-assign-relative-destination
  window.location.assign("/login/");
}

function Localised({ children }: { children: React.ReactNode }) {
  const { locale } = useI18n();
  return (
    <ConfigProvider theme={theme} locale={locale === "zh-CN" ? zhCN : enUS}>
      <AntApp>{children}</AntApp>
    </ConfigProvider>
  );
}

export default function Providers({ children }: { children: React.ReactNode }) {
  const [client] = useState(
    () =>
      new QueryClient({
        queryCache: new QueryCache({ onError: onApiError }),
        mutationCache: new MutationCache({ onError: onApiError }),
        defaultOptions: {
          queries: {
            staleTime: 30_000,
            refetchOnWindowFocus: false,
            // Don't retry what retrying can't fix (signed out, forbidden, validation).
            retry: (count, err) => !(err instanceof ApiError && err.status >= 400 && err.status < 500) && count < 2,
          },
        },
      }),
  );

  return (
    <AntdRegistry>
      <QueryClientProvider client={client}>
        <I18nProvider>
          <Localised>{children}</Localised>
        </I18nProvider>
      </QueryClientProvider>
    </AntdRegistry>
  );
}

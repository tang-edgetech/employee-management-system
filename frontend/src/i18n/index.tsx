"use client";

// English / Chinese via our own JSON dictionaries and a typed t() helper (D13).
// Signed in, the language comes from the user's account; before sign-in it is
// remembered in this browser only.

import { createContext, useCallback, useContext, useEffect, useMemo, useSyncExternalStore } from "react";
import en from "./en.json";
import zh from "./zh-CN.json";

export type Locale = "en" | "zh-CN";
export type MessageKey = keyof typeof en;
export type Vars = Record<string, string | number>;

export const LOCALES: Locale[] = ["en", "zh-CN"];
export const dictionaries: Record<Locale, Record<MessageKey, string>> = { en, "zh-CN": zh };

const STORAGE_KEY = "mw_locale";

/** Replaces {name} placeholders that have a value; unknown placeholders stay as written. */
export function format(text: string, vars?: Vars): string {
  if (!vars) return text;
  return text.replace(/\{(\w+)\}/g, (whole, name: string) => (name in vars ? String(vars[name]) : whole));
}

export function translate(locale: Locale, key: MessageKey, vars?: Vars): string {
  return format(dictionaries[locale][key] ?? en[key] ?? key, vars);
}

// The chosen language lives in localStorage; components subscribe to it. The static
// HTML is rendered in English and switches after hydration.
const listeners = new Set<() => void>();
function subscribe(fn: () => void) {
  listeners.add(fn);
  return () => listeners.delete(fn);
}

export function storedLocale(): Locale {
  try {
    const v = localStorage.getItem(STORAGE_KEY);
    return v === "zh-CN" ? "zh-CN" : "en";
  } catch {
    return "en";
  }
}

type I18n = {
  locale: Locale;
  setLocale: (l: Locale) => void;
  t: (key: MessageKey, vars?: Vars) => string;
};

const I18nContext = createContext<I18n | null>(null);

export function I18nProvider({ children }: { children: React.ReactNode }) {
  const locale = useSyncExternalStore(subscribe, storedLocale, () => "en" as Locale);

  useEffect(() => {
    document.documentElement.lang = locale;
  }, [locale]);

  const setLocale = useCallback((l: Locale) => {
    try {
      localStorage.setItem(STORAGE_KEY, l);
    } catch {
      /* private mode: the browser can't remember it; the account setting still applies */
    }
    listeners.forEach((fn) => fn());
  }, []);

  const value = useMemo<I18n>(
    () => ({ locale, setLocale, t: (key, vars) => translate(locale, key, vars) }),
    [locale, setLocale],
  );

  return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>;
}

export function useI18n(): I18n {
  const ctx = useContext(I18nContext);
  if (!ctx) throw new Error("useI18n must be used inside I18nProvider");
  return ctx;
}

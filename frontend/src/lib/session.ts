"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useEffect } from "react";
import { useI18n, type Locale } from "@/i18n";
import { apiData } from "./api";
import { ApiError } from "./problem";
import type { Me, MyPermissions, StoredFile } from "./types";

export const meKey = ["me"] as const;
export const permissionsKey = ["me", "permissions"] as const;

export function useMe() {
  return useQuery<Me, ApiError>({ queryKey: meKey, queryFn: () => apiData<Me>("/me"), staleTime: 60_000 });
}

/** Not asked while 2FA setup is required: the server only allows setup, /me and logout then. */
export function useMyPermissions(me: Me | undefined) {
  return useQuery<MyPermissions, ApiError>({
    queryKey: permissionsKey,
    queryFn: () => apiData<MyPermissions>("/me/permissions"),
    enabled: !!me && !me.two_factor.setup_required,
  });
}

/** Applies the account's language once the user is known. */
export function useAccountLocale(me: Me | undefined) {
  const { locale, setLocale } = useI18n();
  useEffect(() => {
    if (me && me.locale !== locale) setLocale(me.locale);
    // Only when the account's value changes, not on every local switch.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [me?.locale]);
}

/** Switch language; signed in, the choice is saved to the account. */
export function useChangeLocale() {
  const { setLocale } = useI18n();
  const qc = useQueryClient();
  const save = useMutation({
    mutationFn: (locale: Locale) => apiData<{ locale: Locale }>("/me/preferences", { method: "PATCH", body: { locale } }),
    onSuccess: ({ locale }) => qc.setQueryData<Me>(meKey, (old) => (old ? { ...old, locale } : old)),
  });
  return (locale: Locale) => {
    setLocale(locale);
    const me = qc.getQueryData<Me>(meKey);
    if (me && me.locale !== locale) save.mutate(locale);
  };
}

/** A short-lived URL for a stored file (e.g. the user's own picture). */
export function useFileUrl(fileId: string | null | undefined) {
  return useQuery({
    queryKey: ["file-url", fileId],
    queryFn: () => apiData<{ url: string; expires_at: string }>(`/files/${fileId}/url`),
    enabled: !!fileId,
    staleTime: 4 * 60_000,
    select: (d) => d.url,
  });
}

export type { StoredFile };

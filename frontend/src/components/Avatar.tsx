"use client";

import { useFileUrl } from "@/lib/session";

/** The user's own picture when they have one (only they see it), otherwise their first letter. */
export default function Avatar({ name, size = 36, fileId }: { name: string; size?: number; fileId?: string | null }) {
  const { data: url } = useFileUrl(fileId);
  const letter = (name.trim()[0] ?? "?").toUpperCase();
  return (
    <span className="mw-avatar" style={{ width: size, height: size, fontSize: Math.round(size * 0.4) }} aria-hidden="true">
      {/* eslint-disable-next-line @next/next/no-img-element -- signed, short-lived URL from the API */}
      {url ? <img src={url} alt="" /> : letter}
    </span>
  );
}

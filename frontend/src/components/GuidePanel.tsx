"use client";

import { BookOpen, X } from "lucide-react";
import { useEffect, useRef } from "react";
import { useI18n } from "@/i18n";
import { guideFor } from "@/lib/guides";
import type { PageId } from "@/lib/nav";

/** Right-hand slide-in user guide. Closes with Esc or a click outside; focus stays inside while open. */
export default function GuidePanel({ page, title, onClose }: { page: PageId; title: string; onClose: () => void }) {
  const { t, locale } = useI18n();
  const guide = guideFor(page, locale);
  const panel = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const opener = document.activeElement as HTMLElement | null;
    panel.current?.querySelector<HTMLElement>("button")?.focus();
    function onKey(e: KeyboardEvent) {
      if (e.key === "Escape") onClose();
      if (e.key === "Tab" && panel.current) {
        const focusable = panel.current.querySelectorAll<HTMLElement>("button, a[href], [tabindex]:not([tabindex='-1'])");
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (e.shiftKey && document.activeElement === first) {
          e.preventDefault();
          last?.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
          e.preventDefault();
          first?.focus();
        }
      }
    }
    document.addEventListener("keydown", onKey);
    return () => {
      document.removeEventListener("keydown", onKey);
      opener?.focus();
    };
  }, [onClose]);

  return (
    <div className="mw-guide-wrap" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      <aside className="mw-guide" role="dialog" aria-modal="true" aria-labelledby="mw-guide-title" ref={panel}>
        <div className="mw-guide__head">
          <BookOpen size={18} />
          <div className="flex-1 min-w-0">
            <div className="text-xs opacity-75">{t("shell.userGuide")}</div>
            <div id="mw-guide-title" className="font-bold truncate">
              {title}
            </div>
          </div>
          <button type="button" className="mw-icon-btn" onClick={onClose} aria-label={t("common.close")}>
            <X />
          </button>
        </div>
        <div className="mw-guide__body">
          <p className="mw-guide__intro">{guide.intro}</p>
          {guide.steps.length > 0 && (
            <>
              <div className="mw-guide__h">{t("guide.steps")}</div>
              <ol className="mw-guide__steps">
                {guide.steps.map((s) => (
                  <li key={s}>{s}</li>
                ))}
              </ol>
            </>
          )}
          {guide.tips.length > 0 && (
            <>
              <div className="mw-guide__h">{t("guide.tips")}</div>
              <ul className="mw-guide__tips">
                {guide.tips.map((s) => (
                  <li key={s}>{s}</li>
                ))}
              </ul>
            </>
          )}
        </div>
        <div className="mw-guide__foot">{t("guide.foot")}</div>
      </aside>
    </div>
  );
}

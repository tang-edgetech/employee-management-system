"use client";

import { CalendarDays, ClipboardCheck, ListTodo, MessageSquareWarning, type LucideIcon } from "lucide-react";
import { useI18n, type MessageKey } from "@/i18n";
import { useMe } from "@/lib/session";

// Placeholder tiles (plan 01 §9.3): each module fills its own tile when it ships.
const TILES: { label: MessageKey; module: MessageKey; icon: LucideIcon }[] = [
  { label: "dashboard.tile.tasks", module: "module.tasks", icon: ListTodo },
  { label: "dashboard.tile.checkins", module: "module.tasks", icon: MessageSquareWarning },
  { label: "dashboard.tile.approvals", module: "module.tasks", icon: ClipboardCheck },
  { label: "dashboard.tile.meetings", module: "module.meetings", icon: CalendarDays },
];

export default function DashboardPage() {
  const { t } = useI18n();
  const { data: me } = useMe();
  if (!me) return null;

  return (
    <div className="grid gap-4">
      <div className="mw-greet">
        <b>{t("dashboard.greeting", { name: me.display_name })}</b> {t("dashboard.greetingBody")}
      </div>
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {TILES.map(({ label, module, icon: Icon }) => (
          <section key={label} className="mw-tile" aria-label={t(label)}>
            <div className="flex items-center justify-between">
              <span className="text-sm font-semibold">{t(label)}</span>
              <span className="mw-device__icon" style={{ width: 32, height: 32 }}>
                <Icon size={16} />
              </span>
            </div>
            <div className="mw-tile__num mt-3">—</div>
            <div className="text-xs mw-muted mt-2">{t("dashboard.tile.soon", { module: t(module) })}</div>
          </section>
        ))}
      </div>
    </div>
  );
}

"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { App, Tooltip } from "antd";
import { Bell, Info, LogOut, Menu, PanelLeftClose, PanelLeftOpen, Search } from "lucide-react";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useCallback, useEffect, useState, useSyncExternalStore } from "react";
import Avatar from "@/components/Avatar";
import GuidePanel from "@/components/GuidePanel";
import LangSwitch from "@/components/LangSwitch";
import { useI18n, type MessageKey } from "@/i18n";
import { apiData, resetCsrf } from "@/lib/api";
import { canSee, groupOf, itemForPath, visibleNav, type PageId } from "@/lib/nav";
import { ApiError, problemMessage, SIGNED_OUT_CODES } from "@/lib/problem";
import { useAccountLocale, useMe, useMyPermissions } from "@/lib/session";
import type { Version } from "@/lib/types";

const PROFILE_PATH = "/profile/";

export default function AppLayout({ children }: { children: React.ReactNode }) {
  const { t } = useI18n();
  const { modal, message } = App.useApp();
  const router = useRouter();
  const pathname = usePathname();
  const qc = useQueryClient();

  const me = useMe();
  const perms = useMyPermissions(me.data);
  const version = useQuery({ queryKey: ["version"], queryFn: () => apiData<Version>("/version"), enabled: !!me.data, staleTime: Infinity });
  useAccountLocale(me.data);

  const rail = useSyncExternalStore(subscribeRail, () => document.documentElement.dataset.sb === "rail", () => false);
  // The mobile menu and the guide belong to the page they were opened on; navigating closes them.
  const [mobileFor, setMobileFor] = useState<string | null>(null);
  const [guideFor, setGuideFor] = useState<string | null>(null);
  const mobileOpen = mobileFor === pathname;
  const guideOpen = guideFor === pathname;
  const setMobileOpen = (open: boolean) => setMobileFor(open ? pathname : null);
  const setGuideOpen = (open: boolean) => setGuideFor(open ? pathname : null);

  const signedOut = me.error instanceof ApiError && (me.error.status === 401 || SIGNED_OUT_CODES.has(me.error.code));
  const setupRequired = !!me.data?.two_factor.setup_required;
  const isProfile = pathname === PROFILE_PATH || pathname === "/profile";
  const item = itemForPath(pathname);
  const permissions = perms.data?.permissions;
  const allowed = !item || !permissions || canSee(item, permissions);

  // Guards: signed out → sign-in page; 2FA setup required → only My Profile; page not allowed → dashboard.
  useEffect(() => {
    if (signedOut) router.replace("/login/");
    else if (setupRequired && !isProfile) router.replace(PROFILE_PATH + "?tab=security");
    else if (!allowed) router.replace("/dashboard/");
  }, [signedOut, setupRequired, isProfile, allowed, router]);

  const toggleRail = useCallback(() => {
    const next = !rail;
    document.documentElement.dataset.sb = next ? "rail" : "open";
    railListeners.forEach((fn) => fn());
    try {
      localStorage.setItem("mw_sb", next ? "rail" : "open");
    } catch {
      /* remembered for this page only */
    }
  }, [rail]);

  function askSignOut() {
    modal.confirm({
      title: t("shell.signOutTitle"),
      content: t("shell.signOutBody"),
      okText: t("shell.signOut"),
      cancelText: t("common.cancel"),
      icon: <LogOut size={20} style={{ color: "var(--accent)", marginInlineEnd: 12 }} />,
      onOk: async () => {
        try {
          await apiData("/auth/logout", { method: "POST" });
        } catch (err) {
          if (!(err instanceof ApiError && err.status === 401)) message.error(problemMessage(err, t));
        }
        resetCsrf();
        qc.clear();
        router.replace("/login/");
      },
    });
  }

  if (me.isError && !signedOut) {
    return (
      <div className="mw-splash">
        <div className="text-center">
          <p>{problemMessage(me.error, t)}</p>
          <button type="button" className="mw-chip mw-chip--accent" onClick={() => me.refetch()}>
            {t("common.retry")}
          </button>
        </div>
      </div>
    );
  }
  if (!me.data || signedOut || (setupRequired && !isProfile) || (!setupRequired && !permissions) || !allowed) {
    return <div className="mw-splash">{t("common.loading")}</div>;
  }

  const user = me.data;
  const ownPeopleOnly = !perms.data?.scope.all && !(permissions ?? []).includes("people.edit");
  const nav = setupRequired ? [] : visibleNav(permissions ?? []);
  const pageId: PageId = isProfile ? "profile" : (item?.id ?? "dashboard");
  const title = isProfile ? t("nav.profile") : item ? labelFor(item.id, item.label, ownPeopleOnly, t) : "";
  const crumb = isProfile ? t("nav.group.overview") : item ? t(groupOf(item).label) : "";

  return (
    <div className="mw-app">
      <div className={`mw-backdrop${mobileOpen ? " is-open" : ""}`} onClick={() => setMobileOpen(false)} />
      <aside className={`mw-sidebar${mobileOpen ? " is-open" : ""}`} aria-label={t("shell.menu")}>
        <div className="mw-sidebar__head">
          <span className="mw-logo">M</span>
          <div className="mw-label min-w-0">
            <div className="mw-sidebar__title">{t("app.name")}</div>
          </div>
        </div>
        <nav className="mw-sidebar__body">
          {nav.map((group) => (
            <div key={group.label}>
              <div className="mw-sidebar__group">
                <span className="mw-label">{t(group.label)}</span>
              </div>
              {group.items.map((i) => {
                const label = labelFor(i.id, i.label, ownPeopleOnly, t);
                const Icon = i.icon;
                return (
                  <Tooltip key={i.id} title={rail ? label : null} placement="right">
                    <Link
                      href={i.href}
                      className={`mw-nav-item${item?.id === i.id ? " is-active" : ""}`}
                      aria-current={item?.id === i.id ? "page" : undefined}
                      aria-label={rail ? label : undefined}
                    >
                      <Icon />
                      <span className="mw-label flex-1">{label}</span>
                    </Link>
                  </Tooltip>
                );
              })}
            </div>
          ))}
        </nav>
        <div className="mw-sidebar__toggle">
          <button type="button" className="mw-nav-item" onClick={toggleRail} aria-label={t(rail ? "shell.expand" : "shell.collapse")}>
            {rail ? <PanelLeftOpen /> : <PanelLeftClose />}
            <span className="mw-label">{t("shell.collapse")}</span>
          </button>
        </div>
        {version.data && (
          <div className="mw-sidebar__version" title={`${t("common.version")} ${version.data.version} · ${version.data.build} · ${version.data.commit}`}>
            v{version.data.version}
          </div>
        )}
        <div className="mw-sidebar__footer">
          <Tooltip title={rail ? t("shell.myProfile") : null} placement="right">
            <Link href={PROFILE_PATH} className={`mw-profile-card${isProfile ? " is-active" : ""}`} aria-label={t("shell.myProfile")}>
              <Avatar name={user.display_name} fileId={user.avatar_file_id} />
              <span className="mw-label min-w-0">
                <span className="mw-profile-card__name">{user.display_name}</span>
                <span className="mw-profile-card__role">{user.role.name}</span>
              </span>
            </Link>
          </Tooltip>
          <Tooltip title={t("shell.signOut")}>
            <button type="button" className="mw-icon-btn" onClick={askSignOut} aria-label={t("shell.signOut")}>
              <LogOut />
            </button>
          </Tooltip>
        </div>
      </aside>

      <main className="mw-main">
        <header className="mw-topbar">
          <button type="button" className="mw-icon-btn mw-topbar__menu" onClick={() => setMobileOpen(true)} aria-label={t("shell.menu")}>
            <Menu />
          </button>
          <div className="flex-1 min-w-0">
            <div className="mw-topbar__crumb">
              {crumb} / {title}
            </div>
            <div className="mw-page-heading">
              <h1 className="mw-topbar__title">{title}</h1>
              <Tooltip title={t("shell.userGuide")}>
                <button
                  type="button"
                  className="mw-icon-btn mw-help-btn"
                  onClick={() => setGuideOpen(true)}
                  aria-label={t("shell.userGuide")}
                  aria-haspopup="dialog"
                >
                  <Info />
                </button>
              </Tooltip>
            </div>
          </div>
          <label className="mw-topbar__search hidden md:flex">
            <Search size={16} />
            <input
              placeholder={t("shell.search")}
              aria-label={t("shell.search")}
              onKeyDown={(e) => e.key === "Enter" && message.info(t("shell.searchSoon"))}
            />
          </label>
          <span className="hidden sm:inline-flex">
            <LangSwitch />
          </span>
          <Tooltip title={t("shell.notifications")}>
            <button type="button" className="mw-icon-btn hidden sm:inline-grid" onClick={() => message.info(t("shell.notificationsSoon"))} aria-label={t("shell.notifications")}>
              <Bell />
            </button>
          </Tooltip>
        </header>
        <div className="mw-page">{children}</div>
      </main>

      {guideOpen && <GuidePanel page={pageId} title={title} onClose={() => setGuideOpen(false)} />}
    </div>
  );
}

const railListeners = new Set<() => void>();
function subscribeRail(fn: () => void) {
  railListeners.add(fn);
  return () => railListeners.delete(fn);
}

/** People who only see their own reporting line get "My People" instead of "Employees" (mockup). */
function labelFor(id: PageId, label: MessageKey, ownPeopleOnly: boolean, t: (k: MessageKey) => string) {
  return t(id === "employees" && ownPeopleOnly ? "nav.myPeople" : label);
}

// The menu (mockup NAV) and who sees each item. Visibility is decided from the
// user's permissions (GET /me/permissions), never from the role name, so editing a
// role on the Access Roles page changes the menu too. The server checks every
// request again; this only decides what the app shows.

import type { MessageKey } from "@/i18n";
import type { LucideIcon } from "lucide-react";
import {
  Briefcase,
  Building2,
  CalendarClock,
  ClipboardPen,
  Contact,
  FileBadge,
  FolderKanban,
  Gauge,
  GitBranch,
  Handshake,
  LayoutDashboard,
  Layers,
  ListTodo,
  Network,
  Plug,
  Presentation,
  Repeat,
  ShieldCheck,
  SlidersHorizontal,
  Tags,
  Users,
  UsersRound,
} from "lucide-react";

export type PageId =
  | "dashboard"
  | "profile"
  | "mytasks"
  | "projects"
  | "meetings"
  | "kpi"
  | "vacancies"
  | "candidates"
  | "interviews"
  | "employees"
  | "lifecycle"
  | "review"
  | "teams"
  | "orgchart"
  | "departments"
  | "designations"
  | "emptypes"
  | "tags"
  | "partners"
  | "integrations"
  | "access"
  | "config"
  | "updates";

/** Which module brings a page that isn't built yet ("Coming in <module>", D3). */
export type ModuleKey = "tasks" | "meetings" | "kpi" | "recruitment" | "people" | "organization" | "integrations";

export type NavItem = {
  id: PageId;
  href: string;
  label: MessageKey;
  icon: LucideIcon;
  /** Shown when the user has ANY of these permissions; empty = every signed-in user. */
  anyOf: string[];
  /** Set while the page is a placeholder. */
  module?: ModuleKey;
};

export type NavGroup = { label: MessageKey; items: NavItem[] };

export const NAV: NavGroup[] = [
  {
    label: "nav.group.overview",
    items: [{ id: "dashboard", href: "/dashboard/", label: "nav.dashboard", icon: LayoutDashboard, anyOf: [] }],
  },
  {
    label: "nav.group.work",
    items: [
      { id: "mytasks", href: "/my-tasks/", label: "nav.myTasks", icon: ListTodo, anyOf: [], module: "tasks" },
      { id: "projects", href: "/projects/", label: "nav.projects", icon: FolderKanban, anyOf: [], module: "tasks" },
      { id: "meetings", href: "/meetings/", label: "nav.meetings", icon: Presentation, anyOf: [], module: "meetings" },
      { id: "kpi", href: "/kpi/", label: "nav.kpi", icon: Gauge, anyOf: [], module: "kpi" },
    ],
  },
  {
    label: "nav.group.recruitment",
    items: [
      { id: "vacancies", href: "/vacancies/", label: "nav.vacancies", icon: Briefcase, anyOf: ["candidate.create", "interview.manage"], module: "recruitment" },
      { id: "candidates", href: "/candidates/", label: "nav.candidates", icon: Contact, anyOf: ["candidate.create", "interview.manage"], module: "recruitment" },
      { id: "interviews", href: "/interviews/", label: "nav.interviews", icon: CalendarClock, anyOf: ["candidate.create", "interview.manage"], module: "recruitment" },
    ],
  },
  {
    label: "nav.group.people",
    items: [
      { id: "employees", href: "/employees/", label: "nav.employees", icon: Users, anyOf: [], module: "people" },
      { id: "lifecycle", href: "/lifecycle/", label: "nav.lifecycle", icon: Repeat, anyOf: ["people.lifecycle"], module: "people" },
      { id: "review", href: "/data-review/", label: "nav.dataReview", icon: ClipboardPen, anyOf: ["people.lifecycle", "people.data_review.approve"], module: "people" },
      { id: "teams", href: "/teams/", label: "nav.teams", icon: UsersRound, anyOf: ["org.teams.manage", "people.edit"], module: "organization" },
      { id: "orgchart", href: "/org-chart/", label: "nav.orgChart", icon: Network, anyOf: [], module: "organization" },
    ],
  },
  {
    label: "nav.group.organization",
    items: [
      { id: "departments", href: "/departments/", label: "nav.departments", icon: Building2, anyOf: ["org.structure.manage"], module: "organization" },
      { id: "designations", href: "/designations/", label: "nav.designations", icon: Layers, anyOf: ["org.structure.manage"], module: "organization" },
      { id: "emptypes", href: "/employment-types/", label: "nav.employmentTypes", icon: FileBadge, anyOf: ["org.structure.manage"], module: "organization" },
      { id: "tags", href: "/task-tags/", label: "nav.taskTags", icon: Tags, anyOf: ["org.task_tags.manage", "org.structure.manage"], module: "organization" },
      { id: "partners", href: "/partners/", label: "nav.partners", icon: Handshake, anyOf: ["org.structure.manage"], module: "tasks" },
    ],
  },
  {
    label: "nav.group.system",
    items: [
      { id: "integrations", href: "/integrations/", label: "nav.integrations", icon: Plug, anyOf: ["people.import", "kpi.configure"], module: "integrations" },
      { id: "access", href: "/system/access-roles/", label: "nav.accessRoles", icon: ShieldCheck, anyOf: ["system.settings"] },
      { id: "config", href: "/system/settings/", label: "nav.settings", icon: SlidersHorizontal, anyOf: ["system.settings"] },
      { id: "updates", href: "/system/updates/", label: "nav.updates", icon: GitBranch, anyOf: ["system.settings"] },
    ],
  },
];

export const ALL_ITEMS: NavItem[] = NAV.flatMap((g) => g.items);

export function canSee(item: Pick<NavItem, "anyOf">, permissions: readonly string[]): boolean {
  return item.anyOf.length === 0 || item.anyOf.some((p) => permissions.includes(p));
}

/** The menu for these permissions; groups with nothing visible are dropped. */
export function visibleNav(permissions: readonly string[]): NavGroup[] {
  return NAV.map((g) => ({ ...g, items: g.items.filter((i) => canSee(i, permissions)) })).filter((g) => g.items.length > 0);
}

/** The menu item a path belongs to (paths are served with a trailing slash). */
export function itemForPath(pathname: string): NavItem | undefined {
  const p = pathname.endsWith("/") ? pathname : pathname + "/";
  return ALL_ITEMS.find((i) => p === i.href);
}

export function groupOf(item: NavItem): NavGroup {
  return NAV.find((g) => g.items.includes(item))!;
}

/** Placeholder pages, keyed by their URL segment, for generateStaticParams. */
export const COMING_SOON = ALL_ITEMS.filter((i) => i.module && !i.href.startsWith("/system/")).map((i) => ({
  slug: i.href.replaceAll("/", ""),
  item: i,
}));

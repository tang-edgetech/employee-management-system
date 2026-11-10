import { describe, expect, it } from "vitest";
import { ALL_ITEMS, COMING_SOON, itemForPath, visibleNav, type PageId } from "./nav";

// Default grants from the permissions migration (2026_11_06_100000_create_permissions_tables).
const PERMISSIONS: Record<string, string[]> = {
  "org.structure.manage": ["exec", "hr"],
  "org.teams.manage": ["exec", "hr"],
  "org.task_tags.manage": ["exec", "lead"],
  "people.edit": ["exec", "hr", "lead"],
  "people.import": ["hr"],
  "people.salary.view": ["exec", "hr"],
  "people.lifecycle": ["exec", "hr"],
  "people.data_review.approve": ["hr"],
  "candidate.create": ["hr"],
  "candidate.view_identity": ["exec", "hr"],
  "interview.manage": ["exec", "hr", "lead"],
  "project.manage": ["exec", "lead"],
  "extension.approve": ["exec", "lead", "staff"],
  "delay.record": ["exec", "lead", "staff"],
  "kpi.view_all": ["exec", "hr"],
  "kpi.configure": ["exec", "hr"],
  "system.settings": [],
};
const grantsFor = (role: string) =>
  role === "sysadmin" ? Object.keys(PERMISSIONS) : Object.entries(PERMISSIONS).filter(([, roles]) => roles.includes(role)).map(([p]) => p);

// The mockup's menu per role (ACCESS, without My Profile which lives in the sidebar
// footer); "partners" is the mockup's External Partners page (D25).
const MOCKUP: Record<string, PageId[]> = {
  sysadmin: ALL_ITEMS.map((i) => i.id),
  exec: ["dashboard", "mytasks", "projects", "meetings", "kpi", "vacancies", "candidates", "interviews", "employees", "lifecycle", "review", "teams", "orgchart", "departments", "designations", "emptypes", "tags", "partners", "integrations"],
  hr: ["dashboard", "mytasks", "projects", "meetings", "kpi", "vacancies", "candidates", "interviews", "employees", "lifecycle", "review", "teams", "orgchart", "departments", "designations", "emptypes", "tags", "partners", "integrations"],
  lead: ["dashboard", "mytasks", "projects", "meetings", "kpi", "vacancies", "candidates", "interviews", "employees", "teams", "orgchart", "tags"],
  staff: ["dashboard", "mytasks", "projects", "meetings", "kpi", "employees", "orgchart"],
};

describe("menu filtering", () => {
  for (const [role, expected] of Object.entries(MOCKUP)) {
    it(`shows ${role} the same pages as the mockup`, () => {
      const ids = visibleNav(grantsFor(role)).flatMap((g) => g.items.map((i) => i.id));
      expect(ids.sort()).toEqual([...expected].sort());
    });
  }

  it("drops groups with nothing visible", () => {
    const groups = visibleNav(grantsFor("staff")).map((g) => g.label);
    expect(groups).toEqual(["nav.group.overview", "nav.group.work", "nav.group.people"]);
  });
});

describe("routes", () => {
  it("finds the item for a path with or without the trailing slash", () => {
    expect(itemForPath("/system/settings")?.id).toBe("config");
    expect(itemForPath("/my-tasks/")?.id).toBe("mytasks");
    expect(itemForPath("/nope/")).toBeUndefined();
  });

  it("has a placeholder for every unbuilt page, and none for built ones", () => {
    const slugs = COMING_SOON.map((c) => c.slug);
    expect(slugs).toContain("my-tasks");
    expect(slugs).not.toContain("dashboard");
    expect(slugs.every((s) => !s.includes("/"))).toBe(true);
  });

  it("has unique ids and hrefs", () => {
    expect(new Set(ALL_ITEMS.map((i) => i.id)).size).toBe(ALL_ITEMS.length);
    expect(new Set(ALL_ITEMS.map((i) => i.href)).size).toBe(ALL_ITEMS.length);
  });
});

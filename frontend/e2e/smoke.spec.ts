import { expect, test, type Page } from "@playwright/test";

// Each demo account signs in, sees exactly its menu, and opens every page it is
// allowed with no console errors (plan 01 §9.3). Expected menus follow the default
// role permissions; see src/lib/nav.test.ts for the same table against the mockup.

const ALL = {
  dashboard: "/dashboard/",
  mytasks: "/my-tasks/",
  projects: "/projects/",
  meetings: "/meetings/",
  kpi: "/kpi/",
  vacancies: "/vacancies/",
  candidates: "/candidates/",
  interviews: "/interviews/",
  employees: "/employees/",
  lifecycle: "/lifecycle/",
  review: "/data-review/",
  teams: "/teams/",
  orgchart: "/org-chart/",
  departments: "/departments/",
  designations: "/designations/",
  emptypes: "/employment-types/",
  tags: "/task-tags/",
  partners: "/partners/",
  integrations: "/integrations/",
  access: "/system/access-roles/",
  config: "/system/settings/",
  updates: "/system/updates/",
} as const;
type Id = keyof typeof ALL;

const work: Id[] = ["dashboard", "mytasks", "projects", "meetings", "kpi"];
const office: Id[] = [...work, "vacancies", "candidates", "interviews", "employees", "lifecycle", "review", "teams", "orgchart", "departments", "designations", "emptypes", "tags", "partners", "integrations"];

const ACCOUNTS: { email: string; name: string; menu: Id[] }[] = [
  { email: "tang@hajimi.dev", name: "Tang", menu: Object.keys(ALL) as Id[] },
  { email: "ahfa@hajimi.my", name: "Ah Fa", menu: office },
  { email: "ellie@hajimi.my", name: "Ellie", menu: office },
  { email: "teechienyap@hajimi.my", name: "Tee Chien Yap", menu: [...work, "vacancies", "candidates", "interviews", "employees", "teams", "orgchart", "tags"] },
  { email: "jeffyeoh@hajimi.my", name: "Jeff Yeoh", menu: [...work, "employees", "orgchart"] },
  { email: "sinyee@hajimi.my", name: "Sin Yee", menu: [...work, "employees", "orgchart"] },
];

function watchConsole(page: Page): string[] {
  const errors: string[] = [];
  page.on("console", (m) => m.type() === "error" && errors.push(m.text()));
  page.on("pageerror", (e) => errors.push(e.message));
  return errors;
}

async function signIn(page: Page, email: string) {
  await page.goto("/login/");
  await page.locator(".mw-login__account", { hasText: email }).click();
  await page.waitForURL("**/dashboard/");
  await expect(page.locator(".mw-topbar__title")).toBeVisible();
}

test("sign-in page and a failed attempt", async ({ page }) => {
  const errors = watchConsole(page);
  await page.goto("/login/");
  // Let React hydrate first: a screenshot hides the caret by styling inputs, which
  // would otherwise show up as a hydration mismatch.
  await page.waitForLoadState("networkidle");
  await expect(page.getByRole("heading", { name: "Sign in" })).toBeVisible();
  await page.screenshot({ path: "test-results/screens/login.png" });

  await page.getByLabel("Work email").fill("nobody@example.test");
  await page.getByLabel("Password", { exact: true }).fill("Wrong#Password1");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await expect(page.locator(".ant-form-item-explain-error").first()).toBeVisible();
  await expect(page).toHaveURL(/\/login\/$/);
  await page.screenshot({ path: "test-results/screens/login-failed.png" });
  // The failed sign-in itself is answered with 422; that request shows as a console error.
  expect(errors.filter((e) => !e.includes("422"))).toEqual([]);
});

for (const account of ACCOUNTS) {
  test(`${account.name} sees exactly their menu and every page opens`, async ({ page }) => {
    const errors = watchConsole(page);
    await signIn(page, account.email);
    if (account.email === "tang@hajimi.dev") await page.screenshot({ path: "test-results/screens/dashboard.png" });

    const hrefs = await page.locator(".mw-sidebar__body a.mw-nav-item").evaluateAll((links) => links.map((a) => a.getAttribute("href")));
    expect(hrefs.sort()).toEqual(account.menu.map((id) => ALL[id]).sort());

    for (const href of [...hrefs, "/profile/"]) {
      await page.goto(href!);
      await expect(page.locator(".mw-topbar__title")).not.toBeEmpty();
      await expect(page.locator(".mw-page")).toBeVisible();
      await page.waitForLoadState("networkidle");
    }

    // A page outside the menu sends you back to the dashboard.
    const forbidden = (Object.keys(ALL) as Id[]).find((id) => !account.menu.includes(id));
    if (forbidden) {
      await page.goto(ALL[forbidden]);
      await page.waitForURL("**/dashboard/");
    }

    expect(errors).toEqual([]);

    await page.locator(".mw-sidebar__footer button[aria-label='Sign out']").click();
    await page.getByRole("button", { name: "Sign out" }).last().click();
    await page.waitForURL("**/login/");
  });
}

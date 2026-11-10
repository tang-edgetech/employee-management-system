import { defineConfig } from "@playwright/test";

// Smoke tests run against the dev site at http://meridian.localhost (Apache → Laravel
// API + `npm run dev`), with the demo seed loaded and 2FA switched off in Global
// Settings. They use the installed Chrome, so no browser download is needed.
export default defineConfig({
  testDir: "./e2e",
  timeout: 60_000,
  fullyParallel: false,
  workers: 1,
  reporter: [["list"]],
  use: {
    baseURL: process.env.E2E_BASE_URL ?? "http://meridian.localhost",
    channel: "chrome",
    viewport: { width: 1280, height: 800 },
    trace: "retain-on-failure",
  },
});

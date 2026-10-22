/**
 * Records the feature tour and the handbook screenshots; run through
 * demo/tour/run.sh, which prepares the site and sets the locale.
 */
import { defineConfig, devices } from "@playwright/test";

export const AUTH = "demo/tour/out/.auth.json";
const size = { width: 1280, height: 800 };
const recording = process.env.TOUR_LOCALE === "en";

export default defineConfig({
  testDir: ".",
  outputDir: "out/results",
  timeout: 900_000,
  workers: 1,
  reporter: [["list"], ["html", { open: "never", outputFolder: "out/report" }]],
  use: {
    actionTimeout: 30_000,
    trace: "retain-on-failure",
    navigationTimeout: 60_000,
    baseURL: process.env.TOUR_BASE_URL ?? "http://localhost:8080",
  },
  projects: [
    { name: "setup", testMatch: /auth\.setup\.ts/ },
    {
      name: "tour",
      testMatch: /tour\.spec\.ts/,
      dependencies: ["setup"],
      use: {
        ...devices["Desktop Chrome"],
        storageState: AUTH,
        viewport: size,
        video: recording ? { mode: "on", size } : "off",
      },
    },
  ],
});

import { expect, test as setup } from "@playwright/test";
import { AUTH } from "./playwright.config";

setup("log in as admin", async ({ page }) => {
  await page.goto("/civicrm/login");
  await page.locator("crm-login input:not([type=password])").first().fill("admin");
  await page.locator("crm-login input[type=password]").fill("admin");
  await page.locator("crm-login button").first().click();
  await expect(page).not.toHaveURL(/\/civicrm\/login/, { timeout: 30_000 });
  await page.context().storageState({ path: AUTH });
});

/**
 * One walk through herald's screens. In English it records the video with a
 * caption per step; in every locale it writes the handbook screenshots.
 * Selectors use ids and URLs, never visible text, so both locales share them.
 */
import { readFileSync } from "node:fs";
import { expect, test, type Locator, type Page } from "@playwright/test";

const LOCALE = process.env.TOUR_LOCALE ?? "en";
const RECORDING = LOCALE === "en";
const IMAGES = LOCALE === "en" ? "handbook/images" : `handbook/images/${LOCALE}`;
const MAILDEV = process.env.TOUR_MAILDEV_URL ?? "http://localhost:1080";
const LOAD = { timeout: 30_000 };

interface State {
  rule: number;
  ruleAction: number;
  ruleCondition: number;
  digestRule: number;
  digestRuleAction: number;
  digest: number;
  subscriber: number;
  profile: number;
}
const state = JSON.parse(readFileSync("demo/tour/out/state.json", "utf8")) as State;

/** Shows the caption at the bottom of the video; screenshots never contain it. */
async function caption(page: Page, text: string) {
  if (!RECORDING) return;
  await page.evaluate((line) => {
    let bar = document.getElementById("herald-tour-caption");
    if (!bar) {
      bar = document.createElement("div");
      bar.id = "herald-tour-caption";
      bar.style.cssText =
        "position:fixed;left:0;right:0;bottom:0;z-index:99999;padding:18px 32px;" +
        "background:rgba(20,24,33,.92);color:#fff;font:600 22px/1.35 system-ui,sans-serif;" +
        "text-align:center;pointer-events:none";
      document.body.appendChild(bar);
    }
    bar.textContent = line;
  }, text);
  await pause(2_500);
}

/** Only the video needs time to read. */
const pause = (ms: number) =>
  new Promise<void>((done) => {
    setTimeout(done, RECORDING ? ms : 0);
  });

async function go(page: Page, path: string, ready: string) {
  await page.goto(path);
  await page.waitForLoadState("networkidle");
  await expect(page.locator(ready).first()).toBeVisible(LOAD);
}

async function shot(page: Page, name: string, area?: Locator) {
  const bar = page.locator("#herald-tour-caption");
  if (await bar.count()) await bar.evaluate((el) => (el.style.display = "none"));
  await page.mouse.move(0, 0);
  const path = `${IMAGES}/${name}.png`;
  // Toasts would cover the page, and the fixed menu bar repeats in a full-page shot.
  await page.addStyleTag({
    content:
      "#crm-notification-container, #civicrm-menu-nav { display: none !important; } body { padding-top: 0 !important; }",
  });
  if (area) await area.screenshot({ path });
  else await page.screenshot({ path, fullPage: true });
  if (await bar.count()) await bar.evaluate((el) => (el.style.display = ""));
}

async function type(field: Locator, text: string) {
  await field.fill("");
  await field.pressSequentially(text, { delay: RECORDING ? 45 : 0 });
}

/** Picks the option of an Angular select whose value ends in the given key. */
async function choose(select: Locator, key: string) {
  const value = await select
    .locator("option")
    .evaluateAll(
      (options, wanted) =>
        options
          .map((o) => (o as HTMLOptionElement).value)
          .find((v) => v === wanted || v.endsWith(`:${wanted}`)),
      key,
    );
  if (value === undefined) throw new Error(`No option ${key}`);
  await select.selectOption(value);
}

const ADMIN = "/civicrm/admin/herald";
const CONTENT = "#crm-container";

test("herald tour", async ({ page }) => {
  await go(page, `${ADMIN}#/`, ".herald-admin li");
  await caption(
    page,
    "herald sends notifications and digests from CiviCRM to chats, phones, inboxes and activities.",
  );
  await pause(1_500);
  await shot(page, "admin");

  // Channels
  await go(page, `${ADMIN}#/channels`, ".herald-admin table tbody tr");
  await caption(
    page,
    "A channel holds an account: a Telegram bot, an ntfy server, a webhook or the CiviCRM mailer.",
  );
  await shot(page, "channels");
  await go(page, `${ADMIN}#/channels/new`, "#herald-channel-name");
  await caption(page, "Add a channel: here an email channel for the team.");
  await type(page.locator("#herald-channel-name"), "team_mail");
  await type(page.locator("#herald-channel-label"), "Team email");
  await choose(page.locator("#herald-channel-type"), "email");
  await expect(page.locator("#herald-channel-email_from")).toBeVisible(LOAD);
  await type(page.locator("#herald-channel-email_from"), '"Herald" <herald@example.org>');
  await type(page.locator("#herald-channel-email_to"), "team@example.org");
  await pause(1_000);
  await shot(page, "channel-form");
  await page.locator(".herald-admin button[type=submit]").click();
  await expect(page).toHaveURL(/#\/channels$/, LOAD);
  await pause(1_500);

  // Topics
  await go(page, `${ADMIN}#/topics/new`, "#herald-topic-name");
  await caption(
    page,
    "Publishers name a topic. Its route decides the channel, the recipients and the aggregation.",
  );
  await type(page.locator("#herald-topic-name"), "donations");
  await type(page.locator("#herald-topic-label"), "Donations");
  await choose(page.locator("#herald-topic-channel"), "team_mail");
  await expect(page.locator("#herald-topic-aggregate_minutes")).toBeVisible(LOAD);
  await type(page.locator("#herald-topic-aggregate_minutes"), "10");
  await caption(
    page,
    "Alerts that come in bursts are collected and sent as one message every ten minutes.",
  );
  await shot(page, "topic-form");
  await page.locator(".herald-admin button[type=submit]").click();
  await expect(page).toHaveURL(/#\/topics$/, LOAD);
  await expect(page.locator(".herald-admin table tbody tr").first()).toBeVisible(LOAD);
  await caption(
    page,
    "The list shows where each topic goes, who subscribed to it and what uses it.",
  );
  await shot(page, "topics");

  // Subscriptions
  await go(page, `/civicrm/contact/view?reset=1&cid=${state.subscriber}`, CONTENT);
  await caption(page, "Contacts can subscribe to a topic and choose their own channel.");
  const preferences = page
    .locator(".customFieldGroup", {
      has: page.locator("[id*=herald_preferences], [class*=herald_preferences]"),
    })
    .first();
  if (await preferences.count()) await preferences.scrollIntoViewIfNeeded();
  await pause(1_500);
  await shot(page, "contact-preferences");
  await go(page, `/civicrm/profile/edit?gid=${state.profile}&reset=1`, "form");
  await caption(page, "Staff edit their own preferences in a profile form.");
  await shot(page, "profile");

  // Test message
  await go(page, `${ADMIN}#/test`, "#herald-test-message");
  await caption(
    page,
    "Send a test message to the subscribers of a topic. Each of them gets it on their own channel.",
  );
  await page.locator("#herald-test-target-topic").check();
  await choose(page.locator("#herald-test-topic"), "news");
  await type(page.locator("#herald-test-message"), "The newsletter goes out on Friday.");
  await shot(page, "test");
  await page.locator(".herald-admin button[type=submit]").click();
  await expect(page.locator(".herald-admin-status")).not.toBeEmpty(LOAD);
  await pause(2_000);
  if (RECORDING) {
    await page.goto(MAILDEV);
    await caption(page, "The three subscribers received it by email.");
    await pause(3_000);
  }

  // Template preview
  await go(page, `${ADMIN}#/preview`, "#herald-preview-template");
  await caption(
    page,
    "Messages come from CiviCRM message templates. Preview one with sample data.",
  );
  await choose(page.locator("#herald-preview-template"), "herald_contribution_alert_completed");
  await type(
    page.locator("#herald-preview-vars"),
    '{"contribution": {"total_amount": 50, "currency": "EUR"}}',
  );
  await page.locator(".herald-admin button[type=submit]").click();
  await expect(page.locator("#herald-preview-result")).not.toBeEmpty(LOAD);
  await pause(1_500);
  await shot(page, "preview");

  // CiviRules
  await go(page, `/civicrm/civirule/form/rule?reset=1&action=update&id=${state.rule}`, CONTENT);
  await caption(
    page,
    "With CiviRules, a rule sends an alert when a contribution moves from Pending to Completed.",
  );
  await shot(page, "rule");
  await go(
    page,
    `/civicrm/herald/form/civirulescondition/fieldtransition?reset=1&rule_condition_id=${state.ruleCondition}`,
    "#from_operator, [name=from_operator]",
  );
  await caption(page, "The condition compares a field before and after the edit.");
  await shot(page, "condition");
  await go(
    page,
    `/civicrm/herald/form/civirulesaction/sendnotification?reset=1&rule_action_id=${state.ruleAction}`,
    "#delivery_mode",
  );
  await caption(page, "The action renders a template and sends it to the subscribers of a topic.");
  await shot(page, "action");
  if (RECORDING) {
    await caption(page, "It can also go to one recipient, a group or a list of addresses.");
    for (const mode of ["direct", "group", "addresses", "subscribers"]) {
      await page.locator("#delivery_mode").selectOption(mode);
      await pause(1_500);
    }
  }

  // Digests
  await go(page, "/civicrm/admin/herald/digests?reset=1", "table tbody tr");
  await caption(page, "A digest sums up SearchKit results, such as yesterday's contributions.");
  await pause(1_500);
  await shot(page, "digests");
  await go(
    page,
    `/civicrm/admin/herald/digest#?HeraldDigest1=${state.digest}`,
    "[herald-digest-preview] button",
  );
  await caption(page, "Its text has placeholders for each source. Preview it with today's data.");
  await page.locator("[herald-digest-preview] button").first().click();
  await expect(page.locator("iframe.herald-digest-preview")).toBeVisible(LOAD);
  await pause(1_500);
  await shot(page, "digest");
  await go(
    page,
    `/civicrm/herald/form/civirulestrigger/schedule?reset=1&rule_id=${state.digestRule}`,
    CONTENT,
  );
  await caption(page, "The trigger Herald: Schedule fires at a set hour on chosen weekdays.");
  await shot(page, "schedule");
  await go(
    page,
    `/civicrm/herald/form/civirulesaction/senddigest?reset=1&rule_action_id=${state.digestRuleAction}`,
    "#delivery_mode",
  );
  await caption(page, "The action Herald: Send digest sends it.");
  await shot(page, "digest-action");

  // Operations
  await go(page, `${ADMIN}#/monitor`, ".herald-admin form");
  await caption(
    page,
    "The check monitor reports when a health check starts failing and when it recovers.",
  );
  await shot(page, "monitor");
  await go(page, `${ADMIN}#/cleaning`, ".herald-admin form");
  await caption(
    page,
    "Cleaned fields map messy values, such as payment sources, to a few labels for digests.",
  );
  await shot(page, "cleaning");
  await go(page, `${ADMIN}#/hosts`, "#herald-hosts");
  await caption(page, "Webhooks may only reach the hosts listed here.");
  await shot(page, "hosts");
  await go(
    page,
    "/civicrm/search#/display/Herald_Parked_Alerts/Herald_Parked_Alerts_Table",
    "table tbody tr",
  );
  await caption(
    page,
    "Alerts that could not be delivered are parked, never lost, until you reactivate them.",
  );
  await pause(1_500);
  await shot(page, "parked");
  await go(page, "/civicrm/admin/setting/herald?reset=1", "form");
  await caption(page, "Single values live on the settings form.");
  await shot(page, "settings");
  await caption(page, "herald: notifications and digests for CiviCRM.");
  await pause(2_000);
});

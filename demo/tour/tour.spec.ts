/**
 * Walks through herald's screens in six parts. In English each part records a
 * video with captions and a frame around what a caption explains; in every
 * locale the parts write the handbook screenshots.
 * Selectors use ids and URLs, never visible text, so both locales share them.
 */
import { readFileSync } from "node:fs";
import { expect, test, type Locator, type Page } from "@playwright/test";

const LOCALE = process.env.TOUR_LOCALE ?? "en";
const RECORDING = LOCALE === "en";
const IMAGES = LOCALE === "en" ? "handbook/images" : `handbook/images/${LOCALE}`;
const MAILDEV = process.env.TOUR_MAILDEV_URL ?? "http://localhost:1080";
const LOAD = { timeout: 30_000 };
// Subtitle pace: about 14 characters a second, never under four seconds.
const MS_PER_CHAR = 70;
const MIN_READ = 4_000;

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

/** Only the video needs time to read. */
const pause = (ms: number) =>
  new Promise<void>((done) => {
    setTimeout(done, RECORDING ? ms : 0);
  });

/**
 * Scrolls a caption's element into the room between menu bar and caption and
 * frames it; fails the run when it stays hidden, covered or out of view.
 */
async function frame(target: Locator, text: string) {
  await expect(target, `caption target of "${text}"`).toBeVisible(LOAD);
  const problem = await target.evaluate((el) => {
    const menu = document.getElementById("civicrm-menu");
    const bar = document.getElementById("herald-tour-caption");
    const top = Math.max(0, menu?.getBoundingClientRect().bottom ?? 0) + 12;
    const bottom = (bar?.getBoundingClientRect().top ?? innerHeight) - 12;
    let box = el.getBoundingClientRect();
    const room = bottom - top;
    // Centred when it fits, else its top edge under the menu bar; the margins
    // keep both bars clear in whichever container scrolls.
    el.style.scrollMarginTop = `${top}px`;
    el.style.scrollMarginBottom = `${innerHeight - bottom}px`;
    el.scrollIntoView({ block: box.height <= room ? "center" : "start", behavior: "instant" });
    box = el.getBoundingClientRect();
    if (box.top < top - 1 || box.top > bottom || (box.height <= room && box.bottom > bottom + 1)) {
      return `outside the visible room (top ${Math.round(box.top)}, bottom ${Math.round(box.bottom)})`;
    }
    const x = box.left + Math.min(box.width, innerWidth - box.left) / 2;
    const y = box.top + Math.min(box.height, bottom - box.top) / 2;
    const hit = document.elementFromPoint(x, y);
    return hit && (el === hit || el.contains(hit))
      ? ""
      : `covered by ${hit?.tagName}.${hit?.className}`;
  });
  expect(problem, `caption target of "${text}"`).toBe("");
  await target.evaluate((el) => {
    el.style.outline = "4px solid #f5a623";
    el.style.outlineOffset = "4px";
  });
}

/**
 * Shows a caption at the bottom of the video for as long as it takes to read,
 * with a frame around the element it is about. Screenshots never contain either.
 */
async function say(page: Page, text: string, target?: Locator) {
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
    // Lets the last element of a page scroll above the caption.
    document.body.style.paddingBottom = `${bar.offsetHeight}px`;
  }, text);
  if (target) {
    await frame(target, text);
  }
  await pause(Math.max(MIN_READ, text.length * MS_PER_CHAR));
  if (target) {
    await target.evaluate((el) => {
      el.style.outline = "";
    });
  }
}

const PARTS = 6;

/** A full-screen card; every part opens with the same one. */
async function card(page: Page, line: string, part = "") {
  if (!RECORDING) return;
  await page.setContent(
    `<body style="margin:0;display:grid;place-content:center;height:100vh;background:#141821;color:#fff;font-family:system-ui,sans-serif;text-align:center">
      <h1 style="font-size:52px;margin:0 0 12px">herald</h1>
      <p style="font-size:24px;margin:0 0 40px;opacity:.75">Notifications and digests for CiviCRM</p>
      <p style="font-size:30px;margin:0;font-weight:600">${part}</p>
      <p style="font-size:24px;margin:12px 0 0;opacity:.85">${line}</p></body>`,
  );
  await pause(5_000);
}

const intro = (page: Page, part: number, name: string, line: string) =>
  card(page, line, `Part ${part} of ${PARTS}: ${name}`);

async function go(page: Page, path: string, ready: string) {
  await page.goto(path);
  await page.waitForLoadState("networkidle");
  await expect(page.locator(ready).first()).toBeVisible(LOAD);
  await pause(1_500);
}

async function shot(page: Page, name: string) {
  const bar = page.locator("#herald-tour-caption");
  const padding = await page.evaluate(() => document.body.style.paddingBottom);
  if (await bar.count()) await bar.evaluate((el) => (el.style.display = "none"));
  await page.evaluate(() => (document.body.style.paddingBottom = ""));
  await page.mouse.move(0, 0);
  // Toasts would cover the page, and the fixed menu bar repeats in a full-page shot.
  await page.addStyleTag({
    content:
      "#crm-notification-container, #civicrm-menu-nav { display: none !important; } body { padding-top: 0 !important; }",
  });
  await page.screenshot({ path: `${IMAGES}/${name}.png`, fullPage: true });
  if (await bar.count()) await bar.evaluate((el) => (el.style.display = ""));
  await page.evaluate((value) => (document.body.style.paddingBottom = value), padding);
}

async function type(field: Locator, text: string) {
  await field.fill("");
  await field.pressSequentially(text, { delay: RECORDING ? 90 : 0 });
  await pause(800);
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
  await pause(800);
}

const ADMIN = "/civicrm/admin/herald";
const CONTENT = "#crm-container";
const SUBMIT = ".herald-admin button[type=submit]";

test.describe.configure({ mode: "serial" });

// Each part is one video, saved under the part's title.
test.afterEach(async ({ page }, info) => {
  const video = page.video();
  await page.close();
  if (video) await video.saveAs(`demo/tour/out/${info.title}.webm`);
});

test("herald-1-channels-and-topics", async ({ page }) => {
  await intro(page, 1, "Channels and topics", "Where messages go");
  await go(page, `${ADMIN}#/`, ".herald-admin li");
  await say(
    page,
    "herald sends notifications and digests from CiviCRM to chats, phones, inboxes and CiviCRM activities.",
  );
  await say(page, "Everything starts on the herald administration page under Administer » Herald.");
  await shot(page, "admin");

  await go(page, `${ADMIN}#/channels`, ".herald-admin table tbody tr");
  const channels = page.locator(".herald-admin table").first();
  await say(
    page,
    "A channel is one account of a service: a Telegram bot, an ntfy server, Pushover, a webhook or the CiviCRM mailer.",
    channels,
  );
  await say(
    page,
    "The list shows each channel's type, whether contacts may choose it, and which topics and rules use it.",
    channels,
  );
  await shot(page, "channels");

  await go(page, `${ADMIN}#/channels/new`, "#herald-channel-name");
  await say(page, "Let's add a channel: an email channel for the team.");
  await type(page.locator("#herald-channel-name"), "team_mail");
  await say(
    page,
    "Topics and rules refer to a channel by this name.",
    page.locator("#herald-channel-name"),
  );
  await type(page.locator("#herald-channel-label"), "Team email");
  await choose(page.locator("#herald-channel-type"), "email");
  await say(page, "The type decides which fields follow.", page.locator("#herald-channel-type"));
  await expect(page.locator("#herald-channel-email_from")).toBeVisible(LOAD);
  await type(page.locator("#herald-channel-email_from"), '"Herald" <herald@example.org>');
  await type(page.locator("#herald-channel-email_to"), "team@example.org");
  await say(
    page,
    "Sender and recipient are defaults: a message that names no recipient of its own goes to this address.",
    page.locator("#herald-channel-email_to"),
  );
  await shot(page, "channel-form");
  await page.locator(SUBMIT).click();
  await expect(page).toHaveURL(/#\/channels$/, LOAD);
  await say(page, "Saved. The new channel is ready to use.");

  await go(page, `${ADMIN}#/topics/new`, "#herald-topic-name");
  await say(
    page,
    "Rules and other extensions never name a channel or a recipient. They publish to a topic, such as donations.",
  );
  await type(page.locator("#herald-topic-name"), "donations");
  await type(page.locator("#herald-topic-label"), "Donations");
  await choose(page.locator("#herald-topic-channel"), "team_mail");
  await say(
    page,
    "The topic's route decides where its messages go: here, to the team email channel.",
    page.locator("#herald-topic-channel"),
  );
  await expect(page.locator("#herald-topic-aggregate_minutes")).toBeVisible(LOAD);
  await type(page.locator("#herald-topic-aggregate_minutes"), "10");
  await say(
    page,
    "Alerts that come in bursts can be collected: with ten minutes, the team gets one message every ten minutes instead of many.",
    page.locator("#herald-topic-aggregate_minutes"),
  );
  await shot(page, "topic-form");
  await page.locator(SUBMIT).click();
  await expect(page).toHaveURL(/#\/topics$/, LOAD);
  await expect(page.locator(".herald-admin table tbody tr").first()).toBeVisible(LOAD);
  await pause(1_500);
  await say(
    page,
    "The topics list shows where each topic goes, who subscribed to it and which rules publish to it.",
    page.locator(".herald-admin table").first(),
  );
  await shot(page, "topics");
});

test("herald-2-subscriptions", async ({ page }) => {
  await intro(page, 2, "Subscriptions", "Contacts choose their topics and their channel");
  await go(page, `/civicrm/contact/view?reset=1&cid=${state.subscriber}`, CONTENT);
  await say(
    page,
    "Contacts can subscribe to topics themselves and pick the channel they want them on.",
  );
  const preferences = page.locator(".customFieldGroup.herald_preferences");
  await say(
    page,
    "On a contact, the notification preferences hold the preferred channel and the subscribed topics.",
    preferences,
  );
  await say(
    page,
    "Ada subscribed to the topic Newsletter team and receives it by email.",
    preferences,
  );
  await shot(page, "contact-preferences");

  await go(page, `/civicrm/profile/edit?gid=${state.profile}&reset=1`, "form");
  await say(
    page,
    "Staff edit their own preferences in a profile form, without access to the contact record.",
  );
  await say(
    page,
    "Only channels and topics marked as open to contacts are offered here.",
    page.locator("form").first(),
  );
  await shot(page, "profile");
});

test("herald-3-test-and-templates", async ({ page }) => {
  await intro(page, 3, "Test message and templates", "Check a route, preview a message");
  await go(page, `${ADMIN}#/test`, "#herald-test-message");
  await say(page, "The test page sends a real message, to check a channel or a topic route.");
  await page.locator("#herald-test-target-topic").check();
  await choose(page.locator("#herald-test-topic"), "news");
  await say(
    page,
    "Sent through the topic Newsletter team, it reaches every subscriber, each on their own channel.",
    page.locator("#herald-test-topic"),
  );
  await type(page.locator("#herald-test-message"), "The newsletter goes out on Friday.");
  await shot(page, "test");
  await page.locator(SUBMIT).click();
  await expect(page.locator(".herald-admin-status")).not.toBeEmpty(LOAD);
  await say(
    page,
    "herald reports how many recipients it reached.",
    page.locator(".herald-admin-status"),
  );
  if (RECORDING) {
    await page.goto(MAILDEV);
    await pause(1_500);
    await say(page, "In this demo's mail catcher: the three subscribers received it by email.");
  }

  await go(page, `${ADMIN}#/preview`, "#herald-preview-template");
  await say(
    page,
    "Messages come from CiviCRM message templates, so they are edited like any other template.",
  );
  await choose(page.locator("#herald-preview-template"), "herald_contribution_alert_completed");
  await type(
    page.locator("#herald-preview-vars"),
    '{"contribution": {"total_amount": 50, "currency": "EUR"}}',
  );
  await say(
    page,
    "The preview renders a template with sample values. Nothing is sent.",
    page.locator("#herald-preview-vars"),
  );
  await page.locator(SUBMIT).click();
  await expect(page.locator("#herald-preview-result")).not.toBeEmpty(LOAD);
  await say(
    page,
    "This is the message as a channel would get it.",
    page.locator("#herald-preview-result"),
  );
  await shot(page, "preview");
});

test("herald-4-alerts-with-civirules", async ({ page }) => {
  await intro(page, 4, "Alerts with CiviRules", "A message when something happens in CiviCRM");
  await go(page, `/civicrm/civirule/form/rule?reset=1&action=update&id=${state.rule}`, CONTENT);
  await say(page, "With CiviRules, herald sends alerts when something happens in CiviCRM.");
  await say(page, "This rule fires whenever a contribution changes.");
  await expect(page.locator("afsearch-rule-actions tbody tr").first()).toBeVisible(LOAD);
  await say(
    page,
    "Its condition comes from herald: the contribution's status moved from Pending to Completed.",
    page.locator("afsearch-rule-conditions"),
  );
  await say(
    page,
    "Its action comes from herald too: send a notification.",
    page.locator("afsearch-rule-actions"),
  );
  await shot(page, "rule");

  await go(
    page,
    `/civicrm/herald/form/civirulescondition/fieldtransition?reset=1&rule_condition_id=${state.ruleCondition}`,
    "#from_operator, [name=from_operator]",
  );
  await say(
    page,
    "The condition Herald: Field transition compares a field before and after the edit.",
    page.locator("#field"),
  );
  await say(
    page,
    "Here: the status moved from Pending to Completed. So the rule fires once, not on every later edit.",
    page.locator("#to_value"),
  );
  await shot(page, "condition");

  await go(
    page,
    `/civicrm/herald/form/civirulesaction/sendnotification?reset=1&rule_action_id=${state.ruleAction}`,
    "#delivery_mode",
  );
  await say(
    page,
    "The action Herald: Send notification renders a template…",
    page.locator("#template"),
  );
  await say(
    page,
    "…and sends it to everyone subscribed to the topic Newsletter team.",
    page.locator("#subscribers_of"),
  );
  await say(
    page,
    "With an aggregation window, many completed contributions become one message.",
    page.locator("#aggregate_minutes"),
  );
  await shot(page, "action");
  if (RECORDING) {
    await say(
      page,
      "Instead of subscribers, it can also go to one recipient, a group, or a list of email addresses.",
      page.locator("#delivery_mode"),
    );
    for (const mode of ["direct", "group", "addresses", "subscribers"]) {
      await page.locator("#delivery_mode").selectOption(mode);
      await pause(2_000);
    }
  }
});

test("herald-5-digests", async ({ page }) => {
  await intro(page, 5, "Digests", "Recurring reports from SearchKit");
  await go(page, "/civicrm/admin/herald/digests?reset=1", "table tbody tr");
  await say(
    page,
    "A digest is a recurring report, such as yesterday's contributions or last week's activities.",
    page.locator("table").first(),
  );
  await say(
    page,
    "It is a subject and a text you write yourself, plus the sources that fill their placeholders.",
  );
  await shot(page, "digests");

  // A new digest, built on screen from two ready-made sources.
  await go(page, "/civicrm/admin/herald/digest", 'af-field[name="title"] input');
  await say(
    page,
    "Let's build a weekly report from scratch.",
    page.locator("[herald-digest-intro]"),
  );
  await type(page.locator('af-field[name="title"] input').first(), "Weekly report");
  await type(page.locator('af-field[name="name"] input').first(), "weekly_report");
  await say(
    page,
    "Rules and scripts pick the digest by its name: lowercase letters, digits and underscores.",
    page.locator('af-field[name="name"]').first(),
  );

  const sources = page.locator(".herald-digest-sources > [af-repeat-item]");
  const addSource = page.locator(".af-repeat-add-btn");
  if ((await sources.count()) === 0) await addSource.click();
  const first = sources.nth(0);
  await expect(first).toBeVisible(LOAD);
  await say(
    page,
    "Each source is one set of figures. Ready-made sources cover contributions and activities.",
    first.locator(".herald-digest-source-kind"),
  );
  await say(
    page,
    "An own search from SearchKit takes any table, list or grid display; a section comes from another extension.",
    first.locator(".herald-digest-source-kind"),
  );
  await type(first.locator('af-field[name="name"] input'), "donations");
  await choose(first.locator('select[id$="-figure"]'), "contributions");
  await choose(first.locator('select[id$="-period"]'), "Last_Week");
  await say(
    page,
    "This one: the sum and count of last week's contributions, under the name donations.",
    first.locator(".herald-digest-ready-made-pick"),
  );
  const firstGuide = first.locator("[herald-digest-placeholders]");
  await expect(firstGuide.locator(".herald-digest-snippet")).toBeVisible(LOAD);
  await say(
    page,
    "Below the source, the form lists its placeholders, named after the column headers of the search.",
    firstGuide.locator(".herald-digest-placeholders"),
  );
  await say(
    page,
    "It also offers a snippet that uses them, with amounts and dates already formatted.",
    firstGuide.locator(".herald-digest-snippet"),
  );

  await addSource.click();
  const second = sources.nth(1);
  await expect(second).toBeVisible(LOAD);
  await type(second.locator('af-field[name="name"] input'), "forms");
  await choose(second.locator('select[id$="-figure"]'), "contributions_by_form");
  await choose(second.locator('select[id$="-period"]'), "Last_Week");
  const secondGuide = second.locator("[herald-digest-placeholders]");
  await expect(secondGuide.locator(".herald-digest-snippet")).toBeVisible(LOAD);
  await say(
    page,
    "A second source splits the same week by contribution form. Its snippet draws a bar chart.",
    secondGuide.locator(".herald-digest-snippet"),
  );

  const subject = page.locator('af-field[name="msg_subject"] input');
  await type(subject, "Weekly report");
  await say(page, "The subject. Left empty, the title is used.", subject);
  const text = page.locator('af-field[name="msg_html"] textarea');
  await type(text, "📊 <b>Last week</b>\n");
  for (const guide of [firstGuide, secondGuide]) {
    const snippet = await guide.locator(".herald-digest-snippet").inputValue();
    await page.keyboard.insertText(`${snippet}\n`);
    await pause(1_000);
  }
  await say(
    page,
    "The text is written freely: here a headline and the two snippets, pasted in.",
    page.locator('af-field[name="msg_html"]'),
  );
  await say(
    page,
    "It is rendered with Smarty, like a message template. A placeholder with a typo is an error, not an empty line.",
    page.locator('af-field[name="msg_html"]'),
  );

  const preview = page.locator("[herald-digest-preview]");
  await preview.locator("button").first().click();
  await expect(page.locator("iframe.herald-digest-preview")).toBeVisible(LOAD);
  await say(
    page,
    "The preview composes the digest with today's data. It saves and sends nothing.",
    page.locator("iframe.herald-digest-preview"),
  );
  await preview.locator("button").nth(1).click();
  await expect(preview.locator(".alert-success")).toBeVisible(LOAD);
  await say(
    page,
    "Send a test to me emails it to your own address, without saving it.",
    preview.locator(".alert-success"),
  );
  await page.locator("button.af-button").click();
  await expect(page).toHaveURL(/herald\/digests/, LOAD);
  await expect(page.locator("table tbody tr").first()).toBeVisible(LOAD);
  await say(
    page,
    "Saving checks the whole digest first. Now it is in the list, ready for a rule.",
    page.locator("table").first(),
  );
  if (RECORDING) {
    await page.goto(MAILDEV);
    await pause(1_500);
    await say(page, "In this demo's mail catcher: the test digest as it arrived.");
  }

  await go(
    page,
    `/civicrm/admin/herald/digest#?HeraldDigest1=${state.digest}`,
    "[herald-digest-preview] button",
  );
  await say(
    page,
    "The demo's daily report combines three sources: totals, contributions by form and activities.",
    page.locator(".herald-digest-sources"),
  );
  await page.locator("[herald-digest-preview] button").first().click();
  await expect(page.locator("iframe.herald-digest-preview")).toBeVisible(LOAD);
  await say(
    page,
    "Bars grow in eighths of a block, so a small value still shows.",
    page.locator("iframe.herald-digest-preview"),
  );
  await shot(page, "digest");

  await go(
    page,
    `/civicrm/herald/form/civirulestrigger/schedule?reset=1&rule_id=${state.digestRule}`,
    CONTENT,
  );
  await say(
    page,
    "A CiviRules rule sends a digest. Its trigger Herald: Schedule fires at a set hour…",
    page.locator("#hour"),
  );
  await say(
    page,
    "…on the chosen weekdays, or every day when none is chosen.",
    page.locator("#s2id_weekdays"),
  );
  await say(
    page,
    "If cron misses the hour, the next run catches up, and after a longer outage only the latest slot is sent.",
  );
  await shot(page, "schedule");

  await go(
    page,
    `/civicrm/herald/form/civirulesaction/senddigest?reset=1&rule_action_id=${state.digestRuleAction}`,
    "#delivery_mode",
  );
  await say(page, "Its action Herald: Send digest picks the digest…", page.locator("#digest"));
  await say(
    page,
    "…and where it goes, with the same delivery modes as an alert.",
    page.locator("#delivery_mode"),
  );
  await say(
    page,
    "Without a rule, a crontab or a scheduled job can call the API action Herald.notifyDigest.",
  );
  await shot(page, "digest-action");
});

test("herald-6-operations", async ({ page }) => {
  await intro(
    page,
    6,
    "Operations",
    "Monitoring, cleaned fields and alerts that could not be sent",
  );
  await go(page, `${ADMIN}#/monitor`, ".herald-admin form");
  await say(
    page,
    "The check monitor runs health checks that extensions contribute, on every cron run.",
  );
  await say(page, "It reports when a check starts failing and when it recovers, not on every run.");
  await shot(page, "monitor");

  await go(page, `${ADMIN}#/cleaning`, ".herald-admin form");
  await say(
    page,
    "Cleaned fields turn messy values, such as payment sources, into a few clear labels.",
    page.locator(".herald-cleaned-field").first(),
  );
  await say(page, "Digests and searches can then group by the cleaned value.");
  await shot(page, "cleaning");

  await go(page, `${ADMIN}#/hosts`, "#herald-hosts");
  await say(page, "Webhooks may only reach the hosts listed here.", page.locator("#herald-hosts"));
  await shot(page, "hosts");

  await go(
    page,
    "/civicrm/search#/display/Herald_Parked_Alerts/Herald_Parked_Alerts_Table",
    "table tbody tr",
  );
  await say(page, "When a channel keeps failing, herald parks the alert instead of dropping it.");
  await say(
    page,
    "Parked alerts wait here, with the last error, until you send them again.",
    page.locator("table tbody tr").first(),
  );
  await shot(page, "parked");

  await go(page, "/civicrm/admin/setting/herald?reset=1", "form");
  await say(
    page,
    "Single values, such as the topic the monitor reports to, live on the settings form.",
  );
  await shot(page, "settings");
  await card(page, "civico-de.github.io/herald");
});

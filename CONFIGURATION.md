# herald — configuration recipes

Three end-to-end recipes for typical setups. Each one is admin-configurable in CiviCRM (`Administer → CiviRules`) — no extension code changes needed.

Each action ships with a parameter form (see `CRM/Herald/CivirulesAdapter/Form/`), so admins fill in fields in the CiviRules UI rather than editing JSON. The JSON snippets below show the equivalent stored-parameter shape — handy as a reference, for `cv` scripting, or for diffing rule definitions across environments.

---

## Recipe 1 — migration (replaces `opscli telegram morning` + `opscli telegram monitor`)

This is the configuration that takes over from the two cron jobs on production (`/root/ops telegram morning` at 08:00 and `/root/ops telegram monitor` every 10 min).

### Settings (one-off)

```bash
cv setting:set herald_telegram_bot_token=<bot-token>
cv setting:set herald_telegram_default_chat_id=-5137408650
```

### Rule 1 — Daily digest at 08:00

| Field | Value |
|---|---|
| Trigger | `Cron daily 08:00` (built-in CiviRules scheduled trigger) |
| Conditions | none |
| Action | `Herald: Send contribution digest` |

Action params:
```json
{
  "channel": "telegram",
  "template": "herald_contribution_digest_daily",
  "period": "yesterday",
  "campaign_id": 2,
  "financial_type_id": 1,
  "show_pending": true,
  "show_histogram": true,
  "goal": 30000,
  "campaign_title": "Campaign 2026"
}
```

### Rule 2 — Realtime alert: completed contribution (excluding bank-match noise)

| Field | Value |
|---|---|
| Trigger | `New entity is created — Contribution` |
| Conditions | `Contribution status = Completed` AND `Contribution source NOT LIKE %Bank TX%` |
| Action | `Herald: Send contribution alert` |

Params:
```json
{
  "channel": "telegram",
  "template": "herald_contribution_alert_completed",
  "campaign_id": 2,
  "goal": 30000,
  "campaign_title": "Campaign 2026"
}
```

### Rule 3 — Realtime alert: pending bank transfer announced

| Field | Value |
|---|---|
| Trigger | `New entity is created — Contribution` |
| Conditions | `Contribution status = Pending` AND `Financial type = Spende` |
| Action | `Herald: Send contribution alert` |

> **Financial-type condition is not optional.** Without it, EVERY pending
> contribution fires the "Spende angekündigt" template — including pending
> ticket/event orders created by the cart (their `source` is a cart UUID).
> Tickets belong to Rule 5 (`herald_contribution_alert_ticket`) instead.

Params:
```json
{
  "channel": "telegram",
  "template": "herald_contribution_alert_pending",
  "campaign_id": 2
}
```

### Rule 5 — Realtime alert: ticket sold (aggregated)

| Field | Value |
|---|---|
| Trigger | `New entity is created — Contribution` |
| Conditions | `Financial type = <ticket financial type>` AND `Contribution status = Completed` |
| Action | `Herald: Send contribution alert` |

Params:
```json
{
  "channel": "telegram",
  "template": "herald_contribution_alert_ticket",
  "aggregate_minutes": 5
}
```

With `aggregate_minutes` set, a sales burst (e.g. right after a program
announcement) produces ONE Telegram message per 5-minute window —
"📬 7 Meldungen, gesamt 385,00 €" plus the individual lines — instead of
seven pings. See "Alert aggregation" below.

### Rule 4 — Realtime alert: pending → completed (bank match)

| Field | Value |
|---|---|
| Trigger | `Entity is changed — Contribution` |
| Conditions | `Herald: Previous Contribution status` (set `previous_status_id` to the Pending value, usually `2`) AND `Contribution status = Completed` AND `Contribution source LIKE %Bank TX%` |
| Action | `Herald: Send contribution alert` |

> **Note on delayed rule execution:** the previous-status value is captured in-request via `hook_civicrm_pre`. When a rule runs delayed (CiviRules queue/cron), the condition falls back to the original entity data CiviRules serialises into the queue — both paths cover Pending → Completed detection.

Params:
```json
{
  "channel": "telegram",
  "template": "herald_contribution_status_changed",
  "campaign_id": 2,
  "goal": 30000
}
```

> **Dedup** — the "Bank TX" match always fires Rule 4. Rule 2's `NOT LIKE %Bank TX%` condition is what keeps these out of the regular completed alert. Together they replicate the dedup logic from `opscli/lib/telegram/donation-monitor.ts`.

> **Previous-status condition** — `Herald: Previous Contribution status` is registered by this extension (see `civirules_conditions.json`). It reads the pre-edit snapshot captured in `hook_civicrm_pre`, so it is reliable independent of CiviRules core's optional change-tracking. Look up the numeric ID for "Pending" via `cv api4 OptionValue.get +w 'option_group_id:name=contribution_status' +s 'value,name'` (often `2`).

---

## Recipe 2 — Generic NGO without bank-transfer matching

Smaller setup with just stripe + occasional offline donations. No source-pattern logic needed.

### Rule 1 — Daily digest

```json
{
  "channel": "telegram",
  "template": "herald_contribution_digest_daily",
  "period": "yesterday",
  "show_pending": false,
  "show_histogram": true
}
```

### Rule 2 — Realtime: any completed contribution

Trigger: `New entity is created — Contribution`. Condition: `Contribution status = Completed`.

```json
{
  "channel": "telegram",
  "template": "herald_contribution_alert_completed"
}
```

---

## Recipe 3 — Major-donor campaign with custom histogram buckets

When automatic bucketing doesn't match the campaign's narrative (e.g. you want the breakpoints €100, €1k, €10k, €100k explicitly):

```json
{
  "channel": "telegram",
  "template": "herald_contribution_digest_daily",
  "period": "last_7_days",
  "campaign_id": 5,
  "bucket_edges": [0, 100, 1000, 10000, 100000, 1000000],
  "goal": 500000,
  "campaign_title": "Major Donor Campaign 2026"
}
```

---

## Recipe 4 — Event lifecycle notifications (created / deactivated / deleted)

Notify an ops channel when the event catalogue changes — useful when events are
maintained by a sync (e.g. pretalx → CiviCRM) rather than by hand, so a new or
vanished event would otherwise pass unnoticed. The generic `Send notification`
action exposes `$event` (`id`, `title`, `start_date`, `is_active`) for the
bare-Event triggers, and three ready-made templates ship with the extension.

| Rule | Trigger | Condition | Template |
|------|---------|-----------|----------|
| New event live | `Event is added` | — | `herald_event_created_alert` |
| Event deactivated | `Event is changed` | `Herald: Event was deactivated (is_active 1 → 0)` | `herald_event_deactivated_alert` |
| Event deleted | `Event is deleted` | — | `herald_event_deleted_alert` |

Action params for each (same shape as the other realtime alerts):

```json
{ "channel": "telegram", "template": "herald_event_created_alert", "chat_id": "-100…", "aggregate_minutes": 5 }
```

> **On the "deactivated" rule** — a sync typically unpublishes (sets
> `is_active = 0`) rather than hard-deleting, so this is the rule that fires on
> the common "event removed from booking" case. Do **not** use a plain
> `Field Value Comparison` (`is_active = 0`) for it: a partial edit that omits
> is_active reads it as NULL, and `null == 0` matches → false alarms on every
> unrelated edit. The shipped `herald_event_became_inactive` condition instead
> reads the pre-edit value from a hook_civicrm_pre snapshot and the current
> value from the live row, firing only on a genuine 1 → 0 transition. Reserve
> `Event is deleted` for genuine DB removals.

An instance-specific seeder that creates all three via SQL lives in
`an instance-specific script` (idempotent; edit `CHAT_ID` for another
instance) — run it with `cv scr`.

---

## Subscriber-mode (per-contact preferences)

For setups where multiple users want different notifications routed to *their own* channels, herald ships a per-contact preferences custom group (`herald_preferences`) plus a subscriber-fanout delivery mode on every action.

### One-off setup

```bash
cv ext:enable herald   # creates the option group + custom group + 6 fields
cv scr /var/www/html/ext/herald/bin/install-profile.php
# → prints the self-service URL: /civicrm/profile/edit?gid=<N>&reset=1
```

Send users that URL. The profile asks them to pick:

- **Preferred channel** — Telegram, Webhook, ntfy, Pushover, or Email
- **Recipient ID for that channel** — chat ID, webhook URL, ntfy topic, Pushover user key. Email needs no identifier: it goes to the contact's primary address (contacts with *Do not email* set are skipped)
- **Subscriptions** — checkboxes for "completed alert", "pending alert", "status changed alert", "daily digest", "weekly digest"

CiviCRM auto-resolves the profile to the logged-in contact (`access CiviCRM` permission + own-record only), so each user only sees and edits their own preferences.

### Rule example: "completed contributions, fanned out to subscribers"

| Field | Value |
|---|---|
| Trigger | `New entity is created — Contribution` |
| Conditions | `Contribution status = Completed` |
| Action | `Herald: Send contribution alert` |

In the action form:

- **Delivery mode** → `Subscribers — fan out to all contacts opted into a subscription type`
- **Subscription type** → `Every completed contribution`
- (Channel/chat_id/etc. are ignored in subscriber mode — each subscriber's own preferred channel + recipient ID is used. Only presentation options like `parse_mode`, `ntfy_title`, `pushover_sound` or `email_subject` are forwarded from the rule; recipient and credential keys — `chat_id`, `webhook_url`, `webhook_auth_token`, `webhook_headers`, `ntfy_topic`, `pushover_user_key`, `email_to`, `email_cc`, `email_bcc` — never are.)

When the rule fires, the action queries all contacts whose `subscriptions` field contains `completed_alert` and dispatches the rendered template through each one's preferred channel. A bad chat ID for one contact does not block the others — failures are logged via `Civi::log()->warning()` and the loop continues.

### Adding a custom subscription type

The vocabulary lives in option group `herald_subscription_type`. Add new entries via the CiviCRM UI (*Administer → Customize Data and Screens → Option Groups*) or via `cv api4 OptionValue.create`. Reference the new value's `name` in the action's Subscription-type dropdown.

---

## Channels

`channel` is a per-action parameter. Each registered channel reads its own keys from action params and from the optional `channel_config` JSON textarea on the form. Below: which fields each channel uses, plus minimal recipes.

### Telegram (`channel: "telegram"`)

Top-level fields (already in the form): `chat_id`, `parse_mode` (`HTML`/`MarkdownV2`/empty), `disable_notification`, `disable_web_page_preview`. Falls back to `herald_telegram_default_chat_id` setting if `chat_id` is empty.

### Webhook (`channel: "webhook"`)

Generic POST. Covers Slack/Discord/Mattermost incoming webhooks plus any custom endpoint.

```json
{
  "channel": "webhook",
  "template": "herald_contribution_alert_completed",
  "channel_config": {
    "webhook_url": "https://hooks.slack.com/services/T0/B0/secret",
    "webhook_body_format": "slack"
  }
}
```

| Key | Type | Meaning |
|---|---|---|
| `webhook_url` | string | Endpoint (required; falls back to `herald_webhook_default_url`) |
| `webhook_method` | enum | `POST` (default) or `PUT` |
| `webhook_body_format` | enum | `text` (raw body, default) / `json` / `slack` (`{"text":…}`) / `discord` (`{"content":…}`) |
| `webhook_body_key` | string | Custom JSON key when `body_format=json` (default `text`) |
| `webhook_headers` | object | Extra request headers |
| `webhook_auth_token` | string | Sent as `Authorization: Bearer …` if no Authorization header is in `webhook_headers`. The `herald_webhook_default_auth_token` setting is only used when the URL also comes from `herald_webhook_default_url` — the default token is paired with the default endpoint and is never attached to a caller-supplied URL (e.g. a subscriber's personal webhook). |

### ntfy.sh (`channel: "ntfy"`)

Free, simple, self-hostable. Topic == push channel; metadata goes in `X-*` headers.

```json
{
  "channel": "ntfy",
  "template": "herald_contribution_alert_completed",
  "channel_config": {
    "ntfy_topic": "donations",
    "ntfy_title": "New donation",
    "ntfy_priority": 4,
    "ntfy_tags": "tada,heart",
    "ntfy_markdown": true
  }
}
```

| Key | Type | Meaning |
|---|---|---|
| `ntfy_topic` | string | Topic to publish to (required; falls back to `herald_ntfy_default_topic`) |
| `ntfy_title` | string | `X-Title` header |
| `ntfy_priority` | int | `X-Priority` (1=min … 5=max) |
| `ntfy_tags` | string | `X-Tags` (comma-separated; emoji tags supported) |
| `ntfy_click` | string | URL opened when the notification is tapped |
| `ntfy_markdown` | bool | Render the body as Markdown (`X-Markdown: yes`) |

Server URL comes from `herald_ntfy_server` (default `https://ntfy.sh`). Bearer auth via `herald_ntfy_token`.

### Pushover (`channel: "pushover"`)

Paid mobile-push service. Requires an app token (one-off per install) and a user/group key (per recipient).

```json
{
  "channel": "pushover",
  "template": "herald_contribution_alert_completed",
  "channel_config": {
    "pushover_title": "New donation",
    "pushover_priority": 1,
    "pushover_sound": "cashregister"
  }
}
```

| Key | Type | Meaning |
|---|---|---|
| `pushover_user_key` | string | Override the default user/group key |
| `pushover_title` | string | Notification title |
| `pushover_priority` | int | -2 (silent) … 2 (emergency; `retry`/`expire` are added automatically, see below) |
| `pushover_retry` | int | Emergency only: seconds between re-alerts (min 30, default 60) |
| `pushover_expire` | int | Emergency only: seconds until re-alerting stops (max 10800, default 3600) |
| `pushover_sound` | string | Sound name (see [Pushover sounds](https://pushover.net/api#sounds)) |
| `pushover_url` | string | Supplementary URL shown under the body |
| `pushover_url_title` | string | Label for `pushover_url` |
| `pushover_html` | bool | Body is Pushover-flavoured HTML |

App token comes from `herald_pushover_app_token` (required). User key falls back to `herald_pushover_default_user_key`.

### Email (`channel: "email"`)

Delivers through CiviCRM's configured outbound mailer — SMTP settings, mail logging and dev redirection all apply. No extra credentials needed.

```json
{
  "channel": "email",
  "template": "herald_contribution_alert_completed",
  "channel_config": {
    "email_to": "alerts@example.org",
    "email_subject": "New donation"
  }
}
```

| Key | Type | Meaning |
|---|---|---|
| `email_to` | string | Recipient address (falls back to `herald_email_default_to`) |
| `email_to_name` | string | Recipient display name |
| `email_subject` | string | Explicit subject. When unset, the template's `msg_subject` (Smarty-rendered with the same vars as the body) is used; final fallback "Notification" |
| `email_format` | string | `html` (default) or `text` — which MIME part the rendered body becomes |
| `email_cc` | string | Comma-separated CC addresses |
| `email_bcc` | string | Comma-separated BCC addresses |

The From header comes from `herald_email_from` (`"Name" <email>`); when empty, the site default from address is used.

---

## Alert aggregation (anti-spam)

Any send action can set `aggregate_minutes` (form field or param, default
`0` = send immediately). With a value > 0 the rendered alert is NOT sent
right away; it is parked in `civicrm_herald_buffer`. The scheduled job
**"Herald: Flush alert aggregation buffer"** (`Job.herald_buffer_flush`,
`run_frequency: Always`, installed managed) drains the buffer on every cron
run: once a rule's OLDEST buffered alert is older than the window, everything
that rule collected so far goes out as ONE message.

- One buffered alert → sent unchanged (aggregation only delayed it).
- Several → summary header with count and (for contribution alerts) the
  summed amount — `📬 3 Meldungen, gesamt 295,00 € (gesammelt über 15 min)` —
  followed by the individual alert bodies.
- Grouping is per rule action: two rules (or the same rule with changed
  params) never mix into one message.
- Delivery failures keep the rows and retry on the next cron run.
- Maximum latency = `aggregate_minutes` + cron interval. Pick the window per
  rule: e.g. `5` for ticket sales, `60` for pending donations.

---

## Topics — recipient-agnostic publishing (`Herald.publish`)

Other extensions and scripts publish to a TOPIC; the `herald_topics` setting
decides where each topic lands. Publishers never carry chat IDs.

```bash
cv api4 Setting.set +v 'herald_topics={
  "default": {"channel": "telegram"},
  "ops":     {"channel": "telegram", "chat_id": "-100…", "parse_mode": "HTML"},
  "revenue": {"channel": "telegram", "chat_id": "-100…", "aggregate_minutes": 5}
}'

cv api4 Herald.publish +v 'topic=ops' +v 'message="🔴 disk almost full"'
```

Resolution = topic entry merged over the `default` entry; an unrouted topic
(no entry, no default) throws. A route with `aggregate_minutes > 0` sends
through the same aggregation buffer as the CiviRules alerts. Instead of
`message`, pass `template` (workflow_name) + `vars` to render a
MessageTemplate.

---

## Extra digest sections (`hook_civicrm_heraldDigestSections`)

A composed digest (`Job.herald_contribution_digest` with `blocks`) can only
report what a contribution query answers. Numbers from another entity — booked
event seats, membership counts, queue depth — belong to the extension that owns
them. The `sections` job parameter names the keys the digest wants; herald asks
around and appends the answers after the blocks, in the order listed.

```php
// In any extension's main .php file:
function myext_civicrm_heraldDigestSections(array $keys, array $context, array &$sections): void {
  if (in_array('myext.seats', $keys, TRUE)) {
    $sections['myext.seats'] = "🪑 <b>Seats</b>\n138 booked";
  }
}
```

Job parameter: `sections=myext.seats,myext.queue` (or a JSON array).
`$context` carries the job params under `params` and the resolved `period`, so
a listener can match the digest's timeframe. Unrequested keys and empty bodies
are dropped, so a listener may answer unconditionally. Multi-block mode only —
single-block digests render one template and have no section concept.

---

## Check monitor — push alerts for degraded states

Any extension contributes health checks; herald runs them on every cron and
publishes ONLY transitions (ok→fail, fail→ok) to the `herald_monitor_topic`
topic (default `ops`) using the `herald_monitor_alert` template. Steady
states never alert; a new check alerts only if it is already failing.

```php
// In any extension's main .php file:
function myext_civicrm_heraldChecks(array &$checks): void {
  $checks['myext.queue'] = fn() => ['status' => 'ok']; // or 'fail' + detail, or 'skipped'
}
```

The **pulse** extension (≥ 0.2) contributes `pulse.database`, `pulse.cron`
and `pulse.system` automatically when both extensions are installed.

### Tuning (all optional, defaults reproduce the plain one-shot behaviour)

| Setting | Default | Effect |
| --- | --- | --- |
| `herald_monitor_open_after` | `1` | Require N consecutive failing runs before a fail is reported (rides out flapping). |
| `herald_monitor_close_after` | `1` | Require M consecutive ok runs before a recovery is reported. |
| `herald_monitor_renotify_hours` | `24` | Re-announce a check stuck failing this long (⏰ "still failing"). `0` disables reminders. |
| `herald_monitor_snooze` | `[]` | Map `check-name → ISO datetime`; the check stays silent (no fail, no renotify) until then. A fail→ok recovery still fires afterwards if a failure was announced before the snooze. Expired entries are pruned each run. |
| `herald_monitor_expected` | `[]` | List of check names that must always be present; a listed check missing from the hook is reported as failing ("check missing — extension disabled?"). Empty keeps the default of silently dropping vanished checks. |

Concurrency and durability: each run takes the `worker.herald.monitor` lock
(overlapping crons skip rather than double-send), and the per-check state is
persisted incrementally right after each alert is published, so a crash or a
failing send mid-batch never re-sends alerts that already went out.

Division of labour: this monitor runs INSIDE the instance and cannot report
a hard-down site. Keep an external poller (Uptime Kuma etc.) on pulse's
`/civicrm/pulse` endpoint for dead-man coverage; the monitor covers
everything short of that.

---

## Available action parameters reference

### `Send contribution digest`

| Param | Type | Default | Meaning |
|---|---|---|---|
| `channel` | string | `telegram` | Channel key |
| `template` | string | required | MessageTemplate workflow_name |
| `chat_id` | string | (default setting) | Recipient |
| `parse_mode` | string | `HTML` | Telegram parse mode |
| `period` | string | `yesterday` | `yesterday` / `today` / `last_7_days` / `last_30_days` |
| `campaign_id` | int | none | Filters all queries |
| `financial_type_id` | int | none | Filters all queries |
| `source_pattern` | string | none | LIKE pattern for source field |
| `show_pending` | bool | `true` | Include pending-pool block |
| `show_histogram` | bool | `true` | Include histogram block |
| `bucket_count` | int | `8` | Target histogram bucket count |
| `bucket_edges` | array | (adaptive) | Override edges, e.g. `[0,10,25,100,1000]` |
| `goal` | float | none | Renders campaign progress when set |
| `campaign_title` | string | none | Free-text title for campaign block |
| `sections` | string | none | Extra section keys from other extensions (see "Extra digest sections"), multi-block mode only |

### `Send contribution alert`

| Param | Type | Default | Meaning |
|---|---|---|---|
| `channel`, `template`, `chat_id`, `parse_mode` | — | — | (same as digest) |
| `campaign_id` | int | none | Computes campaign progress |
| `goal` | float | none | Required alongside campaign_id for percent |
| `aggregate_minutes` | int | `0` | > 0: buffer alerts and send one combined message per window (see "Alert aggregation") |
| `campaign_title` | string | none | Free-text title |
| `include_status_transition` | bool | `true` | Pass `from_status` / `to_status` to template |

### `Send notification` (generic)

| Param | Type | Meaning |
|---|---|---|
| `channel`, `template`, `chat_id`, `parse_mode` | — | (same) |
| `vars` | JSON | Variable bag passed verbatim to the template |

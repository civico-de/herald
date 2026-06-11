# civico_notify — configuration recipes

Three end-to-end recipes for typical setups. Each one is admin-configurable in CiviCRM (`Administer → CiviRules`) — no extension code changes needed.

Each action ships with a parameter form (see `CRM/CivicoNotify/Form/CivirulesAction/`), so admins fill in fields in the CiviRules UI rather than editing JSON. The JSON snippets below show the equivalent stored-parameter shape — handy as a reference, for `cv` scripting, or for diffing rule definitions across environments.

---

## Recipe 1 — migration (replaces `opscli telegram morning` + `opscli telegram monitor`)

This is the configuration that takes over from the two cron jobs on production (`/root/ops telegram morning` at 08:00 and `/root/ops telegram monitor` every 10 min).

### Settings (one-off)

```bash
cv setting:set civico_notify_telegram_bot_token=<bot-token>
cv setting:set civico_notify_telegram_default_chat_id=-5137408650
```

### Rule 1 — Daily digest at 08:00

| Field | Value |
|---|---|
| Trigger | `Cron daily 08:00` (built-in CiviRules scheduled trigger) |
| Conditions | none |
| Action | `CiviCo Notify: Send contribution digest` |

Action params:
```json
{
  "channel": "telegram",
  "template": "civico_notify_contribution_digest_daily",
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
| Action | `CiviCo Notify: Send contribution alert` |

Params:
```json
{
  "channel": "telegram",
  "template": "civico_notify_contribution_alert_completed",
  "campaign_id": 2,
  "goal": 30000,
  "campaign_title": "Campaign 2026"
}
```

### Rule 3 — Realtime alert: pending bank transfer announced

| Field | Value |
|---|---|
| Trigger | `New entity is created — Contribution` |
| Conditions | `Contribution status = Pending` |
| Action | `CiviCo Notify: Send contribution alert` |

Params:
```json
{
  "channel": "telegram",
  "template": "civico_notify_contribution_alert_pending",
  "campaign_id": 2
}
```

### Rule 4 — Realtime alert: pending → completed (bank match)

| Field | Value |
|---|---|
| Trigger | `Entity is changed — Contribution` |
| Conditions | `CiviCo Notify: Previous Contribution status` (set `previous_status_id` to the Pending value, usually `2`) AND `Contribution status = Completed` AND `Contribution source LIKE %Bank TX%` |
| Action | `CiviCo Notify: Send contribution alert` |

Params:
```json
{
  "channel": "telegram",
  "template": "civico_notify_contribution_status_changed",
  "campaign_id": 2,
  "goal": 30000
}
```

> **Dedup** — the "Bank TX" match always fires Rule 4. Rule 2's `NOT LIKE %Bank TX%` condition is what keeps these out of the regular completed alert. Together they replicate the dedup logic from `opscli/lib/telegram/donation-monitor.ts`.

> **Previous-status condition** — `CiviCo Notify: Previous Contribution status` is registered by this extension (see `civirules_conditions.json`). It reads the pre-edit snapshot captured in `hook_civicrm_pre`, so it is reliable independent of CiviRules core's optional change-tracking. Look up the numeric ID for "Pending" via `cv api4 OptionValue.get +w 'option_group_id:name=contribution_status' +s 'value,name'` (often `2`).

---

## Recipe 2 — Generic NGO without bank-transfer matching

Smaller setup with just stripe + occasional offline donations. No source-pattern logic needed.

### Rule 1 — Daily digest

```json
{
  "channel": "telegram",
  "template": "civico_notify_contribution_digest_daily",
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
  "template": "civico_notify_contribution_alert_completed"
}
```

---

## Recipe 3 — Major-donor campaign with custom histogram buckets

When automatic bucketing doesn't match the campaign's narrative (e.g. you want the breakpoints €100, €1k, €10k, €100k explicitly):

```json
{
  "channel": "telegram",
  "template": "civico_notify_contribution_digest_daily",
  "period": "last_7_days",
  "campaign_id": 5,
  "bucket_edges": [0, 100, 1000, 10000, 100000, 1000000],
  "goal": 500000,
  "campaign_title": "Major Donor Campaign 2026"
}
```

---

## Subscriber-mode (per-contact preferences)

For setups where multiple users want different notifications routed to *their own* channels, civico_notify ships a per-contact preferences custom group (`civico_notify_preferences`) plus a subscriber-fanout delivery mode on every action.

### One-off setup

```bash
cv ext:enable civico_notify   # creates the option group + custom group + 6 fields
cv scr /var/www/html/ext/civico_notify/bin/install-profile.php
# → prints the self-service URL: /civicrm/profile/edit?gid=<N>&reset=1
```

Send users that URL. The profile asks them to pick:

- **Preferred channel** — Telegram, Webhook, ntfy, or Pushover
- **Recipient ID for that channel** — chat ID, webhook URL, ntfy topic, Pushover user key
- **Subscriptions** — checkboxes for "completed alert", "pending alert", "status changed alert", "daily digest", "weekly digest"

CiviCRM auto-resolves the profile to the logged-in contact (`access CiviCRM` permission + own-record only), so each user only sees and edits their own preferences.

### Rule example: "completed contributions, fanned out to subscribers"

| Field | Value |
|---|---|
| Trigger | `New entity is created — Contribution` |
| Conditions | `Contribution status = Completed` |
| Action | `CiviCo Notify: Send contribution alert` |

In the action form:

- **Delivery mode** → `Subscribers — fan out to all contacts opted into a subscription type`
- **Subscription type** → `Every completed contribution`
- (Channel/chat_id/etc. are ignored in subscriber mode — each subscriber's own preferred channel + recipient ID is used)

When the rule fires, the action queries all contacts whose `subscriptions` field contains `completed_alert` and dispatches the rendered template through each one's preferred channel. A bad chat ID for one contact does not block the others — failures are logged via `Civi::log()->warning()` and the loop continues.

### Adding a custom subscription type

The vocabulary lives in option group `civico_notify_subscription_type`. Add new entries via the CiviCRM UI (*Administer → Customize Data and Screens → Option Groups*) or via `cv api4 OptionValue.create`. Reference the new value's `name` in the action's Subscription-type dropdown.

---

## Channels

`channel` is a per-action parameter. Each registered channel reads its own keys from action params and from the optional `channel_config` JSON textarea on the form. Below: which fields each channel uses, plus minimal recipes.

### Telegram (`channel: "telegram"`)

Top-level fields (already in the form): `chat_id`, `parse_mode` (`HTML`/`MarkdownV2`/empty), `disable_notification`, `disable_web_page_preview`. Falls back to `civico_notify_telegram_default_chat_id` setting if `chat_id` is empty.

### Webhook (`channel: "webhook"`)

Generic POST. Covers Slack/Discord/Mattermost incoming webhooks plus any custom endpoint.

```json
{
  "channel": "webhook",
  "template": "civico_notify_contribution_alert_completed",
  "channel_config": {
    "webhook_url": "https://hooks.slack.com/services/T0/B0/secret",
    "webhook_body_format": "slack"
  }
}
```

| Key | Type | Meaning |
|---|---|---|
| `webhook_url` | string | Endpoint (required; falls back to `civico_notify_webhook_default_url`) |
| `webhook_method` | enum | `POST` (default) or `PUT` |
| `webhook_body_format` | enum | `text` (raw body, default) / `json` / `slack` (`{"text":…}`) / `discord` (`{"content":…}`) |
| `webhook_body_key` | string | Custom JSON key when `body_format=json` (default `text`) |
| `webhook_headers` | object | Extra request headers |
| `webhook_auth_token` | string | Sent as `Authorization: Bearer …` if no Authorization header is in `webhook_headers`. Falls back to `civico_notify_webhook_default_auth_token` setting. |

### ntfy.sh (`channel: "ntfy"`)

Free, simple, self-hostable. Topic == push channel; metadata goes in `X-*` headers.

```json
{
  "channel": "ntfy",
  "template": "civico_notify_contribution_alert_completed",
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
| `ntfy_topic` | string | Topic to publish to (required; falls back to `civico_notify_ntfy_default_topic`) |
| `ntfy_title` | string | `X-Title` header |
| `ntfy_priority` | int | `X-Priority` (1=min … 5=max) |
| `ntfy_tags` | string | `X-Tags` (comma-separated; emoji tags supported) |
| `ntfy_click` | string | URL opened when the notification is tapped |
| `ntfy_markdown` | bool | Render the body as Markdown (`X-Markdown: yes`) |

Server URL comes from `civico_notify_ntfy_server` (default `https://ntfy.sh`). Bearer auth via `civico_notify_ntfy_token`.

### Pushover (`channel: "pushover"`)

Paid mobile-push service. Requires an app token (one-off per install) and a user/group key (per recipient).

```json
{
  "channel": "pushover",
  "template": "civico_notify_contribution_alert_completed",
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
| `pushover_priority` | int | -2 (silent) … 2 (emergency, requires retry/expire) |
| `pushover_sound` | string | Sound name (see [Pushover sounds](https://pushover.net/api#sounds)) |
| `pushover_url` | string | Supplementary URL shown under the body |
| `pushover_url_title` | string | Label for `pushover_url` |
| `pushover_html` | bool | Body is Pushover-flavoured HTML |

App token comes from `civico_notify_pushover_app_token` (required). User key falls back to `civico_notify_pushover_default_user_key`.

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

### `Send contribution alert`

| Param | Type | Default | Meaning |
|---|---|---|---|
| `channel`, `template`, `chat_id`, `parse_mode` | — | — | (same as digest) |
| `campaign_id` | int | none | Computes campaign progress |
| `goal` | float | none | Required alongside campaign_id for percent |
| `campaign_title` | string | none | Free-text title |
| `include_status_transition` | bool | `true` | Pass `from_status` / `to_status` to template |

### `Send notification` (generic)

| Param | Type | Meaning |
|---|---|---|
| `channel`, `template`, `chat_id`, `parse_mode` | — | (same) |
| `vars` | JSON | Variable bag passed verbatim to the template |

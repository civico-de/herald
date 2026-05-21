# civico_notify — configuration recipes

Three end-to-end recipes for typical setups. Each one is admin-configurable in CiviCRM (`Administer → CiviRules`) — no extension code changes needed.

The action parameter examples below are JSON because v0.1 ships without bespoke form classes; CiviRules' generic JSON editor accepts them as-is. (Form classes will land later under `CRM/CivicoNotify/Form/CivirulesAction/`.)

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

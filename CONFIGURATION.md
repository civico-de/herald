# herald: configuration recipes and reference

End-to-end recipes for typical setups, followed by the reference tables. Everything here is
admin-configurable in CiviCRM under Administer, then CiviRules. No extension code changes
are needed.

Each action ships with a parameter form (see `CRM/Herald/CivirulesAdapter/Form/`), so
admins fill in fields in the CiviRules UI rather than editing JSON. The JSON snippets below
show the equivalent stored-parameter shape. They are handy as a reference, for `cv`
scripting, and for diffing rule definitions across environments.

## Recipe 1: replacing a bespoke Telegram cron

A typical starting point is a hand-written script that posts a morning summary and polls
for new donations every few minutes. This recipe replaces both with a digest rule and a set
of realtime rules that deduplicate against a bank-matching source pattern.

Settings, once:

```bash
cv setting:set herald_telegram_bot_token=<bot-token>
cv setting:set herald_telegram_default_chat_id=<chat-id>
```

### Rule 1: daily digest at 08:00

| Field | Value |
|---|---|
| Trigger | `Cron daily 08:00` (built-in CiviRules scheduled trigger) |
| Conditions | none |
| Action | `Herald: Send contribution digest` |

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

### Rule 2: realtime alert for a completed contribution, excluding bank-match noise

| Field | Value |
|---|---|
| Trigger | `New entity is created — Contribution` |
| Conditions | `Contribution status = Completed` AND `Contribution source NOT LIKE %Bank TX%` |
| Action | `Herald: Send contribution alert` |

```json
{
  "channel": "telegram",
  "template": "herald_contribution_alert_completed",
  "campaign_id": 2,
  "goal": 30000,
  "campaign_title": "Campaign 2026"
}
```

### Rule 3: realtime alert for a pending bank transfer

| Field | Value |
|---|---|
| Trigger | `New entity is created — Contribution` |
| Conditions | `Contribution status = Pending` AND `Financial type = Donation` |
| Action | `Herald: Send contribution alert` |

The financial-type condition is not optional. Without it, every pending contribution fires
the "donation announced" template, including pending ticket or event orders created by a
cart, whose `source` is a cart UUID. Tickets belong to rule 5 instead.

```json
{
  "channel": "telegram",
  "template": "herald_contribution_alert_pending",
  "campaign_id": 2
}
```

### Rule 4: realtime alert for pending → completed after a bank match

| Field | Value |
|---|---|
| Trigger | `Entity is changed — Contribution` |
| Conditions | `Herald: Previous Contribution status` with `previous_status_id` set to the Pending value (usually `2`) AND `Contribution status = Completed` AND `Contribution source LIKE %Bank TX%` |
| Action | `Herald: Send contribution alert` |

```json
{
  "channel": "telegram",
  "template": "herald_contribution_status_changed",
  "campaign_id": 2,
  "goal": 30000
}
```

The previous-status value is captured in-request via `hook_civicrm_pre`. When a rule runs
delayed through the CiviRules queue or cron, the condition falls back to the original
entity data CiviRules serialises into the queue. Both paths detect Pending → Completed.

Deduplication: the bank-match source pattern always fires rule 4. Rule 2's `NOT LIKE`
condition is what keeps those out of the regular completed alert. Look up the numeric ID
for "Pending" with
`cv api4 OptionValue.get +w 'option_group_id:name=contribution_status' +s 'value,name'`.

### Rule 5: ticket sold, aggregated

| Field | Value |
|---|---|
| Trigger | `New entity is created — Contribution` |
| Conditions | `Financial type = <ticket financial type>` AND `Contribution status = Completed` |
| Action | `Herald: Send contribution alert` |

```json
{
  "channel": "telegram",
  "template": "herald_contribution_alert_ticket",
  "aggregate_minutes": 5
}
```

With `aggregate_minutes` set, a sales burst right after a programme announcement produces
one Telegram message per five-minute window, a header such as "7 alerts, 385.00 € in
total" plus the individual lines, instead of seven pings. See "Alert aggregation" below.

## Recipe 2: a small organisation without bank-transfer matching

Stripe plus occasional offline donations. No source-pattern logic is needed.

Daily digest:

```json
{
  "channel": "telegram",
  "template": "herald_contribution_digest_daily",
  "period": "yesterday",
  "show_pending": false,
  "show_histogram": true
}
```

Realtime alert for any completed contribution. Trigger `New entity is created —
Contribution`, condition `Contribution status = Completed`:

```json
{
  "channel": "telegram",
  "template": "herald_contribution_alert_completed"
}
```

## Recipe 3: major-donor campaign with custom histogram buckets

When automatic bucketing does not match the campaign's narrative, for example when you
want the breakpoints €100, €1k, €10k and €100k explicitly:

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

## Recipe 4: event lifecycle notifications

Notify an ops channel when the event catalogue changes. This is useful when events are
maintained by a sync, from pretalx for example, rather than by hand, so a new or vanished
event would otherwise pass unnoticed. The generic `Send notification` action exposes
`$event` (`id`, `title`, `start_date`, `is_active`) for the bare-Event triggers, and three
ready-made templates ship with the extension.

| Rule | Trigger | Condition | Template |
|------|---------|-----------|----------|
| New event live | `Event is added` | none | `herald_event_created_alert` |
| Event deactivated | `Event is changed` | `Herald: Event was deactivated (is_active 1 → 0)` | `herald_event_deactivated_alert` |
| Event deleted | `Event is deleted` | none | `herald_event_deleted_alert` |
| Registration cancelled | `Participant is changed` | `Participant status changed`: from Registered / Pending from pay later to Cancelled | `herald_event_cancellation_alert` |

The cancellation rule reports self-service and CRM cancellations once each; restricting the
previous status keeps abandoned checkouts (incomplete transaction → Cancelled) silent. The
participant triggers expose `$participant.role` and `$participant.status` (labels).

Action params for each, in the same shape as the other realtime alerts:

```json
{ "channel": "telegram", "template": "herald_event_created_alert", "chat_id": "-100…", "aggregate_minutes": 5 }
```

A sync typically unpublishes by setting `is_active = 0` rather than hard-deleting, so the
"deactivated" rule is the one that fires on the common "event removed from booking" case.
Do not use a plain `Field Value Comparison` on `is_active = 0` for it. A partial edit that
omits `is_active` reads it as NULL, and `null == 0` matches, which gives false alarms on
every unrelated edit. The shipped `herald_event_became_inactive` condition instead reads
the pre-edit value from a `hook_civicrm_pre` snapshot and the current value from the live
row, and fires only on a genuine 1 → 0 transition. Reserve `Event is deleted` for genuine
database removals.

## Subscriber mode: per-contact preferences

For setups where several users want different notifications routed to their own channels,
herald ships a per-contact preferences custom group (`herald_preferences`) plus a
subscriber fan-out delivery mode on every action.

One-off setup:

```bash
cv ext:enable herald   # creates the option group + custom group + its fields
cv scr /var/www/html/ext/herald/bin/install-profile.php
# → prints the self-service URL: /civicrm/profile/edit?gid=<N>&reset=1
```

Send users that URL. The profile asks them to pick a preferred channel (Telegram, webhook,
ntfy, Pushover or email), the recipient ID for that channel (chat ID, webhook URL, ntfy
topic, Pushover user key; email needs none and goes to the contact's primary address,
skipping contacts with "Do not email"), and their subscriptions: completed alert, pending
alert, status changed alert, daily digest, weekly digest. CiviCRM resolves the profile to
the logged-in contact with `access CiviCRM` and own-record only, so each user only sees and
edits their own preferences.

Rule example, "completed contributions, fanned out to subscribers":

| Field | Value |
|---|---|
| Trigger | `New entity is created — Contribution` |
| Conditions | `Contribution status = Completed` |
| Action | `Herald: Send contribution alert` |

In the action form set Delivery mode to "Subscribers" and Subscription type to "Every
completed contribution". Channel, chat_id and the like are ignored in subscriber mode.
Each subscriber's own channel and recipient ID are used. Only presentation options such as
`parse_mode`, `ntfy_title`, `pushover_sound` or `email_subject` are forwarded from the
rule. Recipient and credential keys (`chat_id`, `webhook_url`, `webhook_auth_token`,
`webhook_headers`, `ntfy_topic`, `pushover_user_key`, `email_to`, `email_cc`, `email_bcc`)
never are.

When the rule fires, the action queries all contacts whose `subscriptions` field contains
`completed_alert` and dispatches the rendered template through each one's preferred
channel. A bad chat ID for one contact does not block the others. Failures are logged with
`Civi::log()->warning()` and the loop continues.

To add a subscription type, add an entry to the option group `herald_subscription_type`,
either in the UI under Administer, Customize Data and Screens, Option Groups, or with
`cv api4 OptionValue.create`. Then reference the new value's `name` in the action's
subscription-type dropdown.

### Scoped subscriptions

The `herald_preferences` custom group carries optional `source_entity_type` and
`source_entity_id` fields. When set, for example `Event` and `42`, the contact's
subscriptions only fire for notifications whose action declares a matching `scope_entity`
resolving to that instance. Empty fields mean global subscriptions, which is the default.

## Channels

`channel` is a per-action parameter. Each registered channel reads its own keys from the
action params and from the optional `channel_config` JSON textarea on the form.

### Telegram (`channel: "telegram"`)

Top-level fields, already in the form: `chat_id`, `parse_mode` (`HTML`, `MarkdownV2` or
empty), `disable_notification`, `disable_web_page_preview`. Falls back to the
`herald_telegram_default_chat_id` setting when `chat_id` is empty.

When Telegram upgrades a group to a supergroup, its `chat_id` changes and
Telegram rejects sends with `400 group chat was upgraded to a supergroup chat`
(new id in the error body's `parameters.migrate_to_chat_id`) until every copy
is updated: the `herald_telegram_default_chat_id` setting, `herald_topics`,
each `civirule_rule_action.action_params` referencing the old id (serialized
PHP — unserialize, edit, reserialize; a SQL `REPLACE` corrupts it because the
string length changes), buffered rows in `civicrm_herald_buffer.delivery`
(JSON), and any `chat_id=…` baked into a scheduled job's parameters (e.g. a
digest job). The buffer flush only drops a row after a successful send, so
alerts queued during the outage go out on their own once every copy is fixed.

### Webhook (`channel: "webhook"`)

A generic POST. Covers Slack, Discord and Mattermost incoming webhooks plus any custom
endpoint.

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
| `webhook_body_format` | enum | `text` (raw body, default), `json`, `slack` (`{"text":…}`) or `discord` (`{"content":…}`) |
| `webhook_body_key` | string | Custom JSON key when `body_format=json` (default `text`) |
| `webhook_headers` | object | Extra request headers |
| `webhook_auth_token` | string | Sent as `Authorization: Bearer …` if no Authorization header is in `webhook_headers` |

The `herald_webhook_default_auth_token` setting is only used when the URL also comes from
`herald_webhook_default_url`. The default token is paired with the default endpoint and is
never attached to a caller-supplied URL such as a subscriber's personal webhook. The
`herald_webhook_allowed_hosts` setting optionally restricts webhook hosts to an allowlist,
with subdomains matching. When it is empty, private and reserved addresses are denied.

### ntfy.sh (`channel: "ntfy"`)

Free, simple, self-hostable. A topic is a push channel. Metadata goes in `X-*` headers.

```json
{
  "channel": "ntfy",
  "template": "herald_contribution_alert_completed",
  "channel_config": {
    "ntfy_topic": "example-donations",
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
| `ntfy_priority` | int | `X-Priority` (1 = min … 5 = max) |
| `ntfy_tags` | string | `X-Tags` (comma-separated; emoji tags supported) |
| `ntfy_click` | string | URL opened when the notification is tapped |
| `ntfy_markdown` | bool | Render the body as Markdown (`X-Markdown: yes`) |

The server URL comes from `herald_ntfy_server` (default `https://ntfy.sh`). Bearer auth
uses `herald_ntfy_token`.

### Pushover (`channel: "pushover"`)

A paid mobile-push service. It needs an app token, once per install, and a user or group
key per recipient.

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
| `pushover_user_key` | string | Override the default user or group key |
| `pushover_title` | string | Notification title |
| `pushover_priority` | int | -2 (silent) … 2 (emergency; `retry` and `expire` are added automatically) |
| `pushover_retry` | int | Emergency only: seconds between re-alerts (min 30, default 60) |
| `pushover_expire` | int | Emergency only: seconds until re-alerting stops (max 10800, default 3600) |
| `pushover_sound` | string | Sound name (see [Pushover sounds](https://pushover.net/api#sounds)) |
| `pushover_url` | string | Supplementary URL shown under the body |
| `pushover_url_title` | string | Label for `pushover_url` |
| `pushover_html` | bool | Body is Pushover-flavoured HTML |

The app token comes from `herald_pushover_app_token` (required). The user key falls back to
`herald_pushover_default_user_key`.

### Email (`channel: "email"`)

Delivers through CiviCRM's configured outbound mailer, so SMTP settings, mail logging and
dev redirection all apply. No extra credentials are needed.

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
| `email_subject` | string | Explicit subject. When unset, the template's `msg_subject` is Smarty-rendered with the same vars as the body. Final fallback is "Notification" |
| `email_format` | string | `html` (default) or `text`: which MIME part the rendered body becomes |
| `email_cc` | string | Comma-separated CC addresses |
| `email_bcc` | string | Comma-separated BCC addresses |

The From header comes from `herald_email_from` in the form `"Name" <email>`. When empty,
the site default From address is used.

## Alert aggregation

Any send action can set `aggregate_minutes`, as a form field or param. The default `0`
sends immediately. With a value above 0 the rendered alert is not sent right away but
parked in `civicrm_herald_buffer`. The managed scheduled job "Herald: Flush alert
aggregation buffer" (`Job.herald_buffer_flush`, `run_frequency: Always`) drains the buffer
on every cron run. Once a rule's oldest buffered alert is older than the window, everything
that rule collected so far goes out as one message.

- One buffered alert is sent unchanged. Aggregation only delayed it.
- Several alerts get a summary header with the count and, for contribution alerts, the
  summed amount, followed by the individual bodies.
- Grouping is per rule action. Two rules, or the same rule with changed params, never mix
  into one message.
- Delivery failures keep the rows and retry on the next cron run. After
  `herald_buffer_max_attempts` tries, or immediately when a flush resolves to no recipient,
  the group is parked. Parked rows stay in the table, are listed by
  `Herald.getParkedAlerts`, and only `Herald.reactivateParkedAlerts` puts them back in
  circulation.
- Maximum latency is `aggregate_minutes` plus the cron interval. Pick the window per rule,
  for example 5 for ticket sales and 60 for pending donations.
- At most `herald_buffer_max_items` individual bodies are printed (default 25, 0 means
  all). The rest becomes "… and N more", and the header keeps counting every one of them.
  A bulk operation such as an import or a batch status change can put thousands of alerts
  into a single window, and that message is neither readable nor accepted by every
  transport.
- A message that still exceeds a channel's hard limit is shortened by the channel itself,
  with the drop stated in the message. Telegram caps `sendMessage` at 4096 characters and
  rejects anything longer outright, so the alert would otherwise never arrive. The cut
  falls on a paragraph boundary and HTML markup is closed again. A `parse_mode: MarkdownV2`
  body that has to be shortened can still be cut inside its own formatting.

## Topics: recipient-agnostic publishing

Other extensions and scripts publish to a topic. The `herald_topics` setting decides where
each topic lands. Publishers never carry chat IDs.

```bash
cv api4 Setting.set +v 'herald_topics={
  "default": {"channel": "telegram"},
  "ops":     {"channel": "telegram", "chat_id": "-100…", "parse_mode": "HTML"},
  "revenue": {"channel": "telegram", "chat_id": "-100…", "aggregate_minutes": 5}
}'

cv api4 Herald.publish +v 'topic=ops' +v 'message="🔴 disk almost full"'
```

Resolution merges the topic entry over the `default` entry. An unrouted topic, with no
entry and no default, throws. A route with `aggregate_minutes > 0` sends through the same
aggregation buffer as the CiviRules alerts. Instead of `message`, pass `template`
(a workflow name) plus `vars` to render a MessageTemplate.

## Extra digest sections from other extensions

A composed digest (`Job.herald_contribution_digest` with `blocks`) can only report what a
contribution query answers. Numbers from another entity, such as booked event seats,
membership counts or queue depth, belong to the extension that owns them. The `sections`
job parameter names the keys the digest wants. herald asks around through
`hook_civicrm_heraldDigestSections` and appends the answers after the blocks, in the order
listed.

```php
// In any extension's main .php file:
function myext_civicrm_heraldDigestSections(array $keys, array $context, array &$sections): void {
  if (in_array('myext.seats', $keys, TRUE)) {
    $sections['myext.seats'] = "🪑 <b>Seats</b>\n138 booked";
  }
}
```

The job parameter is `sections=myext.seats,myext.queue`, or a JSON array. `$context`
carries the job params under `params` and the resolved `period`, so a listener can match
the digest's timeframe. Unrequested keys and empty bodies are dropped, so a listener may
answer unconditionally. This is multi-block mode only. Single-block digests render one
template and have no section concept.

## Check monitor: push alerts for degraded states

Any extension contributes health checks through `hook_civicrm_heraldChecks`. herald runs
them on every cron and publishes only transitions (ok → fail, fail → ok) to the
`herald_monitor_topic` topic (default `ops`) using the `herald_monitor_alert` template.
Steady states never alert. A new check alerts only if it is already failing.

```php
// In any extension's main .php file:
function myext_civicrm_heraldChecks(array &$checks): void {
  $checks['myext.queue'] = fn() => ['status' => 'ok']; // or 'fail' + detail, or 'skipped'
}
```

The pulse extension (0.2 or later) contributes `pulse.database`, `pulse.cron` and
`pulse.system` automatically when both extensions are installed.

Tuning settings, all optional. The defaults reproduce the plain one-shot behaviour.

| Setting | Default | Effect |
| --- | --- | --- |
| `herald_monitor_open_after` | `1` | Require N consecutive failing runs before a fail is reported, which rides out flapping. |
| `herald_monitor_close_after` | `1` | Require M consecutive ok runs before a recovery is reported. |
| `herald_monitor_renotify_hours` | `24` | Re-announce a check stuck failing this long. `0` disables reminders. |
| `herald_monitor_snooze` | `[]` | Map of check name to ISO datetime. The check stays silent (no fail, no renotify) until then. A fail → ok recovery still fires afterwards if a failure was announced before the snooze. Expired entries are pruned each run. |
| `herald_monitor_expected` | `[]` | Check names that must always be present. A listed check missing from the hook is reported as failing ("check missing, extension disabled?"). Empty keeps the default of silently dropping vanished checks. |

Each run takes the `worker.herald.monitor` lock, so overlapping crons skip rather than
double-send. The per-check state is persisted right after each alert is published, so a
crash or a failing send mid-batch never re-sends alerts that already went out.

This monitor runs inside the instance and cannot report a hard-down site. Keep an external
poller such as Uptime Kuma on pulse's `/civicrm/pulse` endpoint for dead-man coverage. The
monitor covers everything short of that.

## Action parameter reference

### `Send contribution digest`

| Param | Type | Default | Meaning |
|---|---|---|---|
| `channel` | string | `telegram` | Channel key |
| `template` | string | required | MessageTemplate workflow_name |
| `chat_id` | string | (default setting) | Recipient |
| `parse_mode` | string | `HTML` | Telegram parse mode |
| `period` | string | `yesterday` | `yesterday`, `today`, `last_7_days` or `last_30_days` |
| `campaign_id` | int | none | Filters all queries |
| `financial_type_id` | int | none | Filters all queries |
| `source_pattern` | string | none | LIKE pattern for the source field |
| `show_pending` | bool | `true` | Include the pending-pool block |
| `show_histogram` | bool | `true` | Include the histogram block |
| `bucket_count` | int | `8` | Target histogram bucket count |
| `bucket_edges` | array | (adaptive) | Override edges, e.g. `[0,10,25,100,1000]` |
| `goal` | float | none | Renders campaign progress when set |
| `campaign_title` | string | none | Free-text title for the campaign block |
| `sections` | string | none | Extra section keys from other extensions, multi-block mode only |

### `Send contribution alert`

| Param | Type | Default | Meaning |
|---|---|---|---|
| `channel`, `template`, `chat_id`, `parse_mode` | | | as for the digest |
| `campaign_id` | int | none | Computes campaign progress |
| `goal` | float | none | Required alongside `campaign_id` for the percentage |
| `aggregate_minutes` | int | `0` | Above 0: buffer alerts and send one combined message per window |
| `campaign_title` | string | none | Free-text title |
| `include_status_transition` | bool | `true` | Pass `from_status` and `to_status` to the template |

### `Send notification` (generic)

| Param | Type | Meaning |
|---|---|---|
| `channel`, `template`, `chat_id`, `parse_mode` | | as above |
| `vars` | JSON | Variable bag passed verbatim to the template |

### Delivery parameters (all send actions)

| Param | Type | Default | Meaning |
|---|---|---|---|
| `delivery_mode` | string | `direct` | `direct`, `subscribers`, `group` or `addresses` |
| `subscription_type` | string | none | Subscriber mode: which opt-in kind to fan out to |
| `group_id` | int | none | Group mode: all contacts of this CiviCRM group, each via their own preferred channel. Contacts without preferences fall back to email to their own address |
| `email_addresses` | string | none | Address-list mode: raw emails (comma, semicolon or newline separated), sent via the email channel with no contact linkage. Meant for ops recipients |
| `respect_suspension` | bool | `false` | Fan-outs: skip email recipients with `is_opt_out` or an on-hold address. Skips are logged and counted, never fail the send. `do_not_email` is always skipped regardless |
| `email_location_type` | int | `0` | Resolve contact emails of this location type, falling back to primary. `0` means primary |
| `scope_entity` | string | none | Send notification only: which trigger entity instance this notification concerns (`Event`, `Membership` or `Participant`; `Event` also resolves via a Participant's event_id). Subscriptions scoped to a different instance are skipped, unscoped subscriptions always match. The contribution actions have no trigger context and do not offer the field |

## Condition: Field transition (generic)

"Herald: Field transition" generalises "Previous Contribution status". Field X of entity E
transitioned from (operator, value) to (operator, value). Both sides are optional, and an
empty operator means any value. Operators are `=`, `!=`, `>`, `<`, `>=`, `<=`, `is_empty`
and `not_empty`.

A one-sided condition, with only the before or only the after side configured,
additionally requires that the value actually changed, so unrelated edits of a row already
in the target state do not re-fire it. That also means it needs a known pre-edit value. The
pre-edit value comes from herald's own `hook_civicrm_pre` snapshot, and the fields in use
are watched automatically. CiviRules' original data takes per-edit precedence. It is the
same reliability model as the contribution condition. Example: entity `Membership`, field
`status_id`, before `= 2`, after `= 3`.

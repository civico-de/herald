# Reference

## CiviRules actions

All herald actions share the delivery fields: template, channel, Telegram chat ID and
options, "Channel-specific config" (JSON), `aggregate_minutes` and the
[delivery mode](delivery.md#delivery-modes) with its options. The action forms need the
permission "administer CiviCRM".

### Herald: Send notification (generic)

Renders any template with the variables the trigger carries
([trigger variables](#trigger-variables)) plus the JSON object in `vars`.

| Parameter | Meaning |
|---|---|
| `vars` | JSON object of template variables. A top-level key here replaces the trigger's variable of that name as a whole: `{"contact": {…}}` drops the generated `contact` fields |
| `scope_entity` | `Event`, `Membership` or `Participant`: the trigger entity the notification concerns, for [scoped subscriptions](delivery.md#scoped-subscriptions) |

On a Contribution trigger the variables also carry the contribution, the progress of
its campaign and the status transition ([contribution variables](#contribution-variables)).

### Herald: Send contribution digest

Sends a digest each time the rule fires. Parameters as in
[docs/digests.md](digests.md#parameters), without the job-only ones.

## CiviRules conditions

| Condition | Parameters | True when |
|---|---|---|
| Herald: Field transition | `entity`, `field`, `from_operator`, `from_value`, `to_operator`, `to_value` | The field's value before and after the edit match both configured sides |

The before-edit value comes from the original data CiviRules records for the edit, which
also survives delayed rules, or from herald's own snapshot taken in `hook_civicrm_pre`.
When it is unknown, the condition is false: a missed alert beats a false one. The
after-value comes from the stored row, so a partial update whose payload lacks the field
is still judged by its real value.

`entity` is the APIv4 entity name, `field` the APIv4 field name.
Operators: `=`, `!=`, `>`, `<`, `>=`, `<=`, `is_empty`,
`not_empty`; an empty operator leaves that side unconstrained. With only one side
configured, the value must also have changed, so unrelated edits of a record already in
the target state do not fire again. With both sides configured, the two predicates alone
decide. herald snapshots the fields that active rules watch;
long-running queue or CLI workers pick up a newly configured field only after a restart.
Examples:

| Watches | Entity | Field | Before | After |
|---|---|---|---|---|
| Membership turns from status 2 to 3 | `Membership` | `status_id` | `= 2` | `= 3` |
| Leaves Pending | `Contribution` | `contribution_status_id` | `=` Pending's id | empty |
| Was Pending before the edit, changed or not | `Contribution` | `contribution_status_id` | `=` Pending's id | `not_empty` |
| Event unpublished | `Event` | `is_active` | `= 1` | `= 0` |

## APIv4

Entity `Herald`. Every action requires "administer CiviCRM"; the metadata actions
such as `getFields` need only "access CiviCRM".

| Action | Parameters | Does |
|---|---|---|
| `send` | `channel`, `message`, `subject`, `format`, `config` | Deliver a finished message through one channel. The entry point for other extensions; reports `unknown_config_keys` |
| `publish` | `topic`, `message` or `template` + `vars`, `format`, `amount` | Deliver through the route in `herald_topics` ([topics](delivery.md#topics)) |
| `notify` | `actionParams`, `triggerContext` | What "Send notification" calls |
| `notifyDigest` | `actionParams` | What "Send contribution digest" calls |
| `checkFieldTransition` | `entity`, `entityId`, `field`, operators and values, `originalValue`, `hasOriginal` | What "Field transition" asks |
| `getChannels` | `channel` (optional) | Registered channels and their config keys |
| `getParkedAlerts` | `ids` (optional) | [Parked alerts](delivery.md#parked-alerts), without bodies |
| `reactivateParkedAlerts` | `ids` (optional, empty = all) | Put parked alerts back into circulation |
| `renderTemplate` | `workflow`, `vars` | Render a template without sending |
| `testTelegram` | `message`, `chatId`, `channel` (default `telegram`) | Send a test message |

Entity `HeraldBufferedAlert` is a read-only view (`get`, `getFields`) of the alert buffer
for SearchKit, restricted to "administer CiviCRM". `body` and `delivery` can carry
contact data; the shipped [parked-alerts display](delivery.md#parked-alerts) leaves them out.

`send` and `publish` address herald by string, so another extension can use them without
a hard dependency:

```php
if (class_exists(\Civi\Api4\Herald::class)) {
  civicrm_api4('Herald', 'publish', ['topic' => 'ops', 'message' => 'Import finished']);
}
```

## Scheduled jobs

| Job | Ships | Frequency | Does |
|---|---|---|---|
| Herald: Flush alert aggregation buffer (`Job.herald_buffer_flush`) | managed | Always | Sends aggregated groups whose window has passed |
| Herald: Run check monitor (`Job.herald_monitor`) | managed | Always | Runs the [check monitor](monitor.md) |
| `Job.herald_contribution_digest` | create it yourself | Hourly with `send_hour` | [Scheduled digest](recipes.md#scheduled-digests) |

## Templates

Templates are `MessageTemplate` workflows, editable under Mailings → Message Templates →
System Workflow Messages. They render as Smarty strings.

| Workflow | For |
|---|---|
| `herald_contribution_alert_completed` | Completed contribution |
| `herald_contribution_alert_pending` | Pending contribution (announced transfer) |
| `herald_contribution_status_changed` | Status change, e.g. Pending → Completed |
| `herald_contribution_alert_ticket` | Ticket or event contribution |
| `herald_contribution_alert_ticket_pending` | Ticket ordered, transfer announced |
| `herald_contribution_alert_ticket_paid` | Ticket transfer received |
| `herald_contribution_digest_daily` | Daily digest |
| `herald_contribution_digest_weekly` | Weekly digest |
| `herald_contribution_digest_block` | One block of a composed digest |
| `herald_event_created_alert` | Event created |
| `herald_event_deactivated_alert` | Event deactivated |
| `herald_event_deleted_alert` | Event deleted |
| `herald_event_registration_alert` | New event registration (masked contact) |
| `herald_event_cancellation_alert` | Registration cancelled (masked contact) |
| `herald_membership_signup_alert` | New membership (masked contact) |
| `herald_group_subscription_alert` | Contact added to a group (masked contact) |
| `herald_monitor_alert` | Check monitor transition: `check`, `status`, `detail`, `renotify` |

An admin's edit to a shipped template survives until a herald release changes that
template; the release then overwrites the edit. To keep a customised version, copy it to
a workflow name of your own. herald renders only the default version of a workflow
(`is_default`). The CiviRules forms list workflows by prefix: "Send notification" offers
`herald_%`, "Send contribution digest" `herald_contribution_digest_%`. The job and the API take any
workflow name.

### Escaping

Templates render to HTML. String variables are HTML-escaped before they reach `msg_html`,
so data cannot inject markup; the subject gets them unescaped. A template with only
`msg_text` is rendered and then escaped as a whole. Section bodies and the digest
`header` are inserted as HTML by design.

### Trigger variables

**Send notification** fills these from the trigger, when it carries the entity:

| Variable | Content |
|---|---|
| `contact.id`, `.display_name`, `.email` | The trigger's contact |
| `contact.masked_name`, `.masked_email` | The same, masked: every word keeps its first two characters (`Jane Doe` → `Ja** Do*`); in the email the local part and each domain label are masked that way and only the TLD stays readable (`jane@example.org` → `ja**@ex*****.org`) |
| `group.id`, `.title` | Contact is added to Group |
| `membership.membership_type`, `.status` | Membership triggers: type name and status label |
| `participant.role`, `.status` | Participant triggers (labels) |
| `event.id`, `.title`, `.start_date`, `.is_active` | Event triggers; participant triggers give `id` and `title` |

A lookup error leaves the variable out and is logged instead of failing the
notification. A deleted contact or group leaves its `id` with empty text fields; a
deleted event keeps the values from the trigger's own data.

### Contribution variables

**Send notification** adds these when the trigger carries a Contribution:

| Variable | Content |
|---|---|
| `contribution` | The contribution's fields, e.g. `total_amount`, `source` (after [source normalisation](digests.md#source-normalisation)), `receive_date` |
| `campaign.title`, `.total`, `.count` | The contribution's own campaign: its title and the running total and count of its contributions |
| `campaign.goal`, `.percent` | The campaign's revenue goal (`goal_revenue`) and the share reached; `0` when the campaign has no goal |
| `from_status`, `to_status` | Status names before and after the edit; `from_status` is empty when the status before the edit is unknown |

Without a campaign, every `campaign` field is empty or `0`, so `{if $campaign.goal}`
hides the progress line.

Digest variables are listed in [docs/digests.md](digests.md#template-variables).

## Settings

Set with `cv setting:set name=value` or `cv api4 Setting.set +v 'name=value'`; array
settings take JSON. The scalar settings are also on the admin screen
`civicrm/admin/setting/herald` (permission "administer CiviCRM"); the array settings
(`herald_topics`, `herald_webhook_allowed_hosts`, `herald_source_normalization` and the
monitor maps) are not.

| Setting | Default | Meaning |
|---|---|---|
| `herald_telegram_bot_token` | empty | Telegram bot token |
| `herald_telegram_default_chat_id` | empty | Fallback Telegram chat |
| `herald_webhook_default_url` | empty | Fallback webhook URL |
| `herald_webhook_default_auth_token` | empty | Bearer token, sent only to the default URL |
| `herald_webhook_allowed_hosts` | empty | Webhook host allowlist; empty denies private and reserved addresses |
| `herald_ntfy_server` | `https://ntfy.sh` | ntfy server |
| `herald_ntfy_default_topic` | empty | Fallback ntfy topic |
| `herald_ntfy_token` | empty | Bearer token for protected ntfy topics |
| `herald_pushover_app_token` | empty | Pushover application token |
| `herald_pushover_default_user_key` | empty | Fallback Pushover user or group key |
| `herald_email_from` | empty | From header; empty uses the site default |
| `herald_email_default_to` | empty | Fallback email recipient |
| `herald_topics` | empty | [Topic routes](delivery.md#topics) |
| `herald_buffer_max_attempts` | `5` | Failed flushes before a group is parked |
| `herald_buffer_max_items` | `25` | Bodies printed per aggregated message; `0` prints all |
| `herald_source_normalization` | empty | [Source labels](digests.md#source-normalisation) |
| `herald_monitor_topic` | `ops` | Topic for monitor alerts |
| `herald_monitor_open_after`, `herald_monitor_close_after`, `herald_monitor_renotify_hours`, `herald_monitor_snooze`, `herald_monitor_expected` | | [Monitor tuning](monitor.md#tuning) |
| `herald_monitor_state` | | Internal monitor state; do not edit |

## Hooks

| Hook | Signature | Purpose |
|---|---|---|
| `hook_civicrm_heraldChecks` | `(array &$checks)` | Contribute [monitor checks](monitor.md#contributing-a-check) |
| `hook_civicrm_heraldDigestSections` | `(array $keys, array $context, array &$sections)` | Contribute [digest sections](digests.md#sections-from-other-extensions) |

## System status

While a deploy has left herald's rules switched off, CiviCRM's system status shows a
warning naming the rules and the command that re-enables them
([docs/deploy.md](deploy.md)).

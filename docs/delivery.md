# Delivery

Who receives a message, when, and what happens when delivery fails.

## Delivery modes

Every herald CiviRules action has a delivery mode. Topic routes accept the same
parameters.

| `delivery_mode` | Recipients | Required parameter |
|---|---|---|
| `direct` (default) | The one recipient configured on the action, or the channel's default setting | none |
| `subscribers` | Every contact whose preferences subscribe to a notification kind, each through their own channel | `subscription_type` |
| `group` | The contacts of a CiviCRM group, each through their own channel; subscriptions and scope do not matter. Contacts without usable preferences, such as a Telegram channel without chat ID, get an email to their own address | `group_id` |
| `addresses` | A raw list of email addresses (comma, semicolon or newline separated), no contacts involved; meant for ops recipients | `email_addresses` |

Options for the contact fan-outs (`subscribers`, `group`):

| Parameter | Default | Effect |
|---|---|---|
| `respect_suspension` | `false` | Also skip email recipients who opted out or whose address is on hold. Skips are logged and counted; only if they leave nobody does the delivery fail |
| `email_location_type` | `0` | Use the contact's email of this location type, falling back to the primary address. `0` means primary |

Contacts marked "Do not email" never get email, whatever the options.

One failing recipient does not stop a fan-out: herald logs a warning and continues with
the next contact. The delivery fails as a whole only when every recipient failed, or when
it resolves to nobody (no subscriber, an empty group, a blank address list). Once one
recipient got the message the delivery counts as done, and the failed ones are not
retried.

## Subscribers

Each contact can choose a channel and the notifications they want. herald ships the
custom group `herald_preferences` on contacts:

| Field | Meaning |
|---|---|
| Preferred channel | Telegram, webhook, ntfy, Pushover or email |
| Telegram chat ID, Pushover user/group key, ntfy topic, Webhook URL | The recipient for the chosen channel. Email needs none and uses the contact's address |
| Subscriptions | The notification kinds the contact wants |
| Scope: entity type, Scope: entity ID | Optional, see [Scoped subscriptions](#scoped-subscriptions) |

Admins fill these in on the contact. For self-service, create the profile once:

```bash
cv scr /var/www/html/ext/herald/bin/install-profile.php
# prints the URL: /civicrm/profile/edit?gid=<N>&reset=1
```

The script is idempotent. Send staff the printed URL: CiviCRM opens the profile for the
logged-in contact, who can only see and edit their own record.

Then set an action's delivery mode to "Subscribers" and pick the subscription type. The
action's own recipient keys are ignored in this mode. From the rule only presentation
options travel to the subscribers' channels, such as `parse_mode`, `ntfy_title`,
`pushover_sound` or `email_subject`; recipient and credential keys (`chat_id`,
`webhook_url`, `webhook_auth_token`, `webhook_headers`, `ntfy_topic`,
`pushover_user_key`, `email_to`, `email_cc`, `email_bcc`) never do.

Shipped subscription types:

| Name | Label |
|---|---|
| `completed_alert` | Every completed contribution |
| `pending_alert` | Every pending contribution |
| `status_changed_alert` | Pending → Completed transitions |
| `daily_digest` | Daily digest |
| `weekly_digest` | Weekly digest |

They are values of the option group `herald_subscription_type`. Add your own under
Administer → Customize Data and Screens → Option Groups, or with
`cv api4 OptionValue.create`; the new value appears in the action's subscription-type
list. Disabling a value only removes it from that list: actions that already use it keep
delivering to its subscribers.

The digest types serve rules that send digests through the CiviRules action. The
scheduled digest job sends to one channel and has no delivery modes.

### Scoped subscriptions

A preference record with **Scope: entity type** and **Scope: entity ID** set, for example
`Event` and `42`, only receives notifications that concern that event. A rule declares
what it concerns through `scope_entity` on the **Send notification** action: `Event`,
`Membership` or `Participant`, resolved from the trigger; `Event` also resolves through a
participant's event. Records without a scope receive everything they subscribed to. A
scoped record applies to all of its subscriptions, so it no longer receives unscoped
notifications such as contribution alerts or digests. The digest action has no scope.
The self-service profile does not include the scope fields; admins set them on the
contact.

## Topics

Publishers name a topic; the `herald_topics` setting decides channel, recipient and
aggregation. A script or another extension then never carries chat IDs or URLs.

```bash
cv api4 Setting.set +v 'herald_topics={
  "default": {"channel": "telegram"},
  "ops":     {"channel": "telegram", "chat_id": "-100…"},
  "revenue": {"channel": "telegram", "chat_id": "-100…", "aggregate_minutes": 5}
}'

cv api4 Herald.publish topic=ops message='🔴 disk almost full'
cv api4 Herald.publish topic=revenue template=herald_contribution_alert_completed \
    vars='{"contribution":{"total_amount":50}}' amount=50
```

A route entry takes the same parameters as an action, including `channel_config`,
`delivery_mode` and `aggregate_minutes`. The topic's entry is merged over `default`; a
topic with neither is an error. `channel` defaults to `telegram`.

`Herald.publish` takes either `message` or `template` plus `vars`. A `message` is HTML
like a rendered template; `format=text` marks it as plain text, which HTML channels then
show escaped and text channels receive unchanged. `amount` adds to the total in an
aggregated header.

The check monitor publishes to the topic named in `herald_monitor_topic` (default `ops`).

## Aggregation

Any action or route can set `aggregate_minutes`. `0`, the default, sends immediately. A
value above 0 parks the rendered alert in the table `civicrm_herald_buffer`. The managed
job "Herald: Flush alert aggregation buffer" (`Job.herald_buffer_flush`) runs on every
cron run; once the oldest waiting alert of a group is older than its window, the whole
group goes out as one message.

- A group is one set of parameters: rules whose actions carry identical parameters share
  a message, and changing a rule's parameters starts a new group. Topic routes group
  separately from actions. With `scope_entity` set, alerts about different events,
  memberships or participants form separate groups.
- A single waiting alert goes out unchanged; aggregation only delayed it.
- Several alerts get a header with the count and, when the alerts concern contributions, the sum, such
  as "📬 7 messages, total 385.00 € (collected over 5 min)", followed by the individual
  bodies.
- At most `herald_buffer_max_items` bodies are printed (default 25, `0` prints all); the
  rest is summarised as "… and N more" while the header still counts every alert. An
  import or a bulk status change can fill one window with thousands of alerts.
- Telegram shortens a message that still exceeds its 4096-character limit and says so in
  the message ([Telegram](channels.md#telegram)). Other channels send the full text;
  keep `herald_buffer_max_items` low enough for their limits.
- The longest delay is the window plus the cron interval. Choose per rule, for example
  5 minutes for ticket sales and 60 for pending donations.

## Parked alerts

A buffered group whose delivery fails stays in the buffer and is retried on the next cron
run. After `herald_buffer_max_attempts` failed tries (default 5) it is parked; a group
that resolves to no recipient is parked at once, because retrying cannot help. Nothing is
dropped: parked rows stay in the table until someone reactivates them.

```bash
cv api4 Herald.getParkedAlerts                          # id, park_reason, attempts, last_error, dates
cv api4 Herald.reactivateParkedAlerts '{"ids": [12, 13]}'   # without ids: every parked row
```

The same rows are listed under Administer › System Settings › Herald parked alerts, a
SearchKit display on the read-only entity `HeraldBufferedAlert`. Neither the list nor
`getParkedAlerts` shows message bodies or delivery parameters, which can contain contact
data.
Reactivated rows start with a fresh retry budget. They keep the parameters stored when
they were buffered, so fixing the rule or route does not repair a wrong explicit
recipient in them; subscriber and group recipients are resolved anew on delivery. The check monitor reports parked rows
and a stuck buffer ([docs/monitor.md](monitor.md#heralds-own-checks)).

Uninstalling herald drops the buffer table, parked rows included; disabling keeps it.

Sends without aggregation are not buffered: a failure there is an exception for the
caller, the API result or CiviRules, and is not retried.

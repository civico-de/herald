# Channels

A channel turns a rendered message into one delivery. Every caller names the channel by
key; the keys below are what `Herald.getChannels` lists.

| Key | Delivers to | Needs |
|---|---|---|
| `telegram` | A Telegram chat or group via the Bot API | Bot token |
| `webhook` | An HTTP endpoint: Slack, Discord, Mattermost, custom | URL |
| `ntfy` | An ntfy topic (ntfy.sh or self-hosted) | Topic |
| `pushover` | Pushover users or groups | App token, user key |
| `email` | An email address via CiviCRM's outbound mailer | nothing |
| `activity` | An assigned CiviCRM activity | Assignee contact |

## Where channel keys come from

Settings hold credentials and fallback recipients. Per-call keys override the fallback.
They come from the parameters of a CiviRules action or a `herald_topics` route, either
top-level (the Telegram fields have their own form fields) or inside `channel_config`,
the action form's "Channel-specific config" JSON field; `channel_config` wins when both
set a key. `Herald.send` takes them as `config`.

```bash
cv api4 Herald.getChannels channel=ntfy     # the keys one channel accepts, with labels and types
```

`Herald.send` passes unknown keys through and reports them as `unknown_config_keys`, so a
typo is visible in the result without changing delivery.

`Herald.send` also takes two channel-neutral parameters and maps them onto each channel's
own key: `subject` (email subject, ntfy and Pushover title, activity subject) and `format`
(`text` or `html`). Channels without an equivalent ignore them. Actions and topic routes
use the channel's own keys, such as `email_subject` or `activity_format`.

## Message bodies

Every channel receives HTML: templates render to HTML, and a plain-text message
(`format=text`) is escaped first. Channels whose transport does not render HTML get the
plain text of the body, never the raw markup. The webhook's chat and raw formats, ntfy,
Pushover without `pushover_html`, Telegram without `parse_mode: HTML` and the text parts
of email and activities all get plain text. The webhook `json` format hands the HTML body
to the receiver.

## Telegram

Settings: `herald_telegram_bot_token` (from @BotFather), `herald_telegram_default_chat_id`
(negative IDs are groups).

| Key | Meaning |
|---|---|
| `chat_id` | Recipient chat or group. Empty falls back to the default setting |
| `parse_mode` | `HTML` (default), `MarkdownV2` or empty for plain text |
| `disable_notification` | Deliver silently |
| `disable_web_page_preview` | No link previews |

With `HTML` Telegram renders the body's markup. With empty `parse_mode` it gets the plain
text; with `MarkdownV2` the plain text with every reserved character escaped, so no markup
survives outside `HTML`.

Telegram rejects messages over 4096 characters. herald shortens such a body at a paragraph
boundary, closes open HTML tags and states the cut in the message. A `MarkdownV2` body can
still be cut inside its own formatting.

### When a group becomes a supergroup

Telegram changes a group's chat ID when it upgrades the group to a supergroup and rejects
sends to the old ID with `400 group chat was upgraded to a supergroup chat`; the new ID is
in the error's `parameters.migrate_to_chat_id`. Replace every copy of the old ID:

- the `herald_telegram_default_chat_id` setting and `herald_topics` entries;
- every CiviRules action that names it: edit the action in the UI. In the database the
  parameters are serialised PHP, and a SQL `REPLACE` corrupts them because the string
  length changes;
- the `delivery` column of waiting rows in `civicrm_herald_buffer` (JSON);
- a `chat_id=…` line in a scheduled job's parameters, such as a digest job.

Buffered alerts stay in the buffer until a send succeeds, so alerts queued during the
outage go out by themselves once every copy is fixed, unless they were parked in the
meantime ([docs/delivery.md](delivery.md#parked-alerts)).

## Webhook

Settings: `herald_webhook_default_url`, `herald_webhook_default_auth_token`,
`herald_webhook_allowed_hosts`.

| Key | Meaning |
|---|---|
| `webhook_url` | Endpoint. Empty falls back to `herald_webhook_default_url` |
| `webhook_method` | `POST` (default) or `PUT` |
| `webhook_body_format` | `text` (default): the plain text as request body. `json`: `{"text": <HTML body>}`. `slack`: `{"text": <plain text, & < > escaped>}`. `discord`: `{"content": <plain text>}` |
| `webhook_body_key` | JSON key for `json` (default `text`) |
| `webhook_headers` | Extra request headers (object) |
| `webhook_auth_token` | Sent as `Authorization: Bearer …` unless `webhook_headers` already has an Authorization header |

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

The default token belongs to the default URL: it is sent only when the URL also comes from
`herald_webhook_default_url`, never to a URL a caller or a subscriber supplied.

Requests do not follow redirects; give the final URL. A URL must be http or https, carry
no credentials, and by default must not resolve to a private, reserved, link-local or
metadata address. A non-empty `herald_webhook_allowed_hosts` list restricts delivery to
those hosts and their subdomains instead, and an allowed host may then be internal.

## ntfy

Settings: `herald_ntfy_server` (default `https://ntfy.sh`), `herald_ntfy_default_topic`,
`herald_ntfy_token` (bearer token for protected topics).

| Key | Meaning |
|---|---|
| `ntfy_topic` | Topic. Empty falls back to `herald_ntfy_default_topic` |
| `ntfy_title` | `X-Title` |
| `ntfy_priority` | `X-Priority`: 1 (min) to 5 (max), or ntfy's names |
| `ntfy_tags` | `X-Tags`, comma-separated; emoji tag names work |
| `ntfy_click` | URL opened when the notification is tapped |
| `ntfy_markdown` | Send `X-Markdown: yes` |

The body is the plain text of the message; metadata travels in the headers.

## Pushover

Settings: `herald_pushover_app_token` (required), `herald_pushover_default_user_key`.

| Key | Meaning |
|---|---|
| `pushover_user_key` | User or group key. Empty falls back to the default setting |
| `pushover_title` | Notification title |
| `pushover_priority` | -2 (silent) to 2 (emergency) |
| `pushover_retry` | Emergency only: seconds between repeats (minimum 30, default 60) |
| `pushover_expire` | Emergency only: seconds until repeats stop (maximum 10800, default 3600) |
| `pushover_sound` | Sound name, see [Pushover sounds](https://pushover.net/api#sounds) |
| `pushover_url`, `pushover_url_title` | Link shown under the message, and its label |
| `pushover_html` | Send the HTML body (Pushover's subset) instead of plain text |

Emergency priority requires retry and expire; herald adds the defaults when they are not
set.

## Email

Delivers through CiviCRM's outbound mailer, so SMTP settings, mail logging and a
development redirect all apply.

Settings: `herald_email_from` (`Name <address>`; empty uses the site's default From
address), `herald_email_default_to`.

| Key | Meaning |
|---|---|
| `email_to` | Recipient. Empty falls back to `herald_email_default_to` |
| `email_to_name` | Recipient display name |
| `email_subject` | Subject. When empty, actions and topics use the template's rendered subject; the scheduled digest job does not. The last fallback is "Notification" |
| `email_format` | `html` (default): the body with its line breaks as `<br>`. `text`: the plain text |
| `email_cc`, `email_bcc` | Comma-separated addresses |

In fan-outs to contacts the email channel uses each contact's own address and skips
contacts with "Do not email" ([docs/delivery.md](delivery.md#delivery-modes)).

## Activity

Keeps a notification inside CiviCRM as an assigned activity, so operational work stays
visible and auditable even without an external transport. Needs no settings.

| Key | Meaning |
|---|---|
| `activity_assignee_contact_id` | Assignee (required) |
| `activity_source_contact_id` | "Added by" contact; defaults to the assignee |
| `activity_target_contact_id` | Contact the activity concerns (optional) |
| `activity_type` | Activity type name; default `herald_notification`, which ships with herald |
| `activity_status` | Activity status name; default `Scheduled` |
| `activity_subject` | Subject; defaults to an excerpt of the body |
| `activity_format` | `html` (default) or `text` |

## Adding a channel

Channels live in herald: implement `Civi\Herald\Channel\ChannelInterface` and add the
class to `ChannelRegistry::default()`. `getConfigSchema()` is the single source of truth
for the channel's keys: `Herald.getChannels` and the unknown-key report read it. The
action forms have dedicated fields only for Telegram; other channels' keys go into the
"Channel-specific config" JSON.
`getSemanticFields()` maps `subject` and `format` onto the channel's keys, so callers never
hard-code a channel's key names.

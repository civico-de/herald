---
title: Channels
order: 20
routes:
  - civicrm/admin/setting/herald
  - civicrm/admin/herald
---

# Channels

A channel delivers a message to one place: a chat, a phone, an inbox or a CiviCRM activity. Actions, topic routes and API calls name the channel by its key.

| Key | Delivers to | Needs |
|---|---|---|
| `telegram` | A Telegram chat or group, through a bot | Bot token |
| `webhook` | An HTTP endpoint such as Slack, Discord, Mattermost or your own | URL |
| `ntfy` | An ntfy topic on ntfy.sh or your own server | Topic |
| `pushover` | Pushover users or groups | App token and user key |
| `email` | An email address, through CiviCRM's outbound mailer | Nothing |
| `activity` | An assigned CiviCRM activity | Assignee contact |

## Configuring a channel

Credentials and fallback recipients are settings. Most of them are on the Herald settings screen, Administer » Herald » Settings. You can also set them with cv:

```bash
cv setting:set herald_telegram_bot_token=<bot-token>
```

Each delivery can name its own recipient and options. These per-call keys override the fallback settings. Where they go depends on the caller:

- A CiviRules action has form fields for the Telegram options. Every other key goes into the field "Channel-specific config" as a JSON object.
- A topic route takes the keys in its entry, either directly or inside `channel_config`.
- `Herald.send` takes them in `config`.

When a key is set both directly and in `channel_config`, the value in `channel_config` wins. A `channel_config` that is not a JSON object fails the send.

To list the keys a channel accepts, with labels and types:

```bash
cv api4 Herald.getChannels channel=ntfy
```

`Herald.send` passes unknown keys through unchanged and lists them in its result as `unknown_config_keys`. A typo shows up there and does not stop the delivery.

`Herald.send` also accepts a subject and a format that work on every channel. herald maps the subject to the email subject, the ntfy title, the Pushover title or the activity subject. It maps the format, text or html, to the email and activity format. Channels that have no such field ignore both. Actions and topic routes do not have these shortcuts and use the channel's own keys, such as `email_subject`.

## Message format

Every message starts as HTML. Templates render to HTML, and a message sent as plain text is escaped into HTML first. A channel that cannot show HTML receives the plain text of the message, never the raw markup.

These receive plain text:

- Telegram without the HTML parse mode
- the webhook formats text, slack and discord
- ntfy
- Pushover unless `pushover_html` is set
- email and activities in text format

The webhook format json hands the HTML to the endpoint.

## Telegram

Create a bot with @BotFather and store its token in `herald_telegram_bot_token`. Store the default chat in `herald_telegram_default_chat_id`. Group chats have negative IDs. A person can find their own chat ID with @userinfobot.

| Key | Meaning |
|---|---|
| `chat_id` | Recipient chat or group. Empty uses the default chat. |
| `parse_mode` | `HTML` is the default. `MarkdownV2` and an empty value are the alternatives. |
| `disable_notification` | Deliver without a sound. |
| `disable_web_page_preview` | Show no link previews. |

With HTML, Telegram shows the formatting of the template. With an empty parse mode, it gets the plain text. With MarkdownV2, it gets the plain text with every reserved character escaped, so no formatting survives.

Telegram rejects messages over 4096 characters. herald shortens a longer message at a paragraph break, closes any open HTML tag and adds a line that says how long the full message was. A MarkdownV2 message can still be cut inside its own formatting, and Telegram then rejects it.

### When a group becomes a supergroup

Telegram gives a group a new chat ID when it turns the group into a supergroup. Sends to the old ID then fail with the error `400 group chat was upgraded to a supergroup chat`. The new ID is in the error body under `parameters.migrate_to_chat_id`.

Replace the old ID everywhere it is stored:

1. The setting `herald_telegram_default_chat_id` and any entry in `herald_topics`.
2. Every CiviRules action that names it. Edit those actions in the CiviRules screens. CiviRules stores action parameters as serialised PHP, and a text replacement in SQL corrupts them because the string length changes.
3. Waiting alerts in the table `civicrm_herald_buffer`. Their `delivery` column is JSON.
4. Scheduled digest jobs. The chat ID is in `actionParams` in the job's JSON parameters.

Alerts that were waiting for aggregation stay in the buffer until a send succeeds. Once every copy of the ID is fixed, they go out by themselves. Alerts that were parked in the meantime need to be reactivated, see [Parked alerts](delivery.md#parked-alerts).

## Webhook

The webhook channel sends an HTTP request to a URL. The settings are `herald_webhook_default_url`, `herald_webhook_default_auth_token` and `herald_webhook_allowed_hosts`.

| Key | Meaning |
|---|---|
| `webhook_url` | Endpoint. Empty uses `herald_webhook_default_url`. |
| `webhook_method` | `POST` is the default, `PUT` the alternative. |
| `webhook_body_format` | How the body is built, see below. The default is `text`. |
| `webhook_body_key` | JSON key for the json format. The default is `text`. |
| `webhook_headers` | Extra request headers as a JSON object that maps names to text or numbers. Anything else fails the send. |
| `webhook_auth_token` | Sent as a bearer token in the Authorization header. An Authorization header in `webhook_headers` takes precedence. |

The body formats:

| Format | Request body |
|---|---|
| `text` | The plain text of the message |
| `json` | `{"text": "<the HTML message>"}`, or your own key from `webhook_body_key` |
| `slack` | `{"text": "<the plain text>"}` with the characters & < > escaped the way Slack expects |
| `discord` | `{"content": "<the plain text>"}` |

A Slack incoming webhook in an action's "Channel-specific config":

```json
{
  "webhook_url": "https://hooks.slack.com/services/T0/B0/secret",
  "webhook_body_format": "slack"
}
```

The default token belongs to the default URL. herald sends it only when the URL also comes from `herald_webhook_default_url`. A URL from an action, a route or a subscriber never receives it.

herald checks every URL before it sends:

- It must use http or https and contain no user name or password.
- By default it must not resolve to a private, reserved, link-local or cloud metadata address.
- Requests do not follow redirects, so give the final URL.

To lock delivery to known services, list their host names under Administer » Herald » Webhook allowed hosts, one per line. Saving rejects an invalid host name and any entry with `https://`, a path or a port. With cv, set `herald_webhook_allowed_hosts`, for example `["hooks.slack.com"]`. Subdomains of a listed host are allowed too. Once the list is not empty, only those hosts are reached, and they may be internal addresses.

## ntfy

The settings are `herald_ntfy_server`, `herald_ntfy_default_topic` and `herald_ntfy_token`. The server defaults to `https://ntfy.sh`. The token is only needed for protected topics and is sent as a bearer token.

| Key | Meaning |
|---|---|
| `ntfy_topic` | Topic. Empty uses `herald_ntfy_default_topic`. |
| `ntfy_title` | Notification title. |
| `ntfy_priority` | 1 for the lowest to 5 for the highest, or one of ntfy's priority names. |
| `ntfy_tags` | Comma-separated tags. Emoji tag names show as emoji. |
| `ntfy_click` | URL that opens when the notification is tapped. |
| `ntfy_markdown` | Ask ntfy to render the body as Markdown. |

The body is the plain text of the message. Title, priority, tags and the click URL travel as ntfy headers.

## Pushover

The settings are `herald_pushover_app_token`, which is required, and `herald_pushover_default_user_key`. Create the app token at pushover.net.

| Key | Meaning |
|---|---|
| `pushover_user_key` | User or group key. Empty uses the default key. |
| `pushover_title` | Notification title. |
| `pushover_priority` | From -2 for silent to 2 for emergency. |
| `pushover_retry` | Emergency only: seconds between repeats. The default is 60, the minimum 30. |
| `pushover_expire` | Emergency only: seconds until the repeats stop. The default is 3600, the maximum 10800. |
| `pushover_sound` | A sound name from the [Pushover sound list](https://pushover.net/api#sounds). |
| `pushover_url` | A link shown under the message. |
| `pushover_url_title` | The label of that link. |
| `pushover_html` | Send the HTML message instead of plain text. Pushover supports a small subset of HTML. |

Pushover requires retry and expire for emergency messages. herald fills in the defaults when you leave them out, and moves values outside the limits back inside.

## Email

The email channel sends through CiviCRM's outbound mailer. Your SMTP settings, mail logging and any development redirect apply.

The settings are `herald_email_from` and `herald_email_default_to`. The From address takes the form `Name <address>`. When it is empty, herald uses the site's default From address.

| Key | Meaning |
|---|---|
| `email_to` | Recipient. Empty uses `herald_email_default_to`. |
| `email_to_name` | Recipient display name. |
| `email_subject` | Subject. Without it, actions, topics and digests use the template's subject. The last fallback is "Notification". |
| `email_format` | `html` is the default and keeps the template's line breaks. `text` sends plain text. |
| `email_cc` | Comma-separated CC addresses. |
| `email_bcc` | Comma-separated BCC addresses. |

An aggregated message gets the count line as its subject, for example "📬 7 messages, total 385.00 € (collected over 5 min)". When herald sends to contacts rather than fixed addresses, it uses each contact's own address and skips contacts marked "Do not email". See [Delivery modes](delivery.md#delivery-modes).

## Activity

The activity channel records a notification in CiviCRM as an activity and assigns it to a contact. It needs no external service and no settings.

| Key | Meaning |
|---|---|
| `activity_assignee_contact_id` | Assignee. Required. |
| `activity_source_contact_id` | The "Added by" contact. Defaults to the assignee. |
| `activity_target_contact_id` | The contact the activity is about. Optional. |
| `activity_type` | Activity type name. The default `herald_notification` ships with herald. |
| `activity_status` | Activity status name. The default is `Scheduled`. |
| `activity_subject` | Subject. Defaults to the start of the message, up to 255 characters. |
| `activity_format` | `html` is the default, `text` the alternative. |

Type and status are option names, not numbers, and must be active on the site.

## Test message

Administer » Herald » Test message sends a real message. "A channel" sends through one channel to the recipient and options entered on the screen; empty fields fall back to the defaults on the settings screen. "A topic route" publishes through a route, the way a real alert travels. A route that aggregates buffers the message until the next flush, and the screen says so.

Input errors are listed on the screen. A delivery error from the channel itself is shown in full to users with the permission "view debug output". Others see an error ID, and the CiviCRM log holds the message under that ID.

With cv, `Herald.send` and `Herald.publish` do the same:

```bash
cv api4 Herald.send channel=ntfy message='herald works' config='{"ntfy_topic": "my-topic"}'
cv api4 Herald.publish topic=ops message='herald works'
```

Administer » Herald » Template preview renders a herald template with sample values given as a JSON object and sends nothing, like `Herald.renderTemplate`.

## Errors

These are the configuration errors the channels raise. In a direct delivery they reach the caller, which is the API result or the CiviRules log. In aggregated deliveries they are stored with the waiting alert, see [Parked alerts](delivery.md#parked-alerts).

| Error | Fix |
|---|---|
| Telegram bot token is not configured | Set `herald_telegram_bot_token`. |
| No chat_id supplied and no default chat ID is configured | Set a chat ID on the action or `herald_telegram_default_chat_id`. |
| Telegram API returned HTTP 400 | The error body says why. A wrong chat ID, a bot that is not in the chat and a [supergroup upgrade](#when-a-group-becomes-a-supergroup) are the usual causes. |
| webhook channel requires webhook_url | Set a URL on the action or `herald_webhook_default_url`. |
| webhook host is not in herald_webhook_allowed_hosts | Add the host to the list or use an allowed URL. |
| webhook host resolves to a disallowed address | The URL points to a private or internal address. Add the host to `herald_webhook_allowed_hosts` if that is intended. |
| webhook URL must use http or https | Fix the URL. |
| webhook URL must not contain credentials | Remove the user name and password from the URL. Use `webhook_auth_token` or `webhook_headers` instead. |
| webhook_headers must map header names to text or numbers | Write the headers as a JSON object of names and values, for example `{"X-Source": "civicrm"}`. `true`, `false`, lists and nested objects are not header values. |
| could not resolve webhook host | Check the host name and the server's DNS. |
| ntfy channel requires ntfy_topic | Set a topic on the action or `herald_ntfy_default_topic`. |
| Pushover app token not configured | Set `herald_pushover_app_token`. |
| Pushover user/group key required | Set a key on the action or `herald_pushover_default_user_key`. |
| email channel requires email_to | Set a recipient address on the action or `herald_email_default_to`. |
| email delivery failed | CiviCRM's mailer refused the message. Check the outbound mail settings. |
| activity channel requires activity_assignee_contact_id | Set an assignee. |
| activity channel option does not exist | The activity type or status name is wrong or disabled. |
| activity_format must be "html" or "text" | Fix the format. |
| Channel is not registered | The channel key is misspelled. |

## Adding a channel

Channels live in herald's code. Implement `Civi\Herald\Channel\ChannelInterface` and register the class in `ChannelRegistry::default()`.

`getConfigSchema()` declares the channel's keys. `Herald.getChannels` and the list `unknown_config_keys` read it. `getSemanticFields()` maps the shared subject and format onto the channel's keys, so callers need not hard-code them. The action forms have dedicated fields only for Telegram, so the keys of a new channel go into "Channel-specific config".

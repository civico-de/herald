---
title: Channels
order: 20
routes:
  - civicrm/admin/setting/herald
  - civicrm/admin/herald
---

# Channels

A channel is a named, configured way to deliver a message: one Telegram bot, one Slack webhook, one ntfy server, one Pushover app, one email sender or CiviCRM activities. Actions, topics and API calls name the channel, for example `ops_bot`, never a token. A site can have several channels of one type, such as two Telegram bots.

| Type | Delivers to | The channel holds |
|---|---|---|
| `telegram` | A Telegram chat or group, through a bot | Bot token |
| `webhook` | An HTTP endpoint such as Slack, Discord, Mattermost or your own | Headers and token for its URL |
| `ntfy` | An ntfy topic on ntfy.sh or your own server | Server and token |
| `pushover` | Pushover users or groups | App token |
| `email` | An email address, through CiviCRM's outbound mailer | From address |
| `activity` | An assigned CiviCRM activity | Nothing |

## Configuring a channel

Administer » Herald » Channels lists the channels, their type and who uses them. "Add channel" asks for:

- the type, which cannot change later;
- the name that actions, topics and calls use: lowercase letters, digits and `_`, starting with a letter. It cannot change later either;
- a title for lists and forms;
- whether the channel is active. Sends through an inactive channel fail;
- whether contacts may choose it as their own channel. Only telegram, webhook, ntfy, pushover and email channels can be offered to contacts, see [Subscribers](delivery.md#subscribers);
- the account fields of the type, such as the bot token;
- defaults for the delivery fields, such as a chat ID or an email subject. A subject rendered from a template or digest replaces the default subject. A delivery that leaves a field empty uses the channel's default. Default recipients, such as a chat ID or a cc address, apply to direct deliveries only; subscribers, group members and each address of an address list get a message of their own.

Tokens, keys and headers are stored encrypted, with CiviCRM's credential key. The screen and `Herald.getChannels` never show them, only whether one is set. Leaving a secret field empty on save keeps the stored value; the checkbox "Remove the stored value of …" clears it, unless a new value is typed into the field.

A fresh install has one channel, `email`. The setting "Contact email channel" on the settings screen names the email channel that sends to contacts who have no channel of their own.

With cv:

```bash
cv api4 Herald.saveChannel name=ops_bot isNew=1 \
  'channel={"type":"telegram","label":"Ops bot","config":{"bot_token":"<bot-token>","chat_id":"-100123"}}'
cv api4 Herald.getChannels
cv api4 Herald.deleteChannel name=ops_bot
```

A channel that a topic, a rule action, a scheduled job, a waiting or parked alert or the contact email setting still names cannot be deleted. The error lists them. Contacts who chose a deleted channel as theirs are skipped by subscriber deliveries and get email in group deliveries until they choose another; the confirmation shows how many there are.

![The list of channels](images/channels.png)

![Adding an email channel](images/channel-form.png)

### Account fields and delivery fields

Each type has two kinds of keys. Account fields, such as `bot_token` or `ntfy_server`, belong to the channel only. A delivery that sets one fails: a topic or rule action is not saved ("… belong to the channel, not to a delivery; set them under Channels."), and a call fails with "… belong to the channel configuration, not to a delivery". Delivery fields, such as `chat_id` or `ntfy_priority`, can be set on the channel as defaults and on each delivery. Where a delivery sets them depends on the caller:

- A CiviRules action shows the delivery fields of the chosen channel's type: the recipients in the form, the presentation fields under "Advanced". A field left empty, or a yes/no field on "- channel default -", keeps the channel's value; a chosen 0 or "No" overrides it.
- A topic takes them in its entry.
- `Herald.send` takes them in `config`.

To list the keys of each type, with labels, types and whether they belong to the account:

```bash
cv api4 Herald.getChannelTypes type=ntfy
```

`Herald.send` passes unknown keys through unchanged and lists them in its result as `unknown_config_keys`. A typo shows up there and does not stop the delivery.

`Herald.send` also accepts a subject and a format that work on every channel. herald maps the subject to the email subject, the ntfy title, the Pushover title or the activity subject. It maps the format, text or html, to the email and activity format. Channels that have no such field ignore both. Actions and topics do not have these shortcuts and use the channel's own keys, such as `email_subject`.

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

Create a bot with @BotFather and store its token in the channel's `bot_token`. Group chats have negative IDs. A person can find their own chat ID with @userinfobot.

| Key | Meaning |
|---|---|
| `bot_token` | Account: the bot's token. Required. |
| `chat_id` | Recipient chat or group. Empty uses the channel's default. |
| `parse_mode` | `HTML` is the default. `MarkdownV2` and `text` (plain text) are the alternatives. |
| `disable_notification` | Deliver without a sound. |
| `disable_web_page_preview` | Show no link previews. |

With HTML, Telegram shows the formatting of the template. With `text`, it gets the plain text. With MarkdownV2, it gets the plain text with every reserved character escaped, so no formatting survives.

Telegram rejects messages over 4096 characters. herald shortens a longer message at a paragraph break, closes any open HTML tag and adds a line that says how long the full message was. A MarkdownV2 message can still be cut inside its own formatting, and Telegram then rejects it.

### When a group becomes a supergroup

Telegram gives a group a new chat ID when it turns the group into a supergroup. Sends to the old ID then fail with the error `400 group chat was upgraded to a supergroup chat`. The new ID is in the error body under `parameters.migrate_to_chat_id`.

Replace the old ID everywhere it is stored:

1. The default chat of the channel, on Administer » Herald » Channels, and any entry in `herald_topics`.
2. Every CiviRules action that names it. Edit those actions in the CiviRules screens. CiviRules stores action parameters as serialised PHP, and a text replacement in SQL corrupts them because the string length changes.
3. Waiting alerts in the table `civicrm_herald_buffer`. Their `delivery` column is JSON.
4. Scheduled digest jobs. The chat ID is in `actionParams` in the job's JSON parameters.

Alerts that were waiting for aggregation stay in the buffer until a send succeeds. Once every copy of the ID is fixed, they go out by themselves. Alerts that were parked in the meantime need to be reactivated, see [Parked alerts](delivery.md#parked-alerts).

## Webhook

The webhook channel sends an HTTP request to a URL. The URL is a delivery field: a channel can hold a default URL, and an action, a topic or a subscriber can name its own.

| Key | Meaning |
|---|---|
| `webhook_url` | Endpoint. Empty uses the channel's default URL. |
| `webhook_method` | `POST` is the default, `PUT` the alternative. |
| `webhook_body_format` | How the body is built, see below. The default is `text`. |
| `webhook_body_key` | JSON key for the json format. The default is `text`. |
| `webhook_headers` | Account: extra request headers as a JSON object that maps names to text or numbers. Anything else fails the save. |
| `webhook_auth_token` | Account: sent as a bearer token in the Authorization header. An Authorization header in `webhook_headers` takes precedence. |

The body formats:

| Format | Request body |
|---|---|
| `text` | The plain text of the message |
| `json` | `{"text": "<the HTML message>"}`, or your own key from `webhook_body_key` |
| `slack` | `{"text": "<the plain text>"}` with the characters & < > escaped the way Slack expects |
| `discord` | `{"content": "<the plain text>"}` |

A Slack incoming webhook as a channel:

```bash
cv api4 Herald.saveChannel name=slack isNew=1 \
  'channel={"type":"webhook","label":"Slack #ops","config":{"webhook_url":"https://hooks.slack.com/services/T0/B0/secret","webhook_body_format":"slack"}}'
```

Headers and token belong to the channel's default URL. herald sends them only when the delivery uses that URL. A delivery that names another URL, such as a subscriber's own, never receives them.

herald checks every URL before it sends:

- It must use http or https and contain no user name or password.
- By default it must not resolve to a private, reserved, link-local or cloud metadata address.
- Requests do not follow redirects, so give the final URL.

To lock delivery to known services, list their host names under Administer » Herald » Webhook allowed hosts, one per line. Saving rejects an invalid host name and any entry with `https://`, a path or a port. With cv, set `herald_webhook_allowed_hosts`, for example `["hooks.slack.com"]`. Subdomains of a listed host are allowed too. Once the list has entries, herald reaches only those hosts, and they may resolve to internal addresses.

![The allowed hosts for webhooks](images/hosts.png)

## ntfy

The server is an account field and defaults to `https://ntfy.sh`. The token is only needed for protected topics and is sent as a bearer token. herald does not check the server against the webhook host list: it is set by an administrator, and a self-hosted ntfy often runs on an internal address.

| Key | Meaning |
|---|---|
| `ntfy_server` | Account: the server. |
| `ntfy_token` | Account: access token for protected topics. |
| `ntfy_topic` | Topic. Empty uses the channel's default. |
| `ntfy_title` | Notification title. Without it, the subject of the template or digest. |
| `ntfy_priority` | 1 for the lowest to 5 for the highest, or one of ntfy's priority names. |
| `ntfy_tags` | Comma-separated tags. Emoji tag names show as emoji. |
| `ntfy_click` | URL that opens when the notification is tapped. |
| `ntfy_markdown` | Ask ntfy to render the body as Markdown. |

The body is the plain text of the message. Title, priority, tags and the click URL travel as ntfy headers.

## Pushover

Create the app token at pushover.net and store it in the channel.

| Key | Meaning |
|---|---|
| `pushover_app_token` | Account: the app token. Required. |
| `pushover_user_key` | User or group key. Empty uses the channel's default. |
| `pushover_title` | Notification title. Without it, the subject of the template or digest. |
| `pushover_priority` | From -2 for silent to 2 for emergency. |
| `pushover_retry` | Emergency only: seconds between repeats. The default is 60, the minimum 30. |
| `pushover_expire` | Emergency only: seconds until the repeats stop. The default is 3600, the maximum 10800. |
| `pushover_sound` | A sound name from the [Pushover sound list](https://pushover.net/api#sounds). |
| `pushover_url` | A link shown under the message. |
| `pushover_url_title` | The label of that link. |
| `pushover_html` | Send the HTML message instead of plain text. Pushover supports a small subset of HTML. |

Pushover requires retry and expire for emergency messages. herald fills in the defaults when you leave them out, and sets values outside the limits to the nearest limit.

## Email

The email channel sends through CiviCRM's outbound mailer. Your SMTP settings, mail logging and any development redirect apply.

The From address is an account field and takes the form `Name <address>`. When it is empty, herald uses the site's default From address. Several email channels can send with different From addresses.

| Key | Meaning |
|---|---|
| `email_from` | Account: the From address. |
| `email_to` | Recipient. Empty uses the channel's default. |
| `email_to_name` | Recipient display name. |
| `email_subject` | Subject. Without it, actions and topics use the template's subject. Digests always use their own. The last fallback is "Notification". |
| `email_format` | `html` is the default and keeps the template's line breaks. `text` sends plain text. |
| `email_cc` | Comma-separated CC addresses. |
| `email_bcc` | Comma-separated BCC addresses. |

An aggregated message gets the count line as its subject, for example "📬 7 messages, total 385.00 € (collected over 5 min)". When herald sends to contacts rather than fixed addresses, it uses each contact's own address and skips contacts marked "Do not email". See [Delivery modes](delivery.md#delivery-modes).

## Activity

The activity channel records a notification in CiviCRM as an activity and assigns it to a contact. It needs no external service and no account fields.

| Key | Meaning |
|---|---|
| `activity_assignee_contact_id` | Assignee. Required. |
| `activity_source_contact_id` | The "Added by" contact. Defaults to the assignee. |
| `activity_target_contact_id` | The contact the activity is about. Optional. |
| `activity_type` | Activity type name. The default `herald_notification` ships with herald. |
| `activity_status` | Activity status name. The default is `Scheduled`. |
| `activity_subject` | Subject. Without it, the subject of the template or digest, else the start of the message, up to 255 characters. |
| `activity_format` | `html` is the default, `text` the alternative. |

Type and status are option names, not numbers, and must be active on the site.

## Test message

Administer » Herald » Test message sends a real message. "A channel" sends through one configured channel, with the target and options you enter on the screen. Empty fields fall back to the channel's defaults. "A topic" publishes to a topic, as a real alert would. If the topic aggregates, it buffers the message until the next flush, and the screen says so.

Input errors are listed on the screen. A delivery error from the channel itself is shown in full to users with the permission "view debug output". Others see an error ID, and the CiviCRM log holds the message under that ID.

With cv, `Herald.send` and `Herald.publish` do the same:

```bash
cv api4 Herald.send channel=ntfy message='herald works' config='{"ntfy_topic": "my-topic"}'
cv api4 Herald.send channel=telegram message='<b>herald works</b>'
cv api4 Herald.publish topic=ops message='herald works'
```

Administer » Herald » Template preview renders a herald template with sample values given as a JSON object and sends nothing, like `Herald.renderTemplate`.

![A test message to the subscribers of a topic](images/test.png)

![A template rendered with sample values](images/preview.png)

## Errors

These are the configuration errors the channels raise. In a direct delivery they reach the caller, which is the API result or the CiviRules log. In aggregated deliveries they are stored with the waiting alert, see [Parked alerts](delivery.md#parked-alerts).

| Error | Fix |
|---|---|
| there is no channel "…" | The delivery names a channel that does not exist. Check the name on Administer » Herald » Channels. |
| the channel "…" is switched off | Activate the channel or name another one. |
| the delivery names no channel | Choose a channel on the action, the topic or the call. |
| the topic "…" names no channel | Give the topic or the default topic a channel. |
| … belong to the channel configuration, not to a delivery | Move the key, such as `bot_token`, from the action, topic or call to the channel. |
| the Telegram channel has no bot_token | Set the bot token on the channel. |
| no chat_id, neither on the delivery nor on the channel | Set a chat ID on the action or a default chat on the channel. |
| Telegram API returned HTTP 400 | The error body says why. A wrong chat ID, a bot that is not in the chat and a [supergroup upgrade](#when-a-group-becomes-a-supergroup) are the usual causes. |
| no webhook_url, neither on the delivery nor on the channel | Set a URL on the action or a default URL on the channel. |
| webhook host is not in herald_webhook_allowed_hosts | Add the host to the list or use an allowed URL. |
| webhook host resolves to a disallowed address | The URL points to a private or internal address. Add the host to `herald_webhook_allowed_hosts` if that is intended. |
| webhook URL must use http or https | Fix the URL. |
| webhook URL must not contain credentials | Remove the user name and password from the URL. Use `webhook_auth_token` or `webhook_headers` instead. |
| webhook_headers must map header names to text or numbers | Write the headers as a JSON object of names and values, for example `{"X-Source": "civicrm"}`. `true`, `false`, `null`, lists and nested objects are not header values. |
| could not resolve webhook host | Check the host name and the server's DNS. |
| no ntfy_topic, neither on the delivery nor on the channel | Set an ntfy topic on the action or a default ntfy topic on the channel. |
| the Pushover channel has no pushover_app_token | Set the app token on the channel. |
| no pushover_user_key, neither on the delivery nor on the channel | Set a key on the action or a default key on the channel. |
| no email_to, neither on the delivery nor on the channel | Set a recipient address on the action or a default on the channel. |
| address lists go through an email channel | The delivery mode "addresses" needs a channel of type email. |
| email delivery failed | CiviCRM's mailer refused the message. Check the outbound mail settings. |
| activity channel requires activity_assignee_contact_id | Set an assignee. |
| activity channel option does not exist | The activity type or status name is wrong or disabled. |
| activity_format must be "html" or "text" | Fix the format. |
| Channel "…" is not registered | The channel's type is no longer available, for example after code was removed. |

## Adding a channel type

Channel types live in herald's code. Implement `Civi\Herald\Channel\ChannelInterface` and register the class in `ChannelRegistry::default()`.

`getConfigSchema()` declares the type's keys and marks each as an account field (`scope: channel`), a secret, a recipient or bound to another key (`bound_to`). `Herald.getChannelTypes` lists them; the channel screen, the action forms and `Herald.send` build on that list. `getSemanticFields()` maps the shared subject and format onto the type's keys, so callers need not hard-code them.

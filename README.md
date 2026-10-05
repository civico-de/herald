# herald

A notification and digest layer for CiviCRM. Other extensions, CiviRules and scripts hand
herald a message or a template. herald renders it and delivers it through whichever channel
the site configured.

- Channels: Telegram, generic webhook (Slack, Discord, Mattermost, custom), ntfy.sh,
  Pushover, email through CiviCRM's outbound mailer, and internal CiviCRM activities.
  Others plug in through `Civi\Herald\Channel\ChannelInterface`.
- Templates: `MessageTemplate` workflows, rendered as Smarty strings. Admins can edit
  them, but a herald release that changes a shipped template overwrites the edits to it.
- CiviRules: three actions (Send notification, Send contribution digest, Send contribution
  alert) and three conditions (Previous contribution status, Field transition, Event was
  deactivated) that any CiviRules trigger can use. No bespoke cron.
- Topics: publishers name a topic, the `herald_topics` setting decides where it lands.
- Alert aggregation, a check monitor with push alerts for degraded states, and per-contact
  notification preferences with subscriber fan-out.

Requires CiviRules (`org.civicoop.civirules`). Proprietary, © civico GmbH.

## Install

```bash
cv ext:enable herald
```

Configure only the channels you actually use:

```bash
# Telegram
cv setting:set herald_telegram_bot_token=<bot-token>
cv setting:set herald_telegram_default_chat_id=<chat-id>

# Webhook (URL also configurable per action)
cv setting:set herald_webhook_default_url=<https://hooks.slack.com/...>
cv setting:set herald_webhook_default_auth_token=<bearer-token>   # optional
cv setting:set herald_webhook_allowed_hosts='["hooks.slack.com"]'  # optional allowlist, subdomains match

# ntfy.sh (or self-hosted)
cv setting:set herald_ntfy_server=https://ntfy.sh                  # default
cv setting:set herald_ntfy_default_topic=<topic>
cv setting:set herald_ntfy_token=<bearer-token>                    # optional, for protected topics

# Pushover
cv setting:set herald_pushover_app_token=<app-token>
cv setting:set herald_pushover_default_user_key=<user-or-group-key>

# Email (no credentials; both optional)
cv setting:set herald_email_from='"Herald" <noreply@example.org>'   # empty → site default
cv setting:set herald_email_default_to=<fallback-recipient>
```

When `herald_webhook_allowed_hosts` is empty, webhooks fall back to a denylist of private
and reserved addresses.

## Smoke test

```bash
# Send a real test message through the configured Telegram channel
cv api4 Herald.testTelegram message='<b>herald smoke test</b>'

# Render a workflow template without sending
cv api4 Herald.renderTemplate workflow=herald_contribution_alert_completed \
    vars='{"contribution":{"total_amount":42.5,"source":"Stripe"}}'

# Keep an operational notification inside CiviCRM as an assigned task
cv api4 Herald.send channel=activity message='Three bank transactions need review' \
    subject='Review bank import' format=text \
    config='{"activity_assignee_contact_id":42,"activity_target_contact_id":84}'

# Publish to a topic; the herald_topics setting decides the recipient
cv api4 Herald.publish +v 'topic=ops' +v 'message=disk almost full'
```

`cv scr bin/test-telegram.php` does the same as `Herald.testTelegram` without the API.
`HERALD_TEST_MESSAGE` and `HERALD_TEST_CHAT_ID` override its defaults.

## APIv4

| Action | Does |
|---|---|
| `Herald.send` | Send a message or a rendered template through one channel |
| `Herald.publish` | Publish to a topic, routed by `herald_topics` |
| `Herald.notify`, `Herald.notifyContribution`, `Herald.notifyDigest` | What the CiviRules actions call |
| `Herald.checkContributionTransition`, `Herald.checkFieldTransition`, `Herald.checkEventDeactivated` | What the CiviRules conditions call |
| `Herald.getChannels` | Registered channels and their config schema |
| `Herald.getParkedAlerts`, `Herald.reactivateParkedAlerts` | Buffered alerts that could not be delivered |
| `Herald.testTelegram`, `Herald.renderTemplate` | Smoke test and preview |

Two scheduled jobs ship managed: "Herald: Flush alert aggregation buffer"
(`Job.herald_buffer_flush`) and the check monitor (`Job.herald_monitor`).

## Configuration

[CONFIGURATION.md](CONFIGURATION.md) has the recipes and the reference: CiviRules rule
sets for donation alerts and digests, event lifecycle notifications, subscriber mode, the
per-channel parameter tables, alert aggregation, topics, digest sections from other
extensions, the check monitor, and every action parameter.

## Self-service notification preferences

Each contact can pick their preferred channel and the kinds of notifications they want,
through the custom group `herald_preferences` that ships with the extension. Admins fill
these in from the contact view. For self-service, point staff at a profile that exposes
the same fields:

```bash
cv scr /var/www/html/ext/herald/bin/install-profile.php
# → prints a URL like /civicrm/profile/edit?gid=42&reset=1
```

Send users that URL. CiviCRM resolves it to the logged-in contact and only allows editing
their own record. When a CiviRules action's `delivery_mode` is `subscribers` and a
`subscription_type` is picked, the action queries all contacts subscribed to that type and
delivers through each contact's own channel and recipient.

## Deploy to production

herald's CiviRules actions run in the write path of every entity they fire on, so a
handler class that cannot load breaks that write. Wrap the code swap in these steps, each
script run with `cv scr`, and stop at the first non-zero exit:

| When | Step |
|---|---|
| Before the swap | `bin/rules-disable.php`, then `cv flush` |
| After the swap | `bin/preflight-rules.php` |
| After the preflight passed | `bin/rules-reenable.php` |
| Last | `bin/note-foreign-rules.php` |

`rules-disable.php` writes the ids of every active rule with a herald condition or action
to `herald_deploy_disabled_rules` in CiviCRM's private directory, then switches those
rules off. `preflight-rules.php` then resolves handler classes the way CiviRules does
(class exists, `new $class()` without arguments, right base class, post-trigger default
only for an object/op trigger without cron). It fails, exit code 1, for herald's
registered handlers and for every handler of the disabled rules whose trigger is active,
whether or not the handlers are active. The message names rule, kind, class and reason,
and the command that re-enables herald's rules after the fix. The rules stay disabled
until then. It notes on stderr the ids of disabled rules whose trigger is missing or
inactive: CiviRules never fires a rule without an active trigger, so none of its
handlers is checked.

`rules-reenable.php` re-activates the recorded rules and removes the file. A herald class
that cannot load at all fails earlier: `cv flush` and `cv ext:enable` exit on the PHP
fatal, naming class and file. Without a marker file the preflight gates only the
registered handlers. A marker left by a failed deploy re-enables its rules on the next
successful one, including a rule an admin switched off on purpose in between. While the
marker exists, CiviCRM's system status shows a warning naming the rules and the command.

`note-foreign-rules.php` runs after herald's rules are back on and only notes, on stderr,
broken handlers of the other active rules. It is the only step that loads their classes,
so a foreign class that is a PHP fatal on load (an unimplemented abstract method, an
incompatible signature) fails this last step and marks the deploy failed, but cannot keep
herald's alerts off.

## Development

The repo ships its own [civikitchen](https://github.com/jfilter/civikitchen) stack in
`tests/docker/`:

```bash
cd tests/docker && docker compose up -d           # CiviCRM on :8090, phpMyAdmin :8091, Maildev :1090
docker compose exec app cv ext:enable herald      # once per fresh DB, for manual poking around
docker compose exec -T app \
    bash -c "cd /var/www/html/ext/herald && vendor/bin/phpunit"
```

The compose file pulls `ghcr.io/jfilter/civikitchen:standalone` and auto-installs CiviCRM,
so the first `up` is a one-shot setup. The headless suite installs CiviRules itself. The
image runs `composer install` on bind-mounted extensions automatically, so
`vendor/bin/phpunit` is available without a manual composer step.

The phpunit run needs no env vars or prior `ext:enable`. The test bootstrap boots
`cv php:boot --test`, and the headless suite installs itself into the isolated
`civicrm_test` scratch DB created by `tests/docker/initdb/`. The dev site's data and
settings stay untouched. If your `db-data` volume predates the initdb script, recreate it
once with `docker compose down -v && docker compose up -d`.

CI uses the shared civikitchen layer in `.docker/` instead (ports 8080, 8081, 1080). Pure
unit tests without any CiviCRM checkout run the `@group unit` files with
`--no-configuration` and a bootstrap that stubs the CiviRules base classes.

## License

Proprietary. © civico GmbH. All rights reserved.

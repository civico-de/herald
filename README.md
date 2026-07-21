# herald

Generic notification + digest layer for CiviCRM.

- **Channel-pluggable** — Telegram, generic Webhook (Slack/Discord/Mattermost/custom), ntfy.sh, Pushover and Email ship in v0.1; others drop in via `Civi\Herald\Channel\ChannelInterface`.
- **Template-driven** — admin-editable `MessageTemplate` workflows, rendered as Smarty strings via `CRM_Core_Smarty`.
- **CiviRules-integrated** — exposes Actions ("Send Notification", "Send Contribution Digest", "Send Contribution Alert") that any CiviRules trigger can fire. No bespoke cron.

Status: **alpha (v0.1)** — feature-complete for the migration. Ships:

- Channels: Telegram, generic Webhook (Slack/Discord/Mattermost/custom), ntfy.sh, Pushover, Email (via CiviCRM’s outbound mailer)
- Three CiviRules actions: `Send Notification`, `Send Contribution Digest`, `Send Contribution Alert` (each with its own parameter form)
- Contribution digest engine (period filters, source/financial-type filters, adaptive histogram, campaign progress, pending pool)
- Custom CiviRules condition `Previous Contribution status` (uses a `hook_civicrm_pre` snapshot, independent of CiviRules core change-tracking)
- Per-contact notification preferences + subscriber-mode delivery (one rule fans out to all opted-in contacts via *their own* preferred channel)
- Workflow `MessageTemplate` fixtures shipped via mgd-php (admin-editable, user edits preserved on re-enable)

## Install

```bash
cv ext:download herald  # once published; for now: clone into /ext/
cv ext:enable herald
```

Configure via settings — only the channels you actually use:

```bash
# Telegram
cv setting:set herald_telegram_bot_token=<bot-token>
cv setting:set herald_telegram_default_chat_id=<chat-id>

# Webhook (Slack/Discord/Mattermost/custom — URL also configurable per-action)
cv setting:set herald_webhook_default_url=<https://hooks.slack.com/...>
cv setting:set herald_webhook_default_auth_token=<bearer-token>   # optional

# ntfy.sh (or self-hosted)
cv setting:set herald_ntfy_server=https://ntfy.sh                  # default
cv setting:set herald_ntfy_default_topic=<topic>
cv setting:set herald_ntfy_token=<bearer-token>                    # optional, for protected topics

# Pushover
cv setting:set herald_pushover_app_token=<app-token>
cv setting:set herald_pushover_default_user_key=<user-or-group-key>

# Email (no credentials — uses CiviCRM's outbound mailer; both settings optional)
cv setting:set herald_email_from='"Herald" <noreply@example.org>'   # empty → site default
cv setting:set herald_email_default_to=<fallback-recipient>
```

## Smoke test

Cleanest path uses the bundled APIv4 actions:

```bash
# Send a real test message through the configured channel
cv api4 Herald.testTelegram message='<b>herald smoke test</b>'

# Render a workflow template without sending — useful for previewing
cv api4 Herald.renderTemplate workflow=herald_contribution_alert_completed \
    vars='{"contribution":{"total_amount":42.5,"source":"Stripe"}}'
```

Or as a PHP script (no API entity, equivalent functionality):

```bash
cv scr /var/www/html/ext/herald/bin/test-telegram.php
# optional overrides via env: HERALD_TEST_MESSAGE, HERALD_TEST_CHAT_ID
```

## Deploy to production

```bash
./deploy.sh             # tar repo, ship to production, extract into docker volume, cv ext:enable
./deploy.sh --reinstall # disable + uninstall first (forces clean install)
```

## Configuration

See [CONFIGURATION.md](CONFIGURATION.md) for end-to-end CiviRules recipes (migration, generic NGO, major-donor campaign, subscriber-mode self-service).

## Self-service: per-contact notification preferences

Each contact can pick *their* preferred channel (Telegram / Webhook / ntfy / Pushover / Email) and the kinds of notifications they want, via a CiviCRM custom group `herald_preferences` shipped with the extension. Admins fill these in from the contact view; for self-service, point staff at a CiviCRM profile that exposes the same fields.

```bash
# One-off after enabling the extension: create the user-facing profile
cv scr /var/www/html/ext/herald/bin/install-profile.php
# → prints a URL like /civicrm/profile/edit?gid=42&reset=1
```

Send users that URL — Civi auto-resolves to the logged-in contact and only allows editing their own record (`access CiviCRM` + standard own-contact ACL). When a CiviRules action's `delivery_mode` is set to `subscribers` and a `subscription_type` is picked, the action queries all contacts subscribed to that type and dispatches via each contact's preferred channel + recipient.

## Development

The repo ships its own [civikitchen](https://github.com/jfilter/civikitchen)-based stack at `tests/docker/` — no separate clone needed:

```bash
cd tests/docker && docker compose up -d           # CiviCRM on :8090, phpMyAdmin :8091, Maildev :1090
docker compose exec app cv ext:enable herald      # once per fresh DB (for manual poking around)
docker compose exec -T app \
    bash -c "cd /var/www/html/ext/herald && vendor/bin/phpunit"
```

The compose file pulls `ghcr.io/jfilter/civikitchen:standalone` and pre-enables CiviRules via `CIVICRM_EXTRA_EXTENSIONS`, so the first `up` is a one-shot setup. The image runs `composer install` on bind-mounted extensions automatically (`CIVICRM_AUTO_COMPOSER=1` by default), so `vendor/bin/phpunit` is available without a manual composer step.

The phpunit run needs no env vars or prior `ext:enable`: the test bootstrap boots `cv php:boot --test`, and the headless suite installs itself into the **isolated `civicrm_test` scratch DB** (created by `tests/docker/initdb/`) — the dev site's data and settings stay untouched. If your `db-data` volume predates the initdb script, recreate it once with `docker compose down -v && docker compose up -d`.

Pure unit tests without any CiviCRM checkout: run the `@group unit` files with `--no-configuration` and a bootstrap that stubs the CiviRules base classes (the repo config registers `Civi\Test\CiviTestListener`, which only exists inside a Civi install).

## License

Proprietary — © civico GmbH. All rights reserved.

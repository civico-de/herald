# civico_notify

Generic notification + digest layer for CiviCRM.

- **Channel-pluggable** — Telegram in v0.1; Slack/Discord/Email designed to drop in via `Civi\CivicoNotify\Channel\ChannelInterface`.
- **Template-driven** — admin-editable `MessageTemplate` workflows, rendered with `CRM_Core_TokenSmarty`.
- **CiviRules-integrated** — exposes Actions ("Send Notification", "Send Contribution Digest", "Send Contribution Alert") that any CiviRules trigger can fire. No bespoke cron.

Status: **alpha (v0.1)** — Phase 1 scaffolding only. CiviRules actions and digest engine arrive in subsequent phases (see `wir-haben-diesem-telegram-sunny-coral.md` plan).

## Install

```bash
cv ext:download civico_notify  # once published; for now: clone into /ext/
cv ext:enable civico_notify
```

Configure via:

```bash
cv setting:set civico_notify_telegram_bot_token=<bot-token>
cv setting:set civico_notify_telegram_default_chat_id=<chat-id>
```

## Smoke test

Cleanest path uses the bundled APIv4 actions:

```bash
# Send a real test message through the configured channel
cv api4 CivicoNotify.testTelegram message='<b>civico_notify smoke test</b>'

# Render a workflow template without sending — useful for previewing
cv api4 CivicoNotify.renderTemplate workflow=civico_notify_contribution_alert_completed \
    vars='{"contribution":{"total_amount":42.5,"source":"Stripe"}}'
```

Or as a PHP script (no API entity, equivalent functionality):

```bash
cv scr /var/www/html/ext/civico_notify/bin/test-telegram.php
```

## Deploy to production

```bash
./deploy.sh             # tar repo, ship to production, extract into docker volume, cv ext:enable
./deploy.sh --reinstall # disable + uninstall first (forces clean install)
```

## Configuration

See [CONFIGURATION.md](CONFIGURATION.md) for end-to-end CiviRules recipes (migration, generic NGO, major-donor campaign).

## Development

This repository follows the civix layout used by sibling extensions in this project. The standalone dev setup uses [civikitchen](https://github.com/jfilter/civikitchen):

```bash
cd ~/code/civikitchen/examples/standalone
docker compose up -d
docker compose exec app cv ext:enable civico_notify
docker compose exec -e CIVICRM_UF=UnitTests app \
    bash -c "cd /var/www/html/ext/civico_notify && phpunit"
```

## License

AGPL-3.0

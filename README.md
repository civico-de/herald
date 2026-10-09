# herald

A notification and digest layer for CiviCRM. CiviRules rules, scheduled jobs, other
extensions and scripts hand herald a message or a template; herald renders it and
delivers it through the channel the site configured.

- **Channels:** Telegram, generic webhook (Slack, Discord, Mattermost, custom), ntfy,
  Pushover, email through CiviCRM's outbound mailer, and assigned CiviCRM activities.
  A new channel implements `Civi\Herald\Channel\ChannelInterface`.
- **Templates:** `MessageTemplate` workflows rendered as Smarty strings. 17 ship with the
  extension, for contributions, tickets, events, memberships, group signups, digests and
  the check monitor.
- **CiviRules:** two actions (send notification, send contribution digest) and one
  condition (field transition: a field's value before and after an edit), fired by
  CiviRules triggers instead of a bespoke cron. On a Contribution trigger the
  notification also carries the contribution, its campaign's progress and the status
  change.
- **Delivery:** to one recipient, to every contact subscribed to a notification kind,
  to the members of a group, or to a list of addresses. Alerts can be aggregated into one
  message per time window.
- **Topics:** publishers name a topic such as `ops`; the `herald_topics` setting decides
  channel and recipient.
- **Check monitor:** extensions contribute health checks; herald reports the ok → fail and
  fail → ok transitions and reminds about checks that keep failing.

Requires CiviRules (`org.civicoop.civirules`). Supported CiviCRM and PHP versions are
declared in `info.xml`.

## Install

```bash
cv ext:enable herald
```

Then configure the channels you use, on the admin screen `civicrm/admin/setting/herald`
or with `cv setting:set`. Array settings such as topic routes have no form field. The
full list is in [docs/reference.md](docs/reference.md#settings).

```bash
# Telegram
cv setting:set herald_telegram_bot_token=<bot-token>
cv setting:set herald_telegram_default_chat_id=<chat-id>

# Webhook
cv setting:set herald_webhook_default_url=<https://hooks.slack.com/...>
cv setting:set herald_webhook_default_auth_token=<bearer-token>      # optional
cv setting:set herald_webhook_allowed_hosts='["hooks.slack.com"]'     # optional allowlist

# ntfy (public ntfy.sh or self-hosted)
cv setting:set herald_ntfy_server=https://ntfy.sh                     # default
cv setting:set herald_ntfy_default_topic=<topic>
cv setting:set herald_ntfy_token=<bearer-token>                       # protected topics only

# Pushover
cv setting:set herald_pushover_app_token=<app-token>
cv setting:set herald_pushover_default_user_key=<user-or-group-key>

# Email: no credentials, both optional
cv setting:set herald_email_from='Herald <noreply@example.org>'       # empty → site default
cv setting:set herald_email_default_to=<fallback-recipient>
```

The activity channel needs no settings.

## Smoke test

```bash
# A real message through the configured Telegram channel
cv api4 Herald.testTelegram message='<b>herald smoke test</b>'

# Render a workflow template without sending it
cv api4 Herald.renderTemplate workflow=herald_contribution_alert_completed \
    vars='{"contribution":{"total_amount":42.5,"source":"Stripe"}}'

# A pre-rendered message through one channel
cv api4 Herald.send channel=activity message='Three bank transactions need review' \
    subject='Review bank import' format=text \
    config='{"activity_assignee_contact_id":42}'

# Publish to a topic; herald_topics decides where it lands
cv api4 Herald.publish topic=ops message='disk almost full'
```

`cv scr bin/test-telegram.php` does what `Herald.testTelegram` does, without the API;
`HERALD_TEST_MESSAGE` and `HERALD_TEST_CHAT_ID` override its defaults.

## First rule

A Telegram message for every completed contribution:

1. Administer → CiviRules → New rule, trigger **Contribution is added**.
2. Condition **Contribution Status is (not) one of**: Completed.
3. Action **Herald: Send notification (generic)**, template "Herald: New completed
   contribution", channel Telegram. Leave the chat ID empty to use the default.

[docs/recipes.md](docs/recipes.md) builds on this: bank-transfer matching, ticket sales
with aggregation, event lifecycle alerts, signups and scheduled digests.

## Documentation

| Document | Covers |
|---|---|
| [docs/recipes.md](docs/recipes.md) | End-to-end CiviRules setups and scheduled digests |
| [docs/channels.md](docs/channels.md) | Every channel: settings, per-call keys, limits, troubleshooting |
| [docs/delivery.md](docs/delivery.md) | Delivery modes, subscriber preferences, topics, aggregation, parked alerts |
| [docs/digests.md](docs/digests.md) | Contribution digests: parameters, composed digests, extra sections, source normalisation |
| [docs/monitor.md](docs/monitor.md) | Check monitor: contributing checks, tuning, herald's own checks |
| [docs/reference.md](docs/reference.md) | CiviRules actions and conditions, APIv4, jobs, templates and their variables, settings, hooks |
| [docs/deploy.md](docs/deploy.md) | Deploying without breaking writes: the rule gate scripts |

## Development

The repo ships a [civikitchen](https://github.com/jfilter/civikitchen) stack in
`tests/docker/`:

```bash
cd tests/docker && docker compose up -d           # CiviCRM :8090, phpMyAdmin :8091, Maildev :1090
docker compose exec app cv ext:enable herald      # once per fresh DB, for manual testing
docker compose exec -T app \
    bash -c "cd /var/www/html/ext/herald && vendor/bin/phpunit"
```

The first `up` installs CiviCRM and CiviRules and runs `composer install` on the mounted
extension. The test run needs no `ext:enable` and no environment variables: the headless
suite installs itself into the separate `civicrm_test` database that
`tests/docker/initdb/` creates, so the dev site stays untouched. The bootstrap refuses to
run unless cv's configuration points the tests at a `*_test` database; the civikitchen
stack provides that. A `db-data` volume older than that init script needs one
`docker compose down -v`.

CI uses the shared civikitchen layer in `.docker/` (ports 8080, 8081, 1080). The
`@group unit` tests also run without any CiviCRM checkout, with `--no-configuration` and
a bootstrap that stubs the CiviRules base classes.

## License

AGPL-3.0-or-later. © civico GmbH. See [LICENSE.txt](LICENSE.txt).

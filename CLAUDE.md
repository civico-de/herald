# herald

Channel-pluggable notification + digest layer for CiviCRM, fired via CiviRules
actions (no bespoke cron for alerts). Live in production on production.

## Contracts to preserve

- Scope boundary: herald is transport and monitor framework, not a source of
  health checks. Checks themselves belong in `pulse` (database/cron/system/
  updates/jobs/disk/queues/payments); herald consumes them via
  `hook_civicrm_heraldChecks` and only reports ok↔fail transitions.
- Channels implement `Civi\Herald\Channel\ChannelInterface` and register in
  `ChannelRegistry`. `getConfigSchema()` is the single source of truth for a
  channel's config keys; `getSemanticFields()` maps the channel-agnostic
  `subject`/`format` semantics onto them — callers must never hardcode
  per-channel key names.
- Subscriber mode forwards only presentation options from the rule; recipient
  and credential keys (`chat_id`, `webhook_url`, tokens, `email_to`, …) come
  exclusively from each contact's own `herald_preferences`. One bad recipient
  logs a warning and must not block the fan-out loop.
- Aggregation (`aggregate_minutes`) parks alerts in `civicrm_herald_buffer`;
  `Job.herald_buffer_flush` drains a rule's alerts only once its oldest
  buffered alert exceeds the window. A single buffered alert is sent unchanged.
- An aggregate prints at most `herald_buffer_max_items` bodies and names the
  rest; a body still over a channel's hard limit is shortened by the channel
  (`Civi\Herald\Channel\MessageLimit`, Telegram: 4096). Neither may silently
  drop an alert — the count in the header and the marker in the message stay
  truthful.
- Buffered alerts are never dropped and never retried forever: a flush that
  resolves to no recipient parks the group immediately, other failures after
  `herald_buffer_max_attempts` tries (`Civi\Herald\Buffer\DeadLetter`). Parked
  rows stay in the table, are listed by `Herald.getParkedAlerts` and only
  `Herald.reactivateParkedAlerts` puts them back in circulation.
- The monitor publishes only ok→fail / fail→ok transitions to the
  `herald_monitor_topic` topic; steady states never alert. Topic routing:
  publishers name a topic, `herald_topics` decides delivery (topic entry
  merged over `default`) — publishers never carry chat IDs.
- MessageTemplate fixtures ship via mgd-php. MessageTemplate is not an APIv4
  ManagedEntity, so `update => unmodified` falls back to `always`: changing a
  shipped template overwrites admin edits to it on the next reconcile. Admin
  edits to unchanged templates survive. Templates render as Smarty strings via
  `CRM_Core_Smarty`.
- Rendered bodies are HTML: string vars are HTML-escaped into `msg_html`
  (after undoing core's `&lt;`/`&gt;` input encoding). Sinks that do not
  render HTML get `Channel\PlainText::fromHtml()` of the body, never the raw
  markup.
- The `Previous Contribution status` condition relies on the
  `hook_civicrm_pre` snapshot (`PreEditSnapshot`), not CiviRules change data.
  The generic `Field transition` condition uses the same model; the fields it
  watches are read from active rule configs (`FieldTransitionWatchList`,
  request-cached) and snapshotted in `hook_civicrm_pre`. The request cache
  means long-running queue/CLI workers keep a stale watch list until restart —
  accepted limitation.
- CiviRules integration is an adapter layer: everything inheriting from a
  CiviRules base class lives in `CRM/Herald/CivirulesAdapter/` and does nothing
  but unpack `TriggerData` and call `Herald.notify*` / `Herald.check*` via
  APIv4. Domain logic belongs in `Civi\Herald\*`; phpstan's phpat rule fails
  the build on any CiviRules reference outside that directory.
- Handler class names live in `civirule_action.class_name`, so renaming one is
  a data migration — the manifests plus
  `CRM_Herald_CivirulesAdapter_Registrar::migrateRenamedClasses()`.

## Verify

```bash
cd tests/docker && docker compose up -d
docker compose exec -T app bash -c "cd /var/www/html/ext/herald && vendor/bin/phpunit"
```

Headless tests self-install into the isolated `civicrm_test` DB — no prior
`ext:enable`, no env vars. If the `db-data` volume predates
`tests/docker/initdb/`, run `docker compose down -v` once. Pure `@group unit`
tests can run without any Civi checkout (`--no-configuration` plus a stub
bootstrap). Smoke-test real sends with `cv api4 Herald.testTelegram` /
`Herald.renderTemplate` (see README). End-to-end CiviRules recipes and
per-channel field tables live in `CONFIGURATION.md`.

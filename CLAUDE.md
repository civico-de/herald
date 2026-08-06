# herald

Channel-pluggable notification + digest layer for CiviCRM, fired via CiviRules
actions (no bespoke cron for alerts). Live in production on production.

## Contracts to preserve

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
- The monitor publishes only ok→fail / fail→ok transitions to the
  `herald_monitor_topic` topic; steady states never alert. Topic routing:
  publishers name a topic, `herald_topics` decides delivery (topic entry
  merged over `default`) — publishers never carry chat IDs.
- MessageTemplate fixtures ship via mgd-php; admin edits must survive
  re-enable. Templates render as Smarty strings via `CRM_Core_Smarty`.
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

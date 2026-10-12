# herald

Channel-pluggable notification + digest layer for CiviCRM, fired via CiviRules
actions (no bespoke cron for alerts). Live in production.

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
  rows stay in the table, are listed by `Herald.getParkedAlerts` and the
  SearchKit display on the read-only `HeraldBufferedAlert` entity (never
  `body`/`delivery` — contact data), and only
  `Herald.reactivateParkedAlerts` puts them back in circulation. The table's
  shape lives in `schema/`; upgrade steps add columns from that spec.
  Uninstall drops the table (AutomaticUpgrader) — the one sanctioned loss.
- The monitor publishes only ok→fail / fail→ok transitions to the
  `herald_monitor_topic` topic; steady states never alert. Topic routing:
  publishers name a topic, `herald_topics` decides delivery (topic entry
  merged over `default`) — publishers never carry chat IDs.
- MessageTemplate fixtures ship via mgd-php. MessageTemplate is not an APIv4
  ManagedEntity, so `update => unmodified` falls back to `always`: changing a
  shipped template overwrites admin edits to it on the next reconcile. Admin
  edits to unchanged templates survive. Templates render as Smarty strings via
  `CRM_Core_Smarty`.
- Every body a channel gets is HTML: string vars are HTML-escaped into
  `msg_html` (after undoing core's `&lt;`/`&gt;` input encoding), and plain
  text (a `msg_text`-only template, `Herald.send`/`publish` with
  `format=text`) is escaped whole via `PlainText::toHtml()`. Sinks that do not
  render HTML get `Channel\PlainText::fromHtml()` of the body, never the raw
  markup; email and activity HTML get newlines as `<br>` (`HtmlLineBreaks`).
- herald ships one condition, `Field transition`, and one per-event action,
  `Send notification`; entity specifics (contribution, campaign progress,
  status names) are template variables in `Civi\Herald\Trigger\*`, not extra
  handlers; the third action, `Send digest`, composes blocks. Retired handlers
  move onto these via
  `CRM_Herald_CivirulesAdapter_RetiredHandlers::migrate()` (upgrade and
  reconcile). The pre-edit value comes from CiviRules original data, else the
  `hook_civicrm_pre` snapshot (`PreEditSnapshot`); the fields it
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
- A class that fails to load (e.g. an unimplemented abstract method) is a
  PHP fatal: the scan-classes mixin loads every `CRM/` and `Civi/` file on a
  cold cache, so it 500s every web request, `cv flush` and the headless test
  boot fail on it, and CiviRules fatals the write that fires its rule.
  `RuleContractTest` checks each registered handler against
  `HandlerContract`. On deploy, `bin/preflight-rules.php` (`DeployGate::run`)
  fails for herald's handlers and those of the rules `bin/rules-disable.php`
  switched off; `bin/note-foreign-rules.php` (`DeployGate::noteOthers`) only
  notes broken handlers of other active rules and runs after
  `bin/rules-reenable.php`, because loading a foreign class can be a fatal.
  `DisabledRulesCheck` warns while the marker exists and reads only that file.
  Every registered handler needs a contract scenario, with full and partial payloads.
- A digest is a list of blocks (`Civi\Herald\Digest\Composer`): contribution,
  saved search, hook section. Search blocks run the SavedSearch's `api_params`
  without permission checks — whoever configures a digest can report any search,
  the same trust as the contribution queries. Scheduled digests are APIv4 jobs
  of `Herald.notifyDigest` (`"checkPermissions": false`, `sendHour`); there is
  no APIv3 job wrapper.
- Post-hook payloads of partial updates carry only the written fields; code
  reading an entity payload completes it from the stored row.
- Bundled packages (`civico-de/stdlib`) are prefixed into `Civi\Herald\Vendor\`
  by Strauss as composer's post-install script (`.civikitchen/strauss.php`).
  `vendor/` is not committed; `herald.php` requires `vendor/autoload.php`, so
  CI, release and deploy all run `composer install`. Packages core ships
  (Guzzle, PSR-7) stay under `provide`: a bundled copy would replace core's
  for every extension.

## Verify

```bash
../civikitchen/scaffold/ckup          # template stack in .docker/, free host ports
../civikitchen/scaffold/ckx ck ci     # every CI gate, as CI runs them
```

Headless tests self-install into the isolated `civicrm_test` DB — no prior
`ext:enable`, no env vars. Smoke-test real sends with `cv api4 Herald.testTelegram` /
`Herald.renderTemplate` (see README). User docs live in `docs/`
(recipes, channels, delivery, digests, monitor, reference, deploy); keep them
in step with code changes.

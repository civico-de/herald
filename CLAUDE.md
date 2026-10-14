# herald

Channel-pluggable notification + digest layer for CiviCRM, fired via CiviRules
actions (no bespoke cron for alerts). CiviRules is optional, not in `<requires>`.

## Contracts to preserve

- Scope boundary: herald is transport and monitor framework, not a source of
  health checks. Other extensions contribute checks via
  `hook_civicrm_heraldChecks`; herald only reports ok↔fail transitions.
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
  SearchKit display on the `HeraldBufferedAlert` entity (never
  `body`/`delivery` — contact data), and only
  `Herald.reactivateParkedAlerts` puts them back in circulation (the display's
  task calls it through `HeraldBufferedAlert.reactivate`, the one write action
  a user may call; update/delete are `ALWAYS_DENY` and serve
  `Civi\Herald\Buffer` with checkPermissions off). `body` and `delivery` are
  written and read only through the BAO: APIv4 encodes `<`/`>` on write and
  decodes every `&lt;`/`&gt;` on read, turning escaped text into markup. An
  APIv4 update reads before it writes, so the flush job's
  `AlertBuffer::LOCK_NAME` lock is what keeps two runs from claiming one
  group. The table's shape lives in `schema/`; upgrade steps add columns from
  that spec.
  Uninstall drops every `schema/` table (AutomaticUpgrader), buffer and
  digests — the one sanctioned loss.
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
- herald ships one trigger, `Schedule`, one condition, `Field transition`,
  and one per-event action, `Send notification`; entity specifics (contribution, campaign progress,
  status names) are template variables in `Civi\Herald\Trigger\*`, not extra
  handlers; the third action, `Send digest`, sends a digest record. Retired handlers
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
  the build on a `CRM_Civirules_*` dependency outside that directory. Reading
  CiviRules data through `Civi\Api4\CiviRules*` is allowed elsewhere, behind
  `Availability::isInstalled()`. The deploy scripts switch rules with SQL: an
  APIv4 update would mark another extension's managed rule as modified.
- CiviRules is optional. herald needs 3.36 or later, whose API unserializes
  rule params before HTML-decoding them; `CiviRulesVersionCheck` reports an
  older one. Every adapter class sits behind a
  `// ckconform-optional civirules` `class_exists()` guard, because the
  scan-classes mixin loads each file and a missing parent is a fatal
  (`WithoutCiviRulesTest`). Handlers register as managed entities in
  `managed/CiviRules.mgd.php`, declared only while CiviRules is installed, so
  installing it later registers them on its reconcile; cleanup `always`, as
  CiviRules prescribes, and `is_active` declared so a re-enable undoes core's
  disable. That cleanup cascades to rule conditions, so `uninstall()` first
  switches off the rules using a herald handler (`DependentRules`). `RuleHandlers::registered()` reads that file, and the
  deploy scripts note a missing CiviRules and succeed. phpstan scans the dev
  profile's CiviRules checkout; its runtime aliases are stubbed in `phpstan-stubs/`.
- Handler class names live in `civirule_action.class_name`, so renaming one is
  a data migration — `managed/CiviRules.mgd.php` plus
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
- A digest is a `HeraldDigest` record: a user-driven message template plus
  `HeraldDigestSource` rows (`Definition` loads, `Composer` renders). A source
  is a SearchKit display (`SearchSource`: values of the first row by column
  header slug, every row under `rows`, SUM/COUNT of no rows 0) or a hook section
  (`ExtraSections`: values only, never text, checked by `SectionRules`). herald
  has no period and no contribution logic of its own: periods and filters live
  in the displays, the shipped ones in `managed/DigestSearches.mgd.php` (displays `update => never`: their headers are placeholder names, a reconcile in another locale must not rename them), and
  formatting in the template (`herald_histogram` modifier). Rules, jobs and
  scripts name a digest by `name`; their params carry only delivery settings,
  never `email_subject` (the subject is the template's). Checks run on APIv4
  validate (`Validator`: `DigestRules`/`SourceRules` per diff), on Afform submit
  (`FormValidator`, which also composes, because Afform swallows save
  exceptions), on template edits that would break a digest using it
  (`TemplateValidator`, APIv4 and the legacy form) and again at send. Search
  displays run with `acl_bypass` forced on — whoever configures a digest can
  report any display. Every herald template renders under core's
  `UserContentPolicy`.
- Upgrade 1011 moves inline digests (0.10.0 and older, params with blocks) into
  records: `CRM_Herald_Upgrader_InlineDigest::extract()` writes a template
  (`InlineTemplate`, the form's snippets) and sources (`InlineBlock`,
  `InlineSources`; shipped searches, else `SearchCopy` copies with the old
  period and filters; line-item blocks total on items, `LineItemSearch::totals`), then composes; a digest that cannot compose throws, the
  caller's transaction rolls back and it stays inline with a warning. Rule
  actions go through `InlineDigestActions` on the CiviRules tables, so a
  disabled or pre-3.36 CiviRules and retired handlers are covered; jobs through
  `InlineDigestJobs`, parsed as the job manager parses them. Old params are read
  as the old code read them (unknown periods are yesterday).
- Scheduled digests are rules on the cron trigger "Herald: Schedule" (`Herald.checkSchedule`): a slot fires
  once, when the rule log has no run since it and it is not older than the
  rule's `created_date` or the trigger's `since` — never `modified_date`, which
  the deploy's rule disable/re-enable touches. CiviRules stamps `log_date` and
  `created_date` with PHP's wall clock, so they are read back in PHP's
  timezone. `Herald.notifyDigest` with `sendHour` stays for scripts and
  crontab; there is no APIv3 job wrapper.
- The admin screens (`civicrm/admin/herald`, Angular module `heraldAdmin`)
  edit the array settings through validating `Herald.save*` actions that store
  exactly the shape `Setting.set` takes; Setting.set stays unchecked. Channel
  fields are rendered from `Herald.getChannels`, never listed per channel.
  New UI is Angular/SearchKit/Afform: phpat and mago forbid QuickForm/page bases
  outside the CiviRules adapter forms.
- The digest form (`afformHeraldDigest`) works around Afform: the
  `heraldDigestSource` directive fills schema defaults into new sources only
  (`afform_default` overwrites loaded join rows), `heraldDigestSourceType`
  shows type fields per repeat row (`af-if` cannot see its own row),
  `heraldDigestPlaceholders` and `heraldDigestPreview` call
  `Herald.getDigestPlaceholders`/`previewDigest` with the unsaved form values,
  and `FormValidator` takes the edited id from `Submit::getArgs()`, as Afform
  drops the main entity's id from the submitted values. The preview iframe
  stays in the DOM and gets `srcdoc` set directly: an interpolated `srcdoc`
  inside `ng-if` stays unrendered.
- Post-hook payloads of partial updates carry only the written fields; code
  reading an entity payload completes it from the stored row.
- Bundled packages (`civico-de/stdlib`) are prefixed into `Civi\Herald\Vendor\`
  by Strauss as composer's post-install script (`.civikitchen/strauss.php`).
  `vendor/` is not committed; `herald.php` requires `vendor/autoload.php`, so
  CI, release and deploy all run `composer install`. Packages core ships
  (Guzzle, PSR-7) stay under `provide`: a bundled copy would replace core's
  for every extension.

## Verify

`composer install` first (vendor/ is not committed), then in the civikitchen
image:

```bash
cd .docker && docker compose up -d
docker compose exec -w /var/www/html/ext/herald app ck ci   # every CI gate, as CI runs them
```

With a civikitchen checkout alongside, `../civikitchen/scaffold/ckup` and
`ckx ck ci` do the same on free host ports.

Headless tests self-install into the isolated `civicrm_test` DB — no prior
`ext:enable`, no env vars. Smoke-test real sends with `cv api4 Herald.testTelegram` /
`Herald.renderTemplate` (see README). User docs live in `handbook/`
(recipes, channels, delivery, digests, monitor, reference, deploy); keep them
in step with code changes.

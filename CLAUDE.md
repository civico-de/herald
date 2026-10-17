# herald

Channel-pluggable notification and digest layer for CiviCRM, fired through
CiviRules (optional, not in `<requires>`). User docs live in `handbook/`; keep
them in step with code changes.

## Contracts

Lint, phpat and the contract tests enforce most structure. These are the rules
they cannot see:

- **Scope:** herald is transport and monitor, not a source of checks. Other
  extensions contribute via `hook_civicrm_heraldChecks` and
  `hook_civicrm_heraldDigestSections`. The monitor publishes only ok↔fail
  transitions.
- **Channels:** `getConfigSchema()` is the only list of a channel's keys, and
  `getSemanticFields()` maps `subject`/`format` onto them. Never hardcode a
  per-channel key name: a rendered subject travels as `ChannelSubject::KEY`
  and `ChannelSubject::forChannel()` names it at send time. Admin screens
  render channel fields from `Herald.getChannels`.
- **Recipients:** publishers name a topic, never a chat ID (`herald_topics`
  decides). In subscriber mode, recipient and credential keys come only from
  the contact's `herald_preferences`. One bad recipient must not stop the
  fan-out.
- **HTML:** every body a channel gets is HTML. Vars are escaped into it, and a
  sink without HTML gets `PlainText::fromHtml()`, never raw markup.
- **APIv4 encodes `<`/`>`** on write and decodes them on read for every field
  not on core's skip list. Digest text is therefore named
  `msg_subject`/`msg_html`. Buffer `body`/`delivery` go only through the BAO.
- **Never lose an alert:**
  - Aggregates and Telegram's 4096 limit shorten a message, but the counts
    and markers stay truthful.
  - Buffered alerts park after `herald_buffer_max_attempts` (`DeadLetter`).
    Only `Herald.reactivateParkedAlerts` revives them.
  - The flush lock (`AlertBuffer::LOCK_NAME`) prevents double claims.
  - Uninstall dropping the `schema/` tables is the one sanctioned loss.
- **Shipped MessageTemplates** fall back to `update => always`: changing one
  overwrites admin edits on reconcile. Shipped digest displays are
  `update => never`, because their headers are placeholder names.
- **Digests:**
  - A digest is a `HeraldDigest` (subject and text) plus SearchKit or section
    sources.
  - Periods and filters live in the displays, formatting in the text.
  - Rule and job params carry only delivery settings, never a channel's
    subject key (`email_subject`, `activity_subject`, …).
  - Displays run with `acl_bypass`.
  - `FormValidator` also composes the digest, because Afform swallows save
    exceptions.
- **CiviRules adapter:**
  - Everything extending a CiviRules class lives in
    `CRM/Herald/CivirulesAdapter/` behind a
    `// ckconform-optional civirules` `class_exists()` guard. A missing
    parent class is a fatal on every request.
  - Handler class names are stored in CiviRules tables, so a rename is a data
    migration (`Registrar::migrateRenamedClasses()`).
  - Deploy scripts switch rules with SQL, so another extension's managed rule
    is not marked modified.
- **Schedule:** a slot fires once, after `created_date` or `since`, never
  `modified_date` (the deploy touches it). CiviRules dates are PHP wall clock.
  `Herald.notifyDigest` with `daily` claims the day in `SendWindow` (locked,
  read fresh) before composing, so a failed digest is not retried that day.
- **Payloads:** post-hook payloads of partial updates carry only the written
  fields, so complete them from the stored row.
- **Vendor:** `civico-de/stdlib` is Strauss-prefixed into
  `Civi\Herald\Vendor\`, and `vendor/` is not committed. Guzzle and PSR-7 stay
  under `provide`.

## Verify

`composer install` first, then in the civikitchen image:

```bash
cd .docker && docker compose up -d
docker compose exec -w /var/www/html/ext/herald app ck ci   # every CI gate
```

Headless tests self-install into `civicrm_test`. Smoke-test real sends with
`cv api4 Herald.testTelegram` / `Herald.renderTemplate` (see README).

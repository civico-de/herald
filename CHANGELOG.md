# Changelog

All notable changes to herald are recorded here. The format follows Keep a Changelog and the
project uses Semantic Versioning.

## [Unreleased]

## [0.16.0] - 2026-11-06

### Added

- System check `herald_digest_jobs` lists active digest jobs that cannot send their digest: no
  digest, a missing digest, or a topic delivery that cannot resolve.

## [0.15.1] - 2026-11-05

### Changed

- Internal maintenance; no change for sites.

## [0.15.0] - 2026-11-04

### Fixed

- The check monitor writes its state only when it changes; the upgrade deletes the copies of that
  state that detailed logging had accumulated.

## [0.14.0] - 2026-10-27

### Added

- The activity channel can name its assignee by external identifier.

## [0.13.0] - 2026-10-26

### Added

- Named channels: every delivery goes through a configured, named channel.
- Contacts can subscribe to topics, and on several channels; notification preferences show as a
  contact tab and are edited in one FormBuilder form.

### Changed

- Contacts subscribe to topics instead of subscription types; the upgrade rewrites existing
  subscriptions.
- The check monitor reads `System.check`, and its page shows threshold labels and site-formatted
  dates with readable text contrast.
- Admin screens follow core's admin console layout and split everyday from advanced settings; the
  send action form shows only the fields of the chosen delivery mode.
- Digest placeholders are named after the stored headers in every language.
- A field transition is judged by the value the edit wrote.

### Removed

- `hook_civicrm_heraldChecks`; checks are read from `System.check`.

### Fixed

- The Telegram bot token no longer appears in the error of a failed request.

## [0.12.0] - 2026-10-20

### Added

- Cleaned free-text fields (`herald_clean_<field>`) built from ordered rules, usable for SearchKit
  grouping and in notifications; they replace the contribution source normalisation, whose rules
  the upgrade migrates.
- Send a digest as a test email to the logged-in user.

### Changed

- A digest keeps its own subject and text and composes them with bars and charts.
- A daily digest is sent once a day from its send hour, and empty digests are skipped.

### Fixed

- Uninstall deletes the rules on herald's triggers, which CiviRules otherwise refuses to drop.

## [0.11.0] - 2026-10-14

### Added

- Digests are `HeraldDigest` records edited under Administer » Herald » Digests, built from a
  message template and SearchKit display sources; the upgrade migrates inline digests and rule
  actions.
- CiviRules trigger "Herald: Schedule" for scheduled digests.
- Admin screens for every setting that was only reachable through the CLI or API.
- Field transition conditions accept option suffixes such as `:name` and are checked on save.
- User handbook in English and German.

### Changed

- CiviRules is an optional dependency; Afform is required.
- PHP 8.2 is supported; development stage is beta.
- Numbers, Money and Float values compare exactly, and empty, NULL and empty-list values are
  treated as the same value.
- Trigger entities and CiviRules data are read through APIv4; CiviRules 3.36 or later is needed
  when it is installed.
- Unknown delivery modes, operators, channel configs and webhook headers fail instead of being
  dropped silently.

### Fixed

- CiviRules rules stay safe across herald uninstall and re-enable.
- A delayed recheck sees the option value from before the edit.

## [0.10.0] - 2026-10-12

### Added

- The self-service preferences profile is created on install and upgrade.

## [0.9.0] - 2026-10-12

### Added

- Herald settings admin screen.
- Digests composed from contribution, saved-search and section blocks.
- The alert buffer is a schema-defined entity with a SearchKit list of parked alerts.
- Event cancellation alert template and participant status variable.
- Deploys are gated on the health of herald's rule handlers, and a system check warns while
  herald's rules are disabled.

### Changed

- Licensed under AGPL-3.0-or-later.
- Requires CiviCRM 6.18 and SearchKit.
- The contribution alert and the specific conditions fold into the generic handlers; the upgrade
  migrates existing rules.
- Template variables are escaped in HTML bodies, and sinks that do not render HTML receive plain
  text.
- Interface strings are English throughout, with German translations.

### Removed

- The cached rule-health system check and its setting.

### Fixed

- Partial post-hook payloads are completed from the stored row.
- FieldTransition compares values as stored strings, so booleans match.
- Digest histogram period, campaign filters and job subject.
- The monitor job run fails when a transition could not be published.

## [0.8.1] - 2026-09-02

### Added

- `herald_buffer_max_items` caps how many alerts an aggregated message prints.

### Fixed

- Registration with CiviRules works when both are installed in the same call.
- Telegram bodies over the 4096-character limit are shortened instead of rejected.
- Parked alerts no longer fail the buffer backlog check forever.
- The alert buffer stays out of CiviCRM detailed logging.

## [0.8.0] - 2026-08-24

### Added

- CiviCRM activity delivery channel.

### Fixed

- Webhook DNS handling.

## [0.7.0] - 2026-08-14

### Added

- Group and address delivery modes, suspension, location-type options and source-entity-scoped
  subscriptions.
- Generic field-transition CiviRules condition.
- Undeliverable buffered alerts are parked in a dead letter instead of retried forever.

### Changed

- Test contributions are excluded from digests.

### Security

- The webhook channel guards against SSRF with a private-range denylist and a host allowlist.

### Fixed

- Contact snapshot mapping, recipient deduplication, on-hold email fallback and group ID
  validation.
- Upgrade path, flush retry on total failure and numeric comparison in transition conditions.

## [0.6.0] - 2026-08-04

### Added

- Event lifecycle notifications with generic event variables and templates.
- `hook_civicrm_heraldDigestSections` for digest sections from other extensions.
- Digest `group_by=line_item` breakdown, `send_hour` gate and `force` parameter.
- System check reports rule handler classes that cannot load.

### Changed

- The CiviRules integration is reduced to thin adapters over APIv4.

### Fixed

- The Previous Contribution status condition decodes its parameters and was always false before.
- Detection of deactivated events.
- The monitor no longer announces failures while the current observation is OK.

## [0.5.0] - 2026-07-23

### Changed

- The check monitor keeps crash-safe incremental state with locking, hysteresis, renotify, snooze
  and missing-check detection.

## [0.4.0] - 2026-07-23

### Added

- Buffer backlog self-check, membership and participant trigger variables, and masked membership
  and event templates.
- English source strings with a German translation for all message output.

### Changed

- Aggregate sums are formatted in the site locale and default currency.

## [0.3.0] - 2026-07-23

### Added

- Generic topics (`Herald.publish`) and a check monitor framework.
- Group subscription alert template with masked contact name and email.
- Ticket alerts split by payment path.

## [0.2.0] - 2026-07-22

### Added

- Alert aggregation buffer (`aggregate_minutes`) against notification spam.
- Scheduled contribution digest job with composable multi-block digests.
- Generic ticket and event contribution alert template.

### Fixed

- Digest blocks keep newlines and show an empty state.

## [0.1.0] - 2026-07-22

### Added

- First release: notifications through Telegram, webhook, ntfy, Pushover and email channels,
  rendered from admin-editable message templates.
- CiviRules actions to send notifications, contribution digests and contribution alerts, and a
  Previous Contribution status condition.
- Per-contact notification preferences and subscriber-mode delivery.
- `Herald.send` APIv4 action for other extensions.
- Optional contribution source normalisation for digests and alerts.

[Unreleased]: https://github.com/civico-de/herald/compare/v0.16.0...HEAD
[0.16.0]: https://github.com/civico-de/herald/compare/v0.15.1...v0.16.0
[0.15.1]: https://github.com/civico-de/herald/compare/v0.15.0...v0.15.1
[0.15.0]: https://github.com/civico-de/herald/compare/v0.14.0...v0.15.0
[0.14.0]: https://github.com/civico-de/herald/compare/v0.13.0...v0.14.0
[0.13.0]: https://github.com/civico-de/herald/compare/v0.12.0...v0.13.0
[0.12.0]: https://github.com/civico-de/herald/compare/v0.11.0...v0.12.0
[0.11.0]: https://github.com/civico-de/herald/compare/v0.10.0...v0.11.0
[0.10.0]: https://github.com/civico-de/herald/compare/v0.9.0...v0.10.0
[0.9.0]: https://github.com/civico-de/herald/compare/v0.8.1...v0.9.0
[0.8.1]: https://github.com/civico-de/herald/compare/v0.8.0...v0.8.1
[0.8.0]: https://github.com/civico-de/herald/compare/v0.7.0...v0.8.0
[0.7.0]: https://github.com/civico-de/herald/compare/v0.6.0...v0.7.0
[0.6.0]: https://github.com/civico-de/herald/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/civico-de/herald/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/civico-de/herald/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/civico-de/herald/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/civico-de/herald/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/civico-de/herald/releases/tag/v0.1.0

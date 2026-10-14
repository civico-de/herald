# Upstream bugs and pitfalls

This file lists behaviour in CiviCRM core and CiviRules that herald has to work around. Some of it is a bug and some of it works as designed but catches you out. Each entry says what happens, what herald does about it and where the code and test for it live. Versions refer to CiviCRM 6.19.

## CiviCRM core

### APIv4 turns escaped text into markup

On every write APIv4 replaces `<` and `>` with `&lt;` and `&gt;`. On every read it turns each `&lt;` and `&gt;` back (`CRM_Utils_API_HTMLInputCoder`, called from `FormattingUtil::formatWriteParams()` and `DAOGetAction`). `&` is left as it is. Once stored, a tag and escaped text look the same:

```
rendered   <b>Max &lt;Test&gt;</b>
stored     &lt;b&gt;Max &lt;Test&gt;&lt;/b&gt;
read back  <b>Max <Test></b>
```

The text the template escaped is now a tag. Core exempts its own HTML fields by name only (`msg_html`, `body_html`, `details` and a few dozen more), plus custom fields of type Memo. An extension cannot exempt a field of its own.

The alert buffer stores rendered HTML, so herald writes and reads `body` and `delivery` through the BAO (`writeRecord()` and `find()` in `Civi\Herald\Buffer\AlertBuffer`), which never runs the encoder. `HeraldBufferedAlert` has no `create` or `save` action, so no new row can go through APIv4 by mistake.

Test: `AlertBufferClaimDueTest::testEscapedTextSurvivesTheBufferUnchanged`. Status: core design, not reported.

CiviRules' serialized `condition_params` are safe from CiviRules 3.36 on, because the API unserializes them before it decodes. Older versions returned the string and decoded it, so a condition value containing `&lt;` broke the serialization. herald needs CiviRules 3.36 or later, and `CiviRulesVersionCheck` reports an older version in the system status.

### Stored values are half-encoded

The same encoder runs for forms and APIv3, so a contact name or campaign title can sit in the database as `Max &lt;Test&gt;`. Escaped once more for a template, it would show up as `&lt;Test&gt;`. `TemplateRenderer::decodeInput()` undoes the encoding before `PlainText::toHtml()` escapes the value. Status: core design.

### An APIv4 update marks a managed record as changed

Every APIv4 write to a ManagedEntity stamps `civicrm_managed.entity_modified_date` (`CRM_Core_BAO_Managed::on_hook_civicrm_post()`). After that, an extension that declares the record with `'update' => 'unmodified'` never updates it again. `CiviRulesRule` is a ManagedEntity.

The deploy scripts switch rules off before the code swap and on again afterwards. With APIv4, every managed rule of another extension that uses a herald handler would be frozen after one deploy. `bin/rules-disable.php` and `bin/rules-reenable.php` therefore set `is_active` with SQL. On uninstall, `CRM_Herald_CivirulesAdapter_DependentRules` uses APIv4 on purpose: a rule switched off for good should count as changed, so a reconcile does not switch it back on without its herald handler.

Test: `DeployGateTest::testTheDeployLeavesAManagedRuleUnmodified`. Status: core design.

### APIv4 get leaves out test, template and trashed rows

A plain `get` filters out test contributions, event templates and deleted contacts. They come back only when the `where` names a unique key with `=` or `IN` (`AbstractGetAction::isFetchByUniqueIdentifier()`). herald reads these entities by ID: the contribution status in `herald_civicrm_pre()`, the event in `EventVars`, the labels in `ContactVars::label()`.

Tests: `PreEditStatusTest`, `ContextVarsBuildTest::testAnEventTemplateAndATrashedContactAreFound`. Status: core design.

### The post hook only sees the fields that were written

`hook_civicrm_post`, and the CiviRules trigger data built from it, carry only the fields passed to the write. A partial update leaves out the field a condition wants to compare, and a missing value compared loosely (`null == 0`) makes a "became inactive" condition fire on unrelated edits. `Civi\Herald\PreEditSnapshot` stores the old value in `hook_civicrm_pre` for the fields active rules watch (`FieldTransitionWatchList`). The trigger variables in `Civi\Herald\Trigger\*` complete the entity from the stored row. Status: core design.

### scan-classes loads every class file

On a cold cache the scan-classes mixin loads every file under `CRM/` and `Civi/`. A class whose parent comes from an extension that is not installed is a fatal error on every request. Each class in `CRM/Herald/CivirulesAdapter/` that extends a CiviRules class sits behind a `class_exists()` guard.

Test: `WithoutCiviRulesTest`. Status: core design.

### Shipped message templates overwrite admin edits

MessageTemplate is not an APIv4 ManagedEntity, so `'update' => 'unmodified'` behaves like `'always'`. When a new herald version changes a shipped template, the next reconcile overwrites whatever an admin changed in it. Templates herald leaves alone keep their edits. There is no workaround. Status: core limitation, accepted.

### Search displays in auto mode store no columns

A display with `columnMode: auto` has no column list. `SearchDisplay::run` builds one at run time from `SearchDisplay::getDefault`. A digest block renders the rows itself, so `Civi\Herald\Digest\SearchRows::columns()` calls `getDefault` the same way. Status: no public API for it.

### Standalone tests can reach the main database

On Standalone, `SettingsManager::bootSettings()` builds `CIVICRM_DSN` from the `CIVICRM_DB_*` environment variables before `settings.php` applies `TEST_DB_DSN`. The headless tests would then run against the main database. `tests/phpunit/bootstrap.php` sets `CIVICRM_DB_NAME=civicrm_test` for the whole test process. Status: not reported.

## CiviRules

### Uninstall leaves rules active without their condition

CiviRules registers handlers as managed entities with cleanup `always`. When herald is uninstalled, the cleanup deletes its handlers, and the delete cascades to the rules' condition rows. A rule that used a herald condition stays active and fires without it. Before that, herald's `CRM_Herald_Upgrader::uninstall()` calls `DependentRules::disable()`, which switches off every rule that uses a herald trigger, condition or action, and logs their ids.

Test: `DependentRulesTest`. Status: not reported.

### Rule dates are in PHP time

CiviRules fills `log_date` and `created_date` with APIv4's "now", which PHP resolves in its own timezone. The MySQL session can run on a different fixed offset. `Herald.checkSchedule` reads both dates in PHP's timezone (`CheckSchedule::wallTime()`). It never uses `modified_date`, because the deploy touches it.

### A DAO class phpstan cannot see

`CRM_Civirules_DAO_RuleCondition` exists only through a `class_alias()` in a legacy CiviRules file, and the condition BAO still extends it. phpstan does not follow the alias, so `phpstan-stubs/` declares the class.

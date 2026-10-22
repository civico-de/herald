# Upstream bugs and pitfalls

This file lists behaviour in CiviCRM core, its Riverlea theme and CiviRules that herald has to work around. Some of it is a bug and some of it works as designed but catches you out. Each entry says what happens, what herald does about it and where the code and test for it live. Versions refer to CiviCRM 6.19.

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

Test: `AlertBufferClaimDueTest::testEscapedTextSurvivesTheBufferUnchanged`. Status: core design, not reported. A fix in core would be a field attribute, such as `'html' => TRUE` in the entity definition, that `formatWriteParams()`, `formatInputValue()` and the decode in `DAOGetAction` check next to the name list.

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

A display with `columnMode: auto` has no column list. `SearchDisplay::run` builds one at run time from `SearchDisplay::getDefault`. A digest source names its values after the columns, so `Civi\Herald\Digest\DisplayColumns` calls `getDefault` the same way. Status: no public API for it.

### SearchKit cannot show a relative date offset

APIv4 evaluates `['receive_date', '>=', 'today - 7 days']` correctly, but SearchKit shows the filter as an empty date field, with an error in the browser console, and filling it in replaces it. Core has no relative term for the seven days before today without today: `ending.week` includes today, and `previous_7.day` does not exist. The upgrade writes such periods as `DATEDIFF(CURDATE(), receive_date) BETWEEN 1 AND 7`, which SearchKit shows as "Days between two dates" and keeps on save (`CRM_Herald_Upgrader_SearchCopy`). Test: `InlineDigestTest::testTheLastSevenDaysCountWhatTheOldPeriodCounted`. Status: not reported.

### Component fields vanish from APIv4

A field whose schema names a component, such as `Contribution.campaign_id` with CiviCampaign, is unknown to APIv4 while that component is off: a search filtering on it fails with `Invalid field 'campaign_id'`. An old digest with a campaign filter therefore stays inline on a site without CiviCampaign. Test: `InlineDigestTest::testFiltersAndCampaignProgressBecomeCopies`. Status: core design.

### The template editor encodes comparison operators

CKEditor 4 stores `>` as `&gt;` and `&&` as `&amp;&amp;` in a message template, in source mode as well, and inside Smarty tags too. Smarty then fails on `{if $a > 0}`. Core's own templates fail the same way. `Civi\Herald\Digest\TemplateCheck` recognises an encoded operator in a tag after a compiler error and names `gt`, `lt` and `and` as the fix. Status: core design.

### Smarty warnings depend on the site

`CRM_Core_Smarty` sets `error_reporting` to `E_ALL` only with debugging on, on `localhost` and in unit tests; elsewhere the global setting applies, so a placeholder typo can render silently on production. `CRM_Utils_String::parseOneOffStringThroughSmarty()` installs a handler of its own that lets warnings pass to PHP. `TemplateCheck` therefore renders with `E_ALL` and a handler that turns warnings and notices into errors, and honours `error_reporting()` inside it, because Smarty silences its own writes of compiled templates. Status: core design.

### Standalone tests can reach the main database

On Standalone, `SettingsManager::bootSettings()` builds `CIVICRM_DSN` from the `CIVICRM_DB_*` environment variables before `settings.php` applies `TEST_DB_DSN`. The headless tests would then run against the main database. `tests/phpunit/bootstrap.php` sets `CIVICRM_DB_NAME=civicrm_test` for the whole test process. Status: not reported.

## Riverlea

herald's admin screens use the markup of Afform fields inside `#bootstrap-theme` (`form-group`, `crm-af-field-label`, `crm-af-field`, `crm-af-field-help-post`, `form-control`), so the theme styles them. The entries below fail WCAG AA in the Minetta stream. herald keeps the theme's markup and colours and does not patch them; they are to be fixed upstream. The numbers were measured with axe-core in light and dark mode.

### Dark buttons are too pale

In dark mode Minetta sets `--crm-primary-color` to `--crm-c-gray-500` (`#919297`) and the button text to white: 3.1:1, below 4.5:1. This hits `.btn-primary`, `.btn-secondary` and `.btn-default`. Setting `--crm-primary-color: #55565b` gives 7.3:1. Status: not reported.

### Required markers are too faint

`.crm-marker` uses `--crm-notify-danger-color`, which comes to 4.34:1 on white and 2.79:1 on the dark background. `#a4161a` (7.75:1) and `#ff8a80` (5.29:1) would pass. Status: not reported.

### Links in text are told apart by colour only

`--crm-link-decoration` is `none`, and the link colour differs from the body text by 1.94:1 in light mode and 1.34:1 in dark mode. WCAG 1.4.1 asks for 3:1 or a non-colour cue. Underlining links inside running text would fix it. Status: not reported.

### Hover buttons with an icon take the link colour

`.crm-container .crm-hover-button:has(.crm-i)` sets `color: var(--crm-link-color) !important`. On the form background (`#f4f4ed`) that is 4.45:1 in light mode, just below 4.5:1. This hits the channel-type toggles on herald's CiviRules send forms. Status: not reported.

## CiviRules

### Uninstall leaves rules active without their condition

CiviRules registers handlers as managed entities with cleanup `always`. When herald is uninstalled, the cleanup deletes its handlers, and the delete cascades to the rules' condition rows. A rule that used a herald condition stays active and fires without it. Before that, herald's `CRM_Herald_Upgrader::uninstall()` calls `DependentRules::disable()`, which switches off every rule that uses a herald trigger, condition or action, and logs their ids.

Test: `DependentRulesTest`. Status: not reported.

### Rule dates are in PHP time

CiviRules fills `log_date` and `created_date` with APIv4's "now", which PHP resolves in its own timezone. The MySQL session can run on a different fixed offset. `Herald.checkSchedule` reads both dates in PHP's timezone (`CheckSchedule::wallTime()`). It never uses `modified_date`, because the deploy touches it.

### A DAO class phpstan cannot see

`CRM_Civirules_DAO_RuleCondition` exists only through a `class_alias()` in a legacy CiviRules file, and the condition BAO still extends it. phpstan does not follow the alias, so `phpstan-stubs/` declares the class.

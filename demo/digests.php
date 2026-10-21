<?php

declare(strict_types = 1);

/**
 * Synthetic contributions, orders and activities for herald's digests, dated
 * relative to today, plus two demo digests and their schedule rules.
 *
 * Run inside a provisioned demo with:
 *   cv scr /var/www/html/ext/herald/demo/digests.php
 *
 * Each run replaces what the seed's own donors gave and did, so every period
 * of the shipped searches has data again. Digests and rules are created once
 * and left alone afterwards.
 */

use Civi\Api4\Activity;
use Civi\Api4\CiviRulesAction;
use Civi\Api4\CiviRulesRule;
use Civi\Api4\CiviRulesRuleAction;
use Civi\Api4\CiviRulesTrigger;
use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionPage;
use Civi\Api4\Herald;
use Civi\Api4\HeraldDigest;
use Civi\Api4\HeraldDigestSource;
use Civi\Api4\Order;
use Civi\Api4\PriceField;
use Civi\Api4\PriceFieldValue;
use Civi\Api4\PriceSet;
use Civi\Api4\SavedSearch;
use Civi\Api4\SearchDisplay;
use Civi\Herald\Cleaning\CleanedFields;
use Civi\Herald\Digest\ChartSnippet;
use Civi\Herald\Digest\SearchSource;
use Civi\Herald\Digest\Snippet;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

$donors = [];
foreach (range(1, 6) as $n) {
  $key = "herald-seed-donor-{$n}";
  $donors[] =
    Contact::get(checkPermissions: FALSE)
      ->addWhere('external_identifier', '=', $key)
      ->execute()
      ->first()['id']
    ?? Contact::create(checkPermissions: FALSE)
      ->setValues([
        'contact_type' => 'Individual',
        'first_name' => 'Demo',
        'last_name' => "Donor {$n}",
        'external_identifier' => $key,
      ])
      ->execute()
      ->single()['id'];
}

// The donors' records belong to the seed: replace them with a fresh set.
Contribution::delete(checkPermissions: FALSE)->addWhere('contact_id', 'IN', $donors)->execute();
// Payments are recorded by the acting user, so their activities name the donor only as target.
Activity::delete(checkPermissions: FALSE)
  ->addClause('OR', ['source_contact_id', 'IN', $donors], ['target_contact_id', 'CONTAINS ONE OF', $donors])
  ->execute();

$pages = [];
foreach (['Autumn appeal', 'Online donation'] as $title) {
  $pages[] =
    ContributionPage::get(checkPermissions: FALSE)
      ->addWhere('title', '=', $title)
      ->execute()
      ->first()['id']
    ?? ContributionPage::create(checkPermissions: FALSE)
      ->setValues(['title' => $title, 'financial_type_id:name' => 'Donation', 'is_active' => TRUE])
      ->execute()
      ->single()['id'];
}

$shop = PriceSet::get(checkPermissions: FALSE)
  ->addWhere('name', '=', 'herald_demo_shop')
  ->execute()
  ->first() ?? PriceSet::create(checkPermissions: FALSE)
  ->setValues([
    'name' => 'herald_demo_shop',
    'title' => 'Demo shop',
    'extends' => [CRM_Core_Component::getComponentID('CiviContribute')],
    'financial_type_id:name' => 'Donation',
    'is_quick_config' => FALSE,
  ])
  ->execute()
  ->single();
foreach ([
  ['ticket', 'Ticket', 15, 'Event Fee'],
  ['t_shirt', 'T-Shirt', 25, 'Donation'],
  ['donation', 'Donation', 10, 'Donation'],
] as [$key, $label, $price, $type]) {
  $exists = PriceField::get(checkPermissions: FALSE)
    ->addWhere('price_set_id', '=', $shop['id'])
    ->addWhere('name', '=', $key)
    ->execute()
    ->count() > 0;
  if ($exists) {
    continue;
  }
  $field = PriceField::create(checkPermissions: FALSE)
    ->setValues([
      'price_set_id' => $shop['id'],
      'name' => $key,
      'label' => $label,
      'html_type' => 'Text',
      'is_enter_qty' => TRUE,
    ])
    ->execute()
    ->single();
  PriceFieldValue::create(checkPermissions: FALSE)
    ->setValues([
      'price_field_id' => $field['id'],
      'name' => $key,
      'label' => $label,
      'amount' => $price,
      'financial_type_id:name' => $type,
    ])
    ->execute();
}
/** @var list<array{id: int, price_field_id: int, label: string, financial_type_id: int, amount: float}> $items */
$items = PriceFieldValue::get(checkPermissions: FALSE)
  ->addSelect('id', 'price_field_id', 'label', 'amount', 'financial_type_id')
  ->addWhere('price_field_id.price_set_id', '=', $shop['id'])
  ->addOrderBy('price_field_id')
  ->execute()
  ->getArrayCopy();

$at = static function (int $daysAgo, int $hour): string {
  // Today's records lie in the past, whatever the hour of the run.
  $hour = $daysAgo === 0 ? min($hour, (int) date('G')) : $hour;
  return (new DateTimeImmutable('today'))
    ->modify("-{$daysAgo} days")
    ->setTime($hour, 0)
    ->format('Y-m-d H:i:s');
};

// A deterministic spread: every period gets contributions, and yesterday one per page and none.
$amounts = [5, 10, 20, 25, 35, 50, 75, 100, 120, 250, 500, 2500];
$types = ['Donation', 'Donation', 'Member Dues', 'Donation', 'Campaign Contribution'];
$perDay = [2, 3, 1, 2, 0, 2, 1];
$days = [...range(0, 62), 90, 150, 200, 300, 400];
// Sources as imports and processors write them, for the cleaned field below.
$rawSources = [
  'Bank TX 2026-0412',
  'BANK TX 2026-0419',
  'Online donation form',
  'pi_3QxDemo42',
  'Street collection',
  NULL,
];
$i = 0;
foreach ($days as $daysAgo) {
  for ($slot = 1; $slot <= ($daysAgo > 62 ? 1 : $perDay[$daysAgo % 7]); $slot++) {
    Contribution::create(checkPermissions: FALSE)
      ->setValues([
        'contact_id' => $donors[$i % count($donors)],
        'financial_type_id:name' => $types[$i % count($types)],
        'contribution_page_id' => [$pages[0], $pages[1], NULL][$i % 3],
        'total_amount' => $amounts[$i % count($amounts)],
        'source' => $rawSources[($i + 1) % count($rawSources)],
        'receive_date' => $at($daysAgo, 8 + (3 * $slot)),
        'contribution_status_id:name' => 'Completed',
      ])
      ->execute();
    $i++;
  }
}

// What every search leaves out: pending, failed and test contributions.
foreach ([[1, 'Pending', FALSE], [20, 'Pending', FALSE], [2, 'Failed', FALSE], [1, 'Completed', TRUE]] as [
  $daysAgo,
  $status,
  $test,
]) {
  Contribution::create(checkPermissions: FALSE)
    ->setValues([
      'contact_id' => $donors[0],
      'financial_type_id:name' => 'Donation',
      'total_amount' => 40,
      'receive_date' => $at($daysAgo, 9),
      'contribution_status_id:name' => $status,
      'is_test' => $test,
    ])
    ->execute();
}

// Shop orders: quantities per item in the order Ticket, T-Shirt, Donation.
foreach ([0 => [2, 1, 0], 1 => [1, 0, 3], 3 => [4, 2, 1], 9 => [0, 1, 0], 33 => [3, 0, 0]] as $daysAgo => $quantities) {
  $lines = [];
  foreach ($items as $index => $item) {
    if ($quantities[$index] === 0) {
      continue;
    }
    $lines[] = [
      'entity_table' => 'civicrm_contribution',
      'price_field_id' => $item['price_field_id'],
      'price_field_value_id' => $item['id'],
      'label' => $item['label'],
      'financial_type_id' => $item['financial_type_id'],
      'qty' => $quantities[$index],
      'unit_price' => $item['amount'],
      'line_total' => $quantities[$index] * $item['amount'],
    ];
  }
  $date = $at($daysAgo, 12);
  $order = Order::create(checkPermissions: FALSE)
    ->setContributionValues([
      'contact_id' => $donors[$daysAgo % count($donors)],
      'financial_type_id:name' => 'Donation',
      'receive_date' => $date,
    ])
    ->setLineItems($lines)
    ->execute()
    ->single();
  civicrm_api4('Payment', 'create', [
    'checkPermissions' => FALSE,
    'values' => ['contribution_id' => $order['id'], 'total_amount' => $order['total_amount'], 'trxn_date' => $date],
  ]);
}

// Activities of the last three weeks, three of them overdue and one ahead.
$activityTypes = ['Meeting', 'Phone Call', 'Email', 'Follow up'];
foreach (range(0, 20) as $daysAgo) {
  Activity::create(checkPermissions: FALSE)
    ->setValues([
      'source_contact_id' => $donors[$daysAgo % count($donors)],
      'activity_type_id:name' => $activityTypes[$daysAgo % count($activityTypes)],
      'status_id:name' => 'Completed',
      'activity_date_time' => $at($daysAgo, 10),
    ])
    ->execute();
}
foreach ([[1, 'Phone Call'], [3, 'Follow up'], [10, 'Meeting'], [-2, 'Meeting']] as [$daysAgo, $type]) {
  Activity::create(checkPermissions: FALSE)
    ->setValues([
      'source_contact_id' => $donors[1],
      'activity_type_id:name' => $type,
      'status_id:name' => 'Scheduled',
      'activity_date_time' => $daysAgo < 0
        ? (new DateTimeImmutable('today'))
          ->modify('+' . -$daysAgo . ' days')
          ->setTime(10, 0)
          ->format('Y-m-d H:i:s')
        : $at($daysAgo, 10),
    ])
    ->execute();
}

$coordinator = Contact::get(checkPermissions: FALSE)
  ->addWhere('external_identifier', '=', 'civico-showroom-coordinator')
  ->execute()
  ->single()['id'];
$trigger = CiviRulesTrigger::get(checkPermissions: FALSE)
  ->addWhere('name', '=', 'herald_schedule')
  ->execute()
  ->single()['id'];
$action = CiviRulesAction::get(checkPermissions: FALSE)
  ->addWhere('name', '=', 'herald_digest')
  ->execute()
  ->single()['id'];

// Kept when the site already cleans the source, so edits on the screen stay.
$cleaned = CleanedFields::all();
if (
  array_filter(
    $cleaned,
    static fn (array $e): bool => (
      $e['entity'] === 'Contribution'
      && $e['field'] === 'source'
    ),
  ) === []
) {
  Herald::saveCleanedFields(checkPermissions: FALSE)
    ->setFields([
      ...$cleaned,
      [
        'entity' => 'Contribution',
        'field' => 'source',
        'rules' => [
          ['match' => 'Bank TX', 'type' => 'prefix', 'label' => 'Bank transfer'],
          ['match' => '^pi_[A-Za-z0-9]+$', 'type' => 'regex', 'label' => 'Stripe'],
          ['match' => 'online', 'type' => 'contains', 'label' => 'Website'],
        ],
        'fallback' => 'Other',
      ],
    ])
    ->execute();
}

// A search grouped by the cleaned source, which the shipped searches cannot assume.
$bySource = 'Herald_Demo_Contributions_Last_Week_By_Source';
SavedSearch::save(checkPermissions: FALSE)
  ->setRecords([[
    'name' => $bySource,
    'label' => 'Demo: contributions last week by source',
    'api_entity' => 'Contribution',
    'api_params' => [
      'version' => 4,
      'select' => ['herald_clean_source', 'SUM(total_amount) AS SUM_total_amount', 'COUNT(id) AS COUNT_id'],
      'where' => [
        ['contribution_status_id:name', '=', 'Completed'],
        ['is_test', '=', FALSE],
        ['is_template', '=', FALSE],
        ['receive_date', '=', 'previous.week'],
      ],
      'groupBy' => ['herald_clean_source'],
    ],
  ]])
  ->setMatch(['name'])
  ->execute();
SearchDisplay::save(checkPermissions: FALSE)
  ->setRecords([[
    'name' => "{$bySource}_Table",
    'label' => 'Demo: contributions last week by source',
    'saved_search_id.name' => $bySource,
    'type' => 'table',
    'settings' => [
      'sort' => [['SUM_total_amount', 'DESC']],
      'columns' => [
        ['type' => 'field', 'key' => 'herald_clean_source', 'label' => 'Source', 'sortable' => TRUE],
        ['type' => 'field', 'key' => 'SUM_total_amount', 'label' => 'Sum', 'sortable' => TRUE],
        ['type' => 'field', 'key' => 'COUNT_id', 'label' => 'Count', 'sortable' => TRUE],
      ],
    ],
  ]])
  ->setMatch(['name'])
  ->execute();

// Digest => title, schedule weekdays (ISO, empty = daily), sources as name => [display, heading].
$digests = [
  'daily_report' => [
    'Daily report',
    [],
    [
      'total' => ['Herald_Digest_Contributions_Yesterday_Table', 'Contributions yesterday'],
      'by_form' => ['Herald_Digest_Contributions_Yesterday_By_Form_Table', 'By form'],
      'activities' => ['Herald_Digest_Activities_Yesterday_Table', 'Activities yesterday'],
    ],
  ],
  'weekly_report' => [
    'Weekly report',
    [1],
    [
      'total' => ['Herald_Digest_Contributions_Last_Week_Table', 'Contributions last week'],
      'by_type' => ['Herald_Digest_Contributions_Last_Week_By_Financial_Type_Table', 'By financial type'],
      'by_source' => ["{$bySource}_Table", 'By source'],
      'amounts' => ['Herald_Digest_Contribution_Amounts_Last_Week_Table', 'Contribution amounts'],
      'overdue' => ['Herald_Digest_Activities_Overdue_Table', 'Overdue activities'],
    ],
  ],
];
foreach ($digests as $name => [$title, $weekdays, $sources]) {
  if (HeraldDigest::get(checkPermissions: FALSE)->addWhere('name', '=', $name)->execute()->count() > 0) {
    continue;
  }
  $displays = SearchDisplay::get(checkPermissions: FALSE)
    ->addWhere('name', 'IN', array_column($sources, 0))
    ->execute()
    ->column('id', 'name');
  // Placeholder names follow the display headers, which are in the site's language.
  $text = [];
  foreach ($sources as $source => [$display, $heading]) {
    $search = SearchSource::placeholders(Cast::toInt($displays[$display]));
    $fields = array_column($search['fields'], 'name', 'type');
    $text[] = "<b>{$heading}</b>\n" . match (TRUE) {
      $search['single']
        => "{\${$source}.{$fields['Integer']}} contributions, {\${$source}.{$fields['Money']}|crmMoney} in total",
      $search['grouped'] => Snippet::search($source, $search)['snippet'],
      default => ChartSnippet::histogram($source, $search['fields']),
    };
  }
  $digest = HeraldDigest::create(checkPermissions: FALSE)
    ->setValues(['name' => $name, 'title' => $title, 'msg_subject' => $title, 'msg_html' => implode("\n\n", $text)])
    ->execute()
    ->single()['id'];
  foreach ($sources as $source => [$display]) {
    HeraldDigestSource::create(checkPermissions: FALSE)
      ->setValues([
        'digest_id' => $digest,
        'name' => $source,
        'type' => 'search',
        'search_display_id' => $displays[$display],
      ])
      ->execute();
  }
  $rule = CiviRulesRule::create(checkPermissions: FALSE)
    ->setValues([
      'name' => "herald_demo_{$name}",
      'label' => "Herald demo: {$title}",
      'trigger_id' => $trigger,
      'trigger_params' => serialize(['weekdays' => $weekdays, 'hour' => 8, 'since' => time()]),
      'is_active' => TRUE,
    ])
    ->execute()
    ->single()['id'];
  CiviRulesRuleAction::create(checkPermissions: FALSE)
    ->setValues([
      'rule_id' => $rule,
      'action_id' => $action,
      'action_params' => [
        'digest' => $name,
        'delivery_mode' => 'direct',
        'channel' => 'activity',
        'activity_assignee_contact_id' => $coordinator,
      ],
      'is_active' => TRUE,
    ])
    ->execute();
}

fwrite(STDOUT, sprintf(
  "herald digest demo ready: %d contributions, digests %s\n",
  $i,
  implode(',', array_keys($digests)),
));

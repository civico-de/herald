<?php

declare(strict_types = 1);

use Civi\Herald\Digest\ShippedSearches;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * Searches with a table display each, for digest sources: contributions and
 * activities per period. ShippedSearches lists them for the form. None outputs contact data.
 * Contribution searches exist only while CiviContribute is enabled.
 */
$periods = [];
foreach (ShippedSearches::periodLabels() as $key => $label) {
  $periods[$key] = ['filter' => ShippedSearches::PERIODS[$key], 'label' => $label];
}

/**
 * A search and its table display. $query holds entity, params (APIv4), columns
 * (key => header; the header names the placeholder) and an optional sort.
 */
$search = static fn (string $name, string $label, array $query): array => [
  [
    'name' => "SavedSearch_{$name}",
    'entity' => 'SavedSearch',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => $name,
        'label' => $label,
        'description' => E::ts('Shipped by herald as a digest source.'),
        'api_entity' => $query['entity'],
        'api_params' => ['version' => 4] + (array) $query['params'],
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => "SearchDisplay_{$name}_Table",
    'entity' => 'SearchDisplay',
    'cleanup' => 'unused',
    // Headers name placeholders; a reconcile in another language must not rename them.
    'update' => 'never',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => "{$name}_Table",
        'label' => $label,
        'saved_search_id.name' => $name,
        'type' => 'table',
        'settings' => [
          'sort' => $query['sort'] ?? [],
          'columns' => array_map(
            static fn (int|string $key, mixed $header): array => [
              'type' => 'field',
              'key' => (string) $key,
              'label' => Cast::toString($header),
              'sortable' => TRUE,
            ],
            array_keys((array) $query['columns']),
            (array) $query['columns'],
          ),
        ],
      ],
      'match' => ['name'],
    ],
  ],
];

$totals = ['SUM_total_amount' => E::ts('Sum'), 'COUNT_id' => E::ts('Count')];
$totalsSelect = ['SUM(total_amount) AS SUM_total_amount', 'COUNT(id) AS COUNT_id'];
$bySum = [['SUM_total_amount', 'DESC']];
$byCount = [['COUNT_id', 'DESC']];
$contribution = static fn (string $status): array => [
  ['contribution_status_id:name', '=', $status],
  ['is_test', '=', FALSE],
  ['is_template', '=', FALSE],
];
// herald's own notifications are messages, not work: they would count as overdue.
$activity = [
  ['is_deleted', '=', FALSE],
  ['is_test', '=', FALSE],
  ['is_current_revision', '=', TRUE],
  ['activity_type_id:name', '!=', 'herald_notification'],
];

$declarations = [];
foreach ($periods as $key => $period) {
  $received = [...$contribution('Completed'), ['receive_date', '=', $period['filter']]];
  if (\CRM_Core_Component::isEnabled('CiviContribute')) {
    $declarations[] = $search(
      "Herald_Digest_Contributions_{$key}",
      E::ts('Digest: contributions %1', [1 => $period['label']]),
      ['entity' => 'Contribution', 'params' => ['select' => $totalsSelect, 'where' => $received], 'columns' => $totals],
    );
    $declarations[] = $search(
      "Herald_Digest_Contributions_{$key}_By_Form",
      E::ts('Digest: contributions %1 by form', [1 => $period['label']]),
      [
        'entity' => 'Contribution',
        'params' => [
          'select' => ['contribution_page_id:label', ...$totalsSelect],
          'where' => $received,
          'groupBy' => ['contribution_page_id'],
        ],
        'columns' => ['contribution_page_id:label' => E::ts('Form')] + $totals,
        'sort' => $bySum,
      ],
    );
    $declarations[] = $search(
      "Herald_Digest_Contributions_{$key}_By_Financial_Type",
      E::ts('Digest: contributions %1 by financial type', [1 => $period['label']]),
      [
        'entity' => 'Contribution',
        'params' => [
          'select' => ['financial_type_id:label', ...$totalsSelect],
          'where' => $received,
          'groupBy' => ['financial_type_id'],
        ],
        'columns' => ['financial_type_id:label' => E::ts('Financial type')] + $totals,
        'sort' => $bySum,
      ],
    );
    $declarations[] = $search(
      "Herald_Digest_Line_Items_{$key}",
      E::ts('Digest: line items %1', [1 => $period['label']]),
      [
        'entity' => 'LineItem',
        'params' => [
          'select' => ['label', 'unit_price', 'SUM(qty) AS SUM_qty', 'SUM(line_total) AS SUM_line_total'],
          'where' => [
            ...array_map(
              static fn (array $clause): array => ['contribution_id.' . $clause[0], ...array_slice($clause, 1)],
              $received,
            ),
            // Free items are no sales.
            ['line_total', '>', 0],
          ],
          'groupBy' => ['label', 'unit_price'],
        ],
        'columns' => [
          'label' => E::ts('Item'),
          'unit_price' => E::ts('Price'),
          'SUM_qty' => E::ts('Quantity'),
          'SUM_line_total' => E::ts('Sum'),
        ],
        'sort' => [['SUM_line_total', 'DESC']],
      ],
    );
    $declarations[] = $search(
      "Herald_Digest_Contribution_Amounts_{$key}",
      E::ts('Digest: contribution amounts %1', [1 => $period['label']]),
      [
        'entity' => 'Contribution',
        'params' => ['select' => ['total_amount'], 'where' => $received],
        'columns' => ['total_amount' => E::ts('Amount')],
      ],
    );
  }
  $declarations[] = $search(
    "Herald_Digest_Activities_{$key}",
    E::ts('Digest: activities %1 by type and status', [1 => $period['label']]),
    [
      'entity' => 'Activity',
      'params' => [
        'select' => ['activity_type_id:label', 'status_id:label', 'COUNT(id) AS COUNT_id'],
        'where' => [...$activity, ['activity_date_time', '=', $period['filter']]],
        'groupBy' => ['activity_type_id', 'status_id'],
      ],
      'columns' => [
        'activity_type_id:label' => E::ts('Type'),
        'status_id:label' => E::ts('Status'),
        'COUNT_id' => E::ts('Count'),
      ],
      'sort' => $byCount,
    ],
  );
}
if (\CRM_Core_Component::isEnabled('CiviContribute')) {
  $declarations[] = $search('Herald_Digest_Contributions_Pending', E::ts('Digest: pending contributions'), [
    'entity' => 'Contribution',
    'params' => ['select' => $totalsSelect, 'where' => $contribution('Pending')],
    'columns' => $totals,
  ]);
}
$declarations[] = $search('Herald_Digest_Activities_Overdue', E::ts('Digest: overdue activities by type'), [
  'entity' => 'Activity',
  'params' => [
    'select' => ['activity_type_id:label', 'COUNT(id) AS COUNT_id'],
    'where' => [...$activity, ['status_id:name', '=', 'Scheduled'], ['activity_date_time', '<', 'now']],
    'groupBy' => ['activity_type_id'],
  ],
  'columns' => ['activity_type_id:label' => E::ts('Type'), 'COUNT_id' => E::ts('Count')],
  'sort' => $byCount,
]);

return array_merge(...$declarations);

<?php

declare(strict_types = 1);

use CRM_Herald_ExtensionUtil as E;

/**
 * Administer » Herald: the settings form, the admin screens, the digests and the parked-alerts list.
 */
$item = static fn (string $name, string $label, string $url, int $weight, string $parent = 'herald') => [
  'name' => 'Navigation_' . $name,
  'entity' => 'Navigation',
  'cleanup' => 'unused',
  'update' => 'unmodified',
  'params' => [
    'version' => 4,
    'values' => [
      'name' => $name,
      'label' => $label,
      'url' => $url,
      'permission' => 'administer CiviCRM',
      'parent_id.name' => $parent,
      'weight' => $weight,
      'is_active' => TRUE,
      'domain_id' => 'current_domain',
    ],
    'match' => ['name', 'domain_id'],
  ],
];

$items = [
  $item('herald', E::ts('Herald'), '', 90, 'Administer'),
  $item('herald_settings', E::ts('Settings'), 'civicrm/admin/setting/herald?reset=1', 1),
];
// Literal so the file evaluates without the class loader; AdminScreensTest pins it to AdminScreens::screens().
$screens = [
  'channels' => E::ts('Channels'),
  'topics' => E::ts('Topic routes'),
  'cleaning' => E::ts('Cleaned fields'),
  'hosts' => E::ts('Webhook allowed hosts'),
  'monitor' => E::ts('Monitor checks'),
  'test' => E::ts('Test message'),
  'preview' => E::ts('Template preview'),
];
$weight = 2;
foreach ($screens as $route => $title) {
  $items[] = $item('herald_' . $route, $title, 'civicrm/admin/herald#/' . $route, $weight++);
}
$items[] = $item('herald_digests', E::ts('Digests'), 'civicrm/admin/herald/digests?reset=1', $weight++);
// Its managed name predates the scheme above; a new name would recreate the item.
$parked = $item(
  'herald_parked_alerts',
  E::ts('Parked alerts'),
  'civicrm/search#/display/Herald_Parked_Alerts/Herald_Parked_Alerts_Table',
  $weight,
);
$parked['name'] = 'Navigation_Herald_Parked_Alerts';
$items[] = $parked;

return $items;

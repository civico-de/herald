<?php

declare(strict_types = 1);

/**
 * herald's CiviRules handlers, declared only while CiviRules is installed, so
 * installing CiviRules later registers them on its own reconcile. `match` adopts
 * rows an earlier release created; `is_active` undoes core's disable on re-enable.
 * Cleanup `always`, as CiviRules prescribes: a handler whose class is gone would
 * fatal every rule that uses it.
 */
if (!CRM_Herald_CivirulesAdapter_Availability::isInstalled()) {
  return [];
}

$handler = static fn (string $entity, string $name, string $label, string $class, array $extra = []): array => [
  'name' => 'herald_civirules_' . $name,
  'entity' => $entity,
  'cleanup' => 'always',
  'update' => 'always',
  'params' => [
    'version' => 4,
    'values' => ['name' => $name, 'label' => $label, 'class_name' => $class, 'is_active' => TRUE] + $extra,
    'match' => ['name'],
  ],
];

return [
  $handler(
    'CiviRulesAction',
    'herald_send',
    'Herald: Send notification (generic)',
    'CRM_Herald_CivirulesAdapter_Action_Notification',
  ),
  $handler('CiviRulesAction', 'herald_digest', 'Herald: Send digest', 'CRM_Herald_CivirulesAdapter_Action_Digest'),
  $handler(
    'CiviRulesCondition',
    'herald_field_transition',
    'Herald: Field transition (entity field changed from/to value)',
    'CRM_Herald_CivirulesAdapter_Condition_FieldTransition',
  ),
  $handler(
    'CiviRulesTrigger',
    'herald_schedule',
    'Herald: Schedule',
    'CRM_Herald_CivirulesAdapter_Trigger_Schedule',
    ['cron' => TRUE],
  ),
];

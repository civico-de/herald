<?php

declare(strict_types = 1);

namespace Civi\Herald\Admin;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * The delivery params stored outside the topic routes: herald rule actions
 * and Herald scheduled jobs, each under a label naming it.
 */
final class DeliveryParams {

  private const ACTION_CLASSES = [
    'CRM_Herald_CivirulesAdapter_Action_Notification',
    'CRM_Herald_CivirulesAdapter_Action_Digest',
  ];

  /**
   * The labels of the deliveries whose value under $key $matches.
   *
   * @param \Closure(mixed): bool $matches
   * @return list<string>
   */
  public static function labelsWhere(string $key, \Closure $matches): array {
    $labels = [];
    foreach (self::all() as [$label, $params]) {
      if (!$matches($params[$key] ?? NULL)) {
        continue;
      }
      $labels[] = $label;
    }
    return $labels;
  }

  /**
   * @return list<array{string, array<array-key, mixed>}> Label and delivery params.
   */
  private static function all(): array {
    $out = [];
    foreach (self::ruleActions() as $action) {
      $out[] = [E::ts('Rule "%1"', [1 => $action['label']]), $action['params']];
    }
    return array_merge($out, self::jobs());
  }

  /**
   * @return list<array{string, array<array-key, mixed>}>
   */
  private static function jobs(): array {
    /** @var list<array{name: mixed, parameters: mixed}> $jobs */
    $jobs = \Civi\Api4\Job::get(checkPermissions: FALSE)
      ->addSelect('name', 'parameters')
      ->addWhere('api_entity', '=', 'Herald')
      ->execute()
      ->getArrayCopy();
    $out = [];
    foreach ($jobs as $job) {
      $parameters = json_decode(Cast::toString($job['parameters'] ?? ''), TRUE);
      if (!is_array($parameters)) {
        continue;
      }
      $params = is_array($parameters['actionParams'] ?? NULL) ? $parameters['actionParams'] : $parameters;
      $out[] = [E::ts('Scheduled job "%1"', [1 => Cast::toString($job['name'])]), $params];
    }
    return $out;
  }

  /**
   * Read from the tables: they hold the actions while CiviRules is disabled.
   *
   * @return list<array{rule_id: int, label: string, params: array<array-key, mixed>}>
   */
  private static function ruleActions(): array {
    if (!\CRM_Core_DAO::checkTableExists('civirule_rule_action')) {
      return [];
    }
    /** @var list<array<string, mixed>> $rows */
    $rows = \CRM_Utils_SQL_Select::from('civirule_rule_action ra')
      ->join('a', 'INNER JOIN civirule_action a ON a.id = ra.action_id')
      ->join('r', 'INNER JOIN civirule_rule r ON r.id = ra.rule_id')
      ->select(['ra.rule_id', 'ra.action_params', 'r.label'])
      ->where('a.class_name IN (@classes)', ['classes' => self::ACTION_CLASSES])
      ->execute()
      ->fetchAll();
    $out = [];
    foreach ($rows as $row) {
      // A malformed string makes unserialize() raise a notice besides returning FALSE.
      set_error_handler(static fn (): bool => TRUE);
      try {
        $params = unserialize(Cast::toString($row['action_params'] ?? ''), ['allowed_classes' => FALSE]);
      }
      finally {
        restore_error_handler();
      }
      $out[] = [
        'rule_id' => Cast::toInt($row['rule_id']),
        'label' => Cast::toString($row['label']),
        'params' => is_array($params) ? $params : [],
      ];
    }
    return $out;
  }

}

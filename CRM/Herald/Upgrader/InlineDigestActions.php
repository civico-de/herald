<?php

declare(strict_types = 1);

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * "Send digest" actions whose digest still sits in their parameters get a
 * HeraldDigest of their own (CRM_Herald_Upgrader_InlineDigest).
 *
 * Reads the CiviRules tables directly: they hold the actions while CiviRules
 * is disabled, and the API unserializes action_params only from CiviRules 3.36.
 */
final class CRM_Herald_Upgrader_InlineDigestActions {

  /**
   * @return int Number of rule actions rewritten.
   */
  public static function migrate(): int {
    if (!CRM_Core_DAO::checkTableExists('civirule_rule_action')) {
      return 0;
    }
    /** @var list<array<string, mixed>> $actions */
    $actions = CRM_Utils_SQL_Select::from('civirule_rule_action ra')
      ->join('a', 'INNER JOIN civirule_action a ON a.id = ra.action_id')
      ->join('r', 'LEFT JOIN civirule_rule r ON r.id = ra.rule_id')
      ->select(['ra.id', 'ra.action_params', 'r.label'])
      // Retired digest handlers still hold actions where CiviRules was disabled when they were retired.
      ->where('a.class_name IN (@classes)', ['classes' => CRM_Herald_CivirulesAdapter_RetiredHandlers::digestClasses()])
      ->execute()
      ->fetchAll();
    $migrated = 0;
    foreach ($actions as $action) {
      CRM_Core_Transaction::create()->run(static function () use ($action, &$migrated): void {
        $migrated += self::rewrite($action);
      });
    }
    return $migrated;
  }

  /**
   * The digest and the action that names it are written together, so a failed upgrade can run again.
   *
   * @param array<string, mixed> $action
   */
  private static function rewrite(array $action): int {
    $id = Cast::toInt($action['id']);
    $params = self::params(Cast::toString($action['action_params'] ?? ''), $id);
    if ($params === NULL || !CRM_Herald_Upgrader_InlineDigest::isInline($params)) {
      return 0;
    }
    $title = Cast::toString($action['label'] ?? '') ?: "Rule action {$id}";
    $delivery = CRM_Herald_Upgrader_InlineDigest::extract($params, "rule_action_{$id}", $title);
    if ($delivery === NULL) {
      return 0;
    }
    CRM_Core_DAO::executeQuery(
      'UPDATE civirule_rule_action SET action_params = %1 WHERE id = %2',
      [1 => [serialize($delivery), 'String'], 2 => [$id, 'Integer']],
    );
    return 1;
  }

  /**
   * CiviRules stores the params PHP-serialized and reads empty ones as none.
   *
   * @return array<string, mixed>|null
   */
  private static function params(string $serialized, int $id): ?array {
    if ($serialized === '') {
      return [];
    }
    // A malformed string makes unserialize() raise a notice besides returning FALSE.
    set_error_handler(static fn (): bool => TRUE);
    try {
      $params = unserialize($serialized, ['allowed_classes' => FALSE]);
    }
    finally {
      restore_error_handler();
    }
    if (is_array($params)) {
      return Cast::namedOnly($params);
    }
    \Civi::log('herald')->warning('herald: rule action params unreadable, digest left inline', ['rule_action' => $id]);
    return NULL;
  }

}

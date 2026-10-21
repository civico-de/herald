<?php

declare(strict_types = 1);

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Every stored delivery rewritten into a new shape: rule actions, scheduled
 * jobs, topic routes and buffered alerts, in that order.
 *
 * @phpstan-type Rewrite callable(array<array-key, mixed>, string, list<CRM_Herald_Upgrader_Domain>): array<array-key, mixed>
 */
final class CRM_Herald_Upgrader_StoredDeliveries {

  /**
   * Rule actions and alerts may be sent from any domain, jobs and routes only from their own.
   *
   * @param list<CRM_Herald_Upgrader_Domain> $domains
   * @param Rewrite $ruleAction Like $delivery, for the params of a rule action
   * @param Rewrite $delivery Params, a unique owner suffix and the domains it may be sent from => new params
   * @param callable(CRM_Herald_Upgrader_Domain): int $routes Rewrites a domain's routes => how many changed
   * @return int The deliveries changed
   */
  public static function rewrite(array $domains, callable $ruleAction, callable $delivery, callable $routes): int {
    $count = self::rewriteRuleActions($domains, $ruleAction) + self::rewriteJobs($domains, $delivery);
    foreach ($domains as $domain) {
      $count += $routes($domain);
    }
    return $count + self::rewriteBuffer($domains, $delivery);
  }

  /**
   * Read from the tables: they hold the actions while CiviRules is disabled.
   *
   * @param list<CRM_Herald_Upgrader_Domain> $domains
   * @param Rewrite $delivery
   */
  private static function rewriteRuleActions(array $domains, callable $delivery): int {
    if (!CRM_Core_DAO::checkTableExists('civirule_rule_action')) {
      return 0;
    }
    /** @var list<array{id: mixed, action_params: mixed}> $actions */
    $actions = CRM_Utils_SQL_Select::from('civirule_rule_action ra')
      ->join('a', 'INNER JOIN civirule_action a ON a.id = ra.action_id')
      ->select(['ra.id', 'ra.action_params'])
      ->where('a.class_name IN (@classes)', ['classes' => [
        'CRM_Herald_CivirulesAdapter_Action_Notification',
        'CRM_Herald_CivirulesAdapter_Action_Digest',
      ]])
      ->execute()
      ->fetchAll();
    $count = 0;
    foreach ($actions as $action) {
      $id = Cast::toInt($action['id']);
      $stored = CRM_Utils_String::unserialize(Cast::toString($action['action_params']));
      if (!is_array($stored)) {
        continue;
      }
      $params = $delivery($stored, 'action_' . $id, $domains);
      if ($params === $stored) {
        continue;
      }
      CRM_Core_DAO::executeQuery(
        'UPDATE civirule_rule_action SET action_params = %1 WHERE id = %2',
        [1 => [serialize($params), 'String'], 2 => [$id, 'Integer']],
      );
      $count++;
    }
    return $count;
  }

  /**
   * @param list<CRM_Herald_Upgrader_Domain> $domains
   * @param Rewrite $delivery
   */
  private static function rewriteJobs(array $domains, callable $delivery): int {
    /** @var list<array{id: int, domain_id: mixed, parameters: mixed}> $jobs */
    $jobs = \Civi\Api4\Job::get(checkPermissions: FALSE)
      ->addSelect('id', 'domain_id', 'parameters')
      ->addWhere('api_entity', '=', 'Herald')
      ->addWhere('api_action', 'IN', ['notify', 'notifyDigest', 'send'])
      ->execute()
      ->getArrayCopy();
    $count = 0;
    foreach ($jobs as $job) {
      $stored = json_decode(Cast::toString($job['parameters']), TRUE);
      if (!is_array($stored)) {
        continue;
      }
      $own = array_values(array_filter(
        $domains,
        static fn (CRM_Herald_Upgrader_Domain $domain): bool => $domain->id === Cast::toInt($job['domain_id']),
      ));
      $parameters = CRM_Herald_Upgrader_JobDelivery::rewrite(
        $stored,
        $delivery,
        'job_' . Cast::toInt($job['id']),
        $own,
      );
      if ($parameters === NULL || $parameters === $stored) {
        continue;
      }
      \Civi\Api4\Job::update(checkPermissions: FALSE)
        ->addWhere('id', '=', $job['id'])
        ->addValue('parameters', json_encode(
          $parameters,
          JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
        ))
        ->execute();
      $count++;
    }
    return $count;
  }

  /**
   * Buffer rows go through SQL like the BAO: the API would encode < and >.
   *
   * @param list<CRM_Herald_Upgrader_Domain> $domains
   * @param Rewrite $delivery
   */
  private static function rewriteBuffer(array $domains, callable $delivery): int {
    /** @var list<array{id: mixed, delivery: mixed}> $rows */
    $rows = CRM_Utils_SQL_Select::from('civicrm_herald_buffer')->select(['id', 'delivery'])->execute()->fetchAll();
    $count = 0;
    foreach ($rows as $row) {
      $stored = json_decode(Cast::toString($row['delivery']), TRUE);
      if (!is_array($stored)) {
        continue;
      }
      $id = Cast::toInt($row['id']);
      $rewritten = $delivery($stored, 'alert_' . $id, $domains);
      if ($rewritten === $stored) {
        continue;
      }
      CRM_Core_DAO::executeQuery('UPDATE civicrm_herald_buffer SET delivery = %1 WHERE id = %2', [
        1 => [json_encode($rewritten, JSON_THROW_ON_ERROR), 'String'],
        2 => [$id, 'Integer'],
      ]);
      $count++;
    }
    return $count;
  }

}

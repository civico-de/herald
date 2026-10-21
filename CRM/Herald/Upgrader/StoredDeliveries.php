<?php

declare(strict_types = 1);

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Every stored delivery in the shape of named channels: rule actions,
 * scheduled jobs, topic routes and buffered alerts.
 */
final class CRM_Herald_Upgrader_StoredDeliveries {

  /**
   * Rule actions and alerts may be sent from any domain, jobs and routes only from their own.
   *
   * @param list<CRM_Herald_Upgrader_Domain> $domains
   * @return int The deliveries rewritten
   */
  public static function rewrite(array $domains): int {
    $count = self::rewriteRuleActions($domains) + self::rewriteJobs($domains);
    foreach ($domains as $domain) {
      $count += CRM_Herald_Upgrader_StoredRoutes::rewrite($domain);
    }
    return $count + self::rewriteBuffer($domains);
  }

  /**
   * Read from the tables: they hold the actions while CiviRules is disabled.
   */
  /**
   * @param list<CRM_Herald_Upgrader_Domain> $domains
   */
  private static function rewriteRuleActions(array $domains): int {
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
      $params = CRM_Utils_String::unserialize(Cast::toString($action['action_params']));
      if (!is_array($params)) {
        continue;
      }
      // The old form saved these unticked as FALSE, which then meant "not set".
      $params = array_diff_key($params, array_filter(
        array_intersect_key($params, array_flip(['disable_notification', 'disable_web_page_preview'])),
        static fn (mixed $value): bool => $value === FALSE,
      ));
      CRM_Core_DAO::executeQuery(
        'UPDATE civirule_rule_action SET action_params = %1 WHERE id = %2',
        [
          1 => [
            serialize(CRM_Herald_Upgrader_NamedChannels::delivery($params, 'action_' . $id, $domains)),
            'String',
          ],
          2 => [$id, 'Integer'],
        ],
      );
      $count++;
    }
    return $count;
  }

  /**
   * @param list<CRM_Herald_Upgrader_Domain> $domains
   */
  private static function rewriteJobs(array $domains): int {
    /** @var list<array{id: int, domain_id: mixed, parameters: mixed}> $jobs */
    $jobs = \Civi\Api4\Job::get(checkPermissions: FALSE)
      ->addSelect('id', 'domain_id', 'parameters')
      ->addWhere('api_entity', '=', 'Herald')
      ->addWhere('api_action', 'IN', ['notify', 'notifyDigest', 'send'])
      ->execute()
      ->getArrayCopy();
    $count = 0;
    foreach ($jobs as $job) {
      $parameters = json_decode(Cast::toString($job['parameters']), TRUE);
      if (!is_array($parameters)) {
        continue;
      }
      $own = array_values(array_filter(
        $domains,
        static fn (CRM_Herald_Upgrader_Domain $domain): bool => $domain->id === Cast::toInt($job['domain_id']),
      ));
      $parameters = self::jobParameters($parameters, 'job_' . Cast::toInt($job['id']), $own);
      if ($parameters === NULL) {
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
   * A notify job keeps its delivery in actionParams, a send job in channel and config.
   *
   * @param array<array-key, mixed> $parameters
   * @param list<CRM_Herald_Upgrader_Domain> $domains
   * @return array<array-key, mixed>|null NULL for a job without a delivery
   */
  private static function jobParameters(array $parameters, string $owner, array $domains): ?array {
    if (is_array($parameters['actionParams'] ?? NULL)) {
      $parameters['actionParams'] = CRM_Herald_Upgrader_NamedChannels::delivery(
        $parameters['actionParams'],
        $owner,
        $domains,
      );
      return $parameters;
    }
    if (!isset($parameters['channel'])) {
      return NULL;
    }
    $config = is_array($parameters['config'] ?? NULL) ? $parameters['config'] : [];
    $delivery = CRM_Herald_Upgrader_NamedChannels::delivery(
      ['channel' => $parameters['channel']] + $config,
      $owner,
      $domains,
    );
    $parameters['channel'] = $delivery['channel'];
    unset($delivery['channel']);
    $parameters['config'] = $delivery;
    return $parameters;
  }

  /**
   * Buffer rows go through SQL like the BAO: the API would encode < and >.
   *
   * @param list<CRM_Herald_Upgrader_Domain> $domains
   */
  private static function rewriteBuffer(array $domains): int {
    /** @var list<array{id: mixed, delivery: mixed}> $rows */
    $rows = CRM_Utils_SQL_Select::from('civicrm_herald_buffer')->select(['id', 'delivery'])->execute()->fetchAll();
    $count = 0;
    foreach ($rows as $row) {
      $delivery = json_decode(Cast::toString($row['delivery']), TRUE);
      if (!is_array($delivery)) {
        continue;
      }
      $id = Cast::toInt($row['id']);
      CRM_Core_DAO::executeQuery('UPDATE civicrm_herald_buffer SET delivery = %1 WHERE id = %2', [
        1 => [
          json_encode(
            CRM_Herald_Upgrader_NamedChannels::delivery($delivery, 'alert_' . $id, $domains),
            JSON_THROW_ON_ERROR,
          ),
          'String',
        ],
        2 => [$id, 'Integer'],
      ]);
      $count++;
    }
    return $count;
  }

}

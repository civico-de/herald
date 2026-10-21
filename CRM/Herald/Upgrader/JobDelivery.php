<?php

declare(strict_types = 1);

/**
 * The delivery inside a scheduled job's parameters.
 *
 * @phpstan-import-type Rewrite from CRM_Herald_Upgrader_StoredDeliveries
 */
final class CRM_Herald_Upgrader_JobDelivery {

  /**
   * A notify job keeps its delivery in actionParams, a send job in channel and config.
   *
   * @param array<array-key, mixed> $parameters
   * @param Rewrite $delivery
   * @param list<CRM_Herald_Upgrader_Domain> $domains
   * @return array<array-key, mixed>|null NULL for a job without a delivery
   */
  public static function rewrite(array $parameters, callable $delivery, string $owner, array $domains): ?array {
    if (is_array($parameters['actionParams'] ?? NULL)) {
      $parameters['actionParams'] = $delivery($parameters['actionParams'], $owner, $domains);
      return $parameters;
    }
    if (!isset($parameters['channel'])) {
      return NULL;
    }
    $config = is_array($parameters['config'] ?? NULL) ? $parameters['config'] : [];
    $rewritten = $delivery(['channel' => $parameters['channel']] + $config, $owner, $domains);
    $parameters['channel'] = $rewritten['channel'];
    unset($rewritten['channel']);
    if ($rewritten !== [] || array_key_exists('config', $parameters)) {
      $parameters['config'] = $rewritten;
    }
    return $parameters;
  }

}

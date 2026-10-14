<?php

declare(strict_types = 1);

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Scheduled jobs of Herald.notifyDigest whose digest still sits in
 * `actionParams` name a HeraldDigest of their own (CRM_Herald_Upgrader_InlineDigest).
 */
final class CRM_Herald_Upgrader_InlineDigestJobs {

  /**
   * @return int Number of jobs rewritten.
   */
  public static function migrate(): int {
    /** @var list<array<string, mixed>> $jobs */
    $jobs = \Civi\Api4\Job::get(checkPermissions: FALSE)
      ->addSelect('id', 'name', 'parameters')
      ->addWhere('api_entity', '=', 'Herald')
      ->addWhere('api_action', '=', 'notifyDigest')
      ->execute()
      ->getArrayCopy();
    $migrated = 0;
    foreach ($jobs as $job) {
      $id = Cast::toInt($job['id'] ?? NULL);
      $api = json_decode(Cast::toString($job['parameters'] ?? ''), TRUE);
      $api = self::migrated(is_array($api) ? Cast::namedOnly($api) : [], $id, Cast::toString($job['name'] ?? ''));
      if ($api === NULL) {
        continue;
      }
      \Civi\Api4\Job::update(checkPermissions: FALSE)
        ->addWhere('id', '=', $id)
        ->addValue('parameters', json_encode(
          $api,
          JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
        ))
        ->execute();
      $migrated++;
    }
    return $migrated;
  }

  /**
   * The job's API parameters with `digest` beside `actionParams`, or NULL to leave them.
   *
   * @param array<string, mixed> $api
   * @return array<string, mixed>|null
   */
  private static function migrated(array $api, int $id, string $name): ?array {
    $params = is_array($api['actionParams'] ?? NULL) ? Cast::namedOnly($api['actionParams']) : NULL;
    if ($params === NULL || array_key_exists('digest', $api) || !CRM_Herald_Upgrader_InlineDigest::isInline($params)) {
      return NULL;
    }
    $delivery = CRM_Herald_Upgrader_InlineDigest::extract($params, "job_{$id}", $name !== '' ? $name : "Job {$id}");
    if ($delivery === NULL) {
      return NULL;
    }
    $api['digest'] = $delivery['digest'];
    unset($delivery['digest']);
    $api['actionParams'] = $delivery;
    return $api;
  }

}

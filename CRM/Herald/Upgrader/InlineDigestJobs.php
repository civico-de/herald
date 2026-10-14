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
      $api = self::api(Cast::toString($job['parameters'] ?? ''));
      if ($api === NULL) {
        continue;
      }
      $name = Cast::toString($job['name'] ?? '');
      try {
        CRM_Core_Transaction::create()->run(static function () use ($api, $id, $name, &$migrated): void {
          $migrated += self::rewrite($api, $id, $name);
        });
      }
      catch (CRM_Core_Exception $e) {
        CRM_Herald_Upgrader_InlineDigest::warn("job_{$id}", CRM_Herald_Upgrader_InlineDigestActions::problems($e));
      }
    }
    return $migrated;
  }

  /**
   * The parameters as the job manager reads them, JSON or key=value lines; NULL
   * for a job that never ran, as Herald.notifyDigest exists only in APIv4.
   *
   * @return array<string, mixed>|null
   */
  private static function api(string $parameters): ?array {
    try {
      $api = Cast::namedOnly(CRM_Core_BAO_Job::parseParameters($parameters));
    }
    catch (CRM_Core_Exception) {
      return NULL;
    }
    return Cast::toInt($api['version'] ?? 3) === 4 ? $api : NULL;
  }

  /**
   * The digest and the job that names it are written together, so a failed upgrade can run again.
   *
   * @throws CRM_Core_Exception when the digest does not compose
   *
   * @param array<string, mixed> $api
   */
  private static function rewrite(array $api, int $id, string $name): int {
    $api = self::migrated($api, $id, $name);
    if ($api === NULL) {
      return 0;
    }
    \Civi\Api4\Job::update(checkPermissions: FALSE)
      ->addWhere('id', '=', $id)
      ->addValue('parameters', json_encode(
        $api,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
      ))
      ->execute();
    return 1;
  }

  /**
   * The job's API parameters with `digest` beside `actionParams`, or NULL to leave them.
   *
   * @throws CRM_Core_Exception when the digest does not compose
   *
   * @param array<string, mixed> $api
   * @return array<string, mixed>|null
   */
  private static function migrated(array $api, int $id, string $name): ?array {
    // A job without actionParams sent the default digest.
    $params = $api['actionParams'] ?? [];
    if (
      !is_array($params)
      || array_key_exists('digest', $api)
      || !CRM_Herald_Upgrader_InlineDigest::isInline($params)
    ) {
      return NULL;
    }
    $params = Cast::namedOnly($params);
    $delivery = CRM_Herald_Upgrader_InlineDigest::extract($params, "job_{$id}", $name !== '' ? $name : "Job {$id}");
    $api['digest'] = $delivery['digest'];
    unset($delivery['digest']);
    $api['actionParams'] = $delivery;
    return $api;
  }

}

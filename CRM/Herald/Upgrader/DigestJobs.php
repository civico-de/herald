<?php

declare(strict_types = 1);

/**
 * Scheduled jobs of the contribution digest become APIv4 jobs of
 * Herald.notifyDigest: `sections` become section blocks, campaign title and
 * goal come from the Campaign record.
 */
final class CRM_Herald_Upgrader_DigestJobs {

  private const RETIRED_KEYS = ['goal', 'campaign_title', 'version'];

  /**
   * @return int Number of jobs rewritten.
   */
  public static function migrate(): int {
    $jobs = \Civi\Api4\Job::get(checkPermissions: FALSE)
      ->addSelect('id', 'parameters')
      ->addWhere('api_entity', '=', 'Job')
      ->addWhere('api_action', '=', 'herald_contribution_digest')
      ->execute()
      ->indexBy('id')
      ->column('parameters');
    $migrated = 0;
    foreach ($jobs as $jobId => $parameters) {
      $jobId = (int) $jobId;
      try {
        $parsed = CRM_Core_BAO_Job::parseParameters(\Civi\Herald\Vendor\Civico\Stdlib\Cast::toString($parameters));
        $params = \Civi\Herald\Vendor\Civico\Stdlib\Cast::namedOnly($parsed);
      }
      catch (CRM_Core_Exception $e) {
        // Such a job could not run before either; it keeps failing visibly in the job log.
        \Civi::log()->warning('herald: digest job left unmigrated, its parameters do not parse', [
          'job_id' => $jobId,
          'error' => $e->getMessage(),
        ]);
        continue;
      }
      \Civi\Api4\Job::update(checkPermissions: FALSE)
        ->addWhere('id', '=', $jobId)
        ->addValue('api_entity', 'Herald')
        ->addValue('api_action', 'notifyDigest')
        ->addValue('parameters', self::parameters($params, $jobId))
        ->execute();
      $migrated++;
    }
    return $migrated;
  }

  /**
   * The job's APIv4 parameters; `force` only ever lifted the send hour.
   *
   * @param array<string, mixed> $params
   */
  public static function parameters(array $params, int $jobId): string {
    $hour = $params['send_hour'] ?? '';
    $force = !empty($params['force']);
    // Core reads this flag at the top level, before the action runs.
    $api =
      ['version' => 4, 'checkPermissions' => FALSE]
      + array_filter(['runInNonProductionEnvironment' => !empty($params['runInNonProductionEnvironment'])]);
    unset($params['send_hour'], $params['force'], $params['runInNonProductionEnvironment']);
    if (!$force && is_numeric($hour)) {
      $api['sendHour'] = (int) $hour;
    }
    $api['actionParams'] = self::actionParams($params, $jobId);
    $problems = \Civi\Herald\Digest\BlockList::problems($api['actionParams']);
    if ($problems !== []) {
      \Civi::log()->warning('herald: migrated digest job will fail until fixed', [
        'job_id' => $jobId,
        'problems' => $problems,
      ]);
    }
    return json_encode($api, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  }

  /**
   * @param array<string, mixed> $params
   * @return array<string, mixed>
   */
  public static function actionParams(array $params, int $jobId): array {
    $dropped = self::withoutRetired($params);
    $sections = \Civi\Herald\Digest\ExtraSections::parseKeys($params['sections'] ?? '');
    unset($params['sections']);
    $blocks = $params['blocks'] ?? NULL;
    $blocks = is_string($blocks) ? json_decode($blocks, TRUE) : $blocks;
    if (is_array($blocks) && $blocks !== []) {
      $blocks = array_map(static function ($block) use (&$dropped) {
        if (is_array($block)) {
          $block = \Civi\Herald\Vendor\Civico\Stdlib\Cast::namedOnly($block);
          $dropped += self::withoutRetired($block);
        }
        return $block;
      }, $blocks);
      // Sections only ever rendered after the blocks of a composed digest.
      foreach ($sections as $key) {
        $blocks[] = ['type' => 'section', 'key' => $key];
      }
      $params['blocks'] = $blocks;
    }
    unset($dropped['version']);
    if ($dropped !== []) {
      \Civi::log()->warning('herald: digest job migrated, campaign params dropped', ['job_id' => $jobId] + $dropped);
    }
    return $params;
  }

  /**
   * Strip the retired keys and return what was removed.
   *
   * @param array<string, mixed> $params
   * @return array<string, mixed>
   */
  private static function withoutRetired(array &$params): array {
    $dropped = array_intersect_key($params, array_flip(self::RETIRED_KEYS));
    $params = array_diff_key($params, $dropped);
    return $dropped;
  }

}

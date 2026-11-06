<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Core\HookInterface;
use Civi\Core\Service\AutoService;
use Civi\Herald\Api4\Rows;
use Civi\Herald\Buffer\BufferChecks;
use Civi\Herald\Topic\Router;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * System check: an active Herald.notifyDigest job that names no digest, a missing one, or a
 * topic delivery cannot resolve fails before it can send its failure notice, so only the job log shows it.
 *
 * @service herald.digest_jobs_check
 */
final class DigestJobsCheck extends AutoService implements HookInterface {

  public const NAME = 'herald_digest_jobs';

  /**
   * @param array<int, \CRM_Utils_Check_Message> $messages
   */
  public function hook_civicrm_check(array &$messages): void {
    $message = BufferChecks::guarded(self::NAME, self::message(...));
    if ($message !== NULL) {
      $messages[] = $message;
    }
  }

  private static function message(): ?\CRM_Utils_Check_Message {
    $broken = self::broken();
    if ($broken === []) {
      return NULL;
    }
    return new \CRM_Utils_Check_Message(
      self::NAME,
      E::ts(
        'These scheduled jobs cannot send their digest and fail on every run: %1. Give each one an existing digest and a configured topic, or disable it.',
        [1 => htmlspecialchars(implode('; ', $broken))],
      ),
      E::ts('Herald: digest jobs that cannot run'),
      // PSR-3 level; Psr\Log\LogLevel is only a transitive dependency here.
      'error',
      'fa-calendar-times-o',
    );
  }

  /**
   * @return list<string> "‹job name› (‹reason›)" per active job that cannot run.
   */
  public static function broken(): array {
    $jobs = \Civi\Api4\Job::get(checkPermissions: FALSE)
      ->addSelect('name', 'parameters')
      ->addWhere('api_entity', '=', 'Herald')
      ->addWhere('api_action', '=', 'notifyDigest')
      ->addWhere('is_active', '=', TRUE)
      ->execute();
    $broken = [];
    foreach (Rows::of($jobs) as $job) {
      $params = self::paramsOf(Cast::toString($job['parameters'] ?? ''));
      $digest = Cast::toString($params['digest'] ?? '');
      $delivery = is_array($params['actionParams'] ?? NULL) ? $params['actionParams'] : [];
      $reason = match (TRUE) {
        $digest === '' => E::ts('no digest'),
        !self::digestExists($digest) => E::ts('digest "%1" does not exist', [1 => $digest]),
        default => self::routeError(Cast::toString($delivery['topic'] ?? '')),
      };
      if ($reason !== NULL) {
        $broken[] = Cast::toString($job['name'] ?? '') . " ({$reason})";
      }
    }
    return $broken;
  }

  /**
   * Why delivery cannot resolve the topic, NULL when it can or the job names none.
   */
  private static function routeError(string $topic): ?string {
    if ($topic === '') {
      return NULL;
    }
    try {
      (new Router())->resolve($topic);
      return NULL;
    }
    catch (\CRM_Core_Exception $e) {
      return $e->getMessage();
    }
  }

  /**
   * Looked up like Definition::load(), so the database collation decides.
   */
  private static function digestExists(string $name): bool {
    return \Civi\Api4\HeraldDigest::get(checkPermissions: FALSE)
      ->addWhere('name', '=', $name)
      ->selectRowCount()
      ->execute()
      ->countMatched() > 0;
  }

  /**
   * The parameters the job manager would pass; unparsable ones name no digest.
   *
   * @return array<array-key, mixed>
   */
  private static function paramsOf(string $parameters): array {
    try {
      return \CRM_Core_BAO_Job::parseParameters($parameters);
    }
    catch (\CRM_Core_Exception) {
      return [];
    }
  }

}

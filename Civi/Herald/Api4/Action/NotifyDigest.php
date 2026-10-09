<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Api4\Generic\AbstractScheduledJob;
use Civi\Api4\Generic\Result;
use Civi\Herald\Digest\Composer;
use Civi\Herald\Dispatch\Dispatcher;

/**
 * Compose and deliver a digest. Needs no trigger entity; a scheduled job runs
 * it as an APIv4 job (parameters {"version": 4, "checkPermissions": false, …}).
 *
 * @method $this setActionParams(array<string, mixed> $actionParams)
 * @method $this setSendHour(?int $sendHour)
 */
class NotifyDigest extends AbstractScheduledJob {

  /**
   * Parameter bag — the Dispatcher keys plus the digest blocks or the single
   * block's own keys. See Civi\Herald\Digest\Composer.
   *
   * @var array
   * @phpstan-var array<string, mixed>
   */
  protected array $actionParams = [];

  /**
   * Send only during this hour (0–23, CiviCRM's timezone), for a job that runs
   * Hourly: a Daily job fires at the first cron run after midnight.
   *
   * @var int|null
   */
  protected ?int $sendHour = NULL;

  public function _run(Result $result): void {
    $hour = self::currentHour();
    if ($this->sendHour !== NULL && $this->sendHour !== $hour) {
      $result[] = ['mode' => 'skipped', 'send_hour' => $this->sendHour, 'current_hour' => $hour];
      return;
    }
    $digest = (new Composer())->compose($this->actionParams);
    // No amount: summing the period totals of buffered digests would double-count.
    $mode = (new Dispatcher())->dispatchRendered($this->actionParams, $digest['body'], $digest['subject'], NULL);
    $result[] = ['mode' => $mode, 'blocks' => $digest['blocks'], 'count' => $digest['count']];
  }

  private static function currentHour(): int {
    $zone = \CRM_Core_Config::singleton()->userSystem->getTimeZoneString() ?: date_default_timezone_get();
    return (int) (new \DateTime('now', new \DateTimeZone($zone)))->format('G');
  }

}

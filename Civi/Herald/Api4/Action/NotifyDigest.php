<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Api4\Generic\AbstractScheduledJob;
use Civi\Api4\Generic\Result;
use Civi\Herald\Digest\Composer;
use Civi\Herald\Digest\Definition;
use Civi\Herald\Dispatch\Dispatcher;
use Civi\Herald\Schedule\Clock;
use Civi\Herald\Schedule\ScheduleParams;

/**
 * Compose and deliver a digest. Needs no trigger entity. Rules send on a schedule
 * via the "Herald: Schedule" trigger; scripts, crontab and APIv4 jobs call this.
 *
 * @method $this setDigest(string $digest)
 * @method $this setActionParams(array<string, mixed> $actionParams)
 * @method $this setSendHour(?int $sendHour)
 */
class NotifyDigest extends AbstractScheduledJob {

  /**
   * Name of the HeraldDigest to send.
   *
   * @var string
   * @required
   */
  protected string $digest = '';

  /**
   * Delivery settings: the Dispatcher keys (channel, chat_id, delivery_mode, …).
   *
   * @var array
   * @phpstan-var array<string, mixed>
   */
  protected array $actionParams = [];

  /**
   * Send only during this hour (0–23, CiviCRM's timezone). Scheduled jobs drift
   * by up to one cron interval per run, so an Hourly job can miss the hour.
   *
   * @var int|null
   */
  protected ?int $sendHour = NULL;

  public function _run(Result $result): void {
    $sendHour = $this->sendHour === NULL ? NULL : ScheduleParams::hour($this->sendHour, 'sendHour');
    $hour = (int) Clock::now()->format('G');
    if ($sendHour !== NULL && $sendHour !== $hour) {
      $result[] = ['mode' => 'skipped', 'send_hour' => $sendHour, 'current_hour' => $hour];
      return;
    }
    $digest = (new Composer())->compose(Definition::load($this->digest));
    // No amount: summing the period totals of buffered digests would double-count.
    $mode = (new Dispatcher())->dispatchRendered($this->actionParams, $digest['body'], $digest['subject'], NULL);
    $result[] = ['mode' => $mode, 'blocks' => $digest['blocks'], 'count' => $digest['count']];
  }

}

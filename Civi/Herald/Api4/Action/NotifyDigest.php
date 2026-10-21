<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Api4\Generic\AbstractScheduledJob;
use Civi\Api4\Generic\Result;
use Civi\Herald\Channel\PlainText;
use Civi\Herald\Digest\Composer;
use Civi\Herald\Digest\Definition;
use Civi\Herald\Digest\DigestProblem;
use Civi\Herald\Digest\DigestRules;
use Civi\Herald\Digest\NothingToReport;
use Civi\Herald\Dispatch\Dispatcher;
use Civi\Herald\Schedule\SendWindow;
use CRM_Herald_ExtensionUtil as E;

/**
 * Compose and deliver a digest; if it cannot be composed, its recipients get
 * a short notice and the error is rethrown. Needs no trigger entity. Rules send on a schedule
 * via the "Herald: Schedule" trigger; scripts, crontab and APIv4 jobs call this.
 *
 * @method $this setDigest(string $digest)
 * @method $this setActionParams(array<string, mixed> $actionParams)
 * @method $this setSendHour(?int $sendHour)
 * @method $this setDaily(bool $daily)
 * @method $this setSkipEmpty(bool $skipEmpty)
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

  /**
   * With sendHour: send on the first run at or after sendHour, once a day per
   * digest and delivery settings, so a job can run on every cron run.
   *
   * @var bool
   */
  protected bool $daily = FALSE;

  /**
   * Send nothing when every source is a search display without rows.
   *
   * @var bool
   */
  protected bool $skipEmpty = FALSE;

  public function _run(Result $result): void {
    $skipped = $this->daily
      ? SendWindow::dailyFromHour(
        $this->sendHour ?? throw new \CRM_Core_Exception('herald: daily needs a sendHour'),
        $this->digest,
        $this->actionParams,
      )
      : SendWindow::duringHour($this->sendHour);
    if ($skipped !== NULL) {
      $result[] = $skipped;
      return;
    }
    $dispatcher = new Dispatcher();
    $title = $this->digest;
    try {
      $problem = DigestRules::deliveryProblem(Dispatcher::withTopic($this->actionParams));
      if ($problem !== NULL) {
        throw new DigestProblem([$problem]);
      }
      $digest = Definition::load($this->digest);
      $title = $digest['title'];
      $composer = new Composer();
      $values = $composer->values($digest);
      if ($this->skipEmpty && NothingToReport::in($digest, $values)) {
        $result[] = ['mode' => 'skipped', 'empty' => TRUE, 'sources' => count($digest['sources'])];
        return;
      }
      $message = $composer->render($digest, $values);
    }
    catch (\Throwable $e) {
      $this->notifyFailure($dispatcher, $title, $e);
      throw $e;
    }
    // No amount: summing the totals of buffered digests would double-count.
    $mode = $dispatcher->dispatchRendered($this->actionParams, $message['body'], $message['subject'], NULL);
    $result[] = ['mode' => $mode, 'sources' => $message['sources']];
  }

  /**
   * Tells the recipients that the digest is missing. Only herald's own
   * problems are quoted; other errors may carry data and stay in the log.
   */
  private function notifyFailure(Dispatcher $dispatcher, string $title, \Throwable $e): void {
    $reason = $e instanceof DigestProblem ? $e->getMessage() : E::ts('an unexpected error, see the CiviCRM log.');
    $text = E::ts('⚠️ Digest "%1" could not be composed: %2', [1 => $title, 2 => $reason]);
    try {
      $dispatcher->dispatchRendered(
        $this->actionParams,
        PlainText::toHtml($text),
        E::ts('Digest "%1" failed', [1 => $title]),
        NULL,
      );
    }
    catch (\Throwable $sendError) {
      \Civi::log()->error('herald: the failure notice of digest {digest} was not delivered: {error}', [
        'digest' => $this->digest,
        'error' => $sendError->getMessage(),
      ]);
    }
  }

}

<?php

declare(strict_types = 1);

namespace Civi\Herald\Buffer;

use Civi\Api4\HeraldBufferedAlert;
use Civi\Herald\Api4\Rows;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Anti-spam aggregation buffer for realtime alerts.
 *
 * When a send has aggregate_minutes > 0, the rendered alert
 * body is parked here instead of being sent immediately. The scheduled job
 * Job.herald_buffer_flush (run_frequency "Always") drains the buffer: a group
 * becomes due once its OLDEST entry is older than the configured window, and
 * everything collected for that group by then goes out as ONE message.
 *
 * Grouping — all entries written by the same rule action (identical action
 * params → identical group_hash) aggregate together; two different rules or
 * two differently-configured actions never mix.
 *
 * Concurrency — callers hold LOCK_NAME while they claim, so overlapping cron
 * runs never compose the same entries; claimDue() stamps the rows it hands
 * out, so a run that dies with them leaves them claimed, not lost.
 *
 * Dead letter — rows that cannot be delivered are parked rather than retried
 * forever or dropped; claimDue() skips parked rows, the rest lives in
 * DeadLetter.
 */
class AlertBuffer {

  public const TABLE = 'civicrm_herald_buffer';

  /**
   * Lock name for the flush job, mirroring the monitor's worker.herald.monitor.
   */
  public const LOCK_NAME = 'worker.herald.buffer';

  /**
   * A claim older than this belongs to a run that died between claiming and
   * deleting; its rows are offered again rather than stranded in the buffer.
   */
  private const CLAIM_TIMEOUT_MINUTES = 30;

  /**
   * Park one rendered alert body for later aggregated delivery.
   *
   * @param array<string, mixed> $delivery Full action params — everything
   *   Delivery::send() needs to send the composed message later.
   * @param float|null $amount Monetary value of the underlying event (e.g.
   *   contribution.total_amount) — summed into the aggregate header.
   */
  public function add(
    string $groupHash,
    int $windowMinutes,
    string $body,
    array $delivery,
    ?float $amount = NULL,
  ): void {
    // Serialise before touching the DB: an alert that cannot carry its
    // delivery params must not be buffered at all.
    $deliveryJson = self::encodeDelivery($delivery);
    // The BAO stores body and delivery verbatim; APIv4 would turn their escaped
    // `&lt;`/`&gt;` into markup on the way back.
    \CRM_Herald_BAO_HeraldBufferedAlert::writeRecord([
      'group_hash' => $groupHash,
      'window_minutes' => max(1, $windowMinutes),
      'body' => $body,
      'delivery' => $deliveryJson,
      'amount' => $amount,
    ]);
  }

  /**
   * Serialise the delivery params. A failure aborts the buffering: empty
   * params would send the alert to the channel defaults, not its recipient.
   *
   * @param array<string, mixed> $delivery
   */
  private static function encodeDelivery(array $delivery): string {
    try {
      return json_encode($delivery, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      throw new \CRM_Core_Exception(
        'herald: could not serialise the delivery params of a buffered alert: ' . $e->getMessage(),
        0,
        [],
        $e,
      );
    }
  }

  /**
   * Take ownership of every group whose oldest unclaimed entry has waited at
   * least its window, and return what was claimed.
   *
   * Callers hold LOCK_NAME: the claim is one APIv4 update per group, which
   * reads the rows before it stamps them and so is not atomic on its own.
   *
   * Rows are NOT deleted here: the caller deletes via deleteRows() once the
   * composed message went out, and hands them back via release() when the send
   * failed, so a failure retries on the next cron run instead of silently
   * dropping alerts.
   *
   * @return list<array{group_hash: string, window: int, ids: list<int>, bodies: list<string>, amounts: list<float>, delivery: array<string, mixed>}>
   */
  public function claimDue(): array {
    $this->releaseAbandonedClaims();
    $claim = bin2hex(random_bytes(16));
    $groups = [];
    foreach ($this->dueGroupHashes() as $groupHash) {
      HeraldBufferedAlert::update(checkPermissions: FALSE)
        ->addWhere('group_hash', '=', $groupHash)
        ->addWhere('claimed_by', 'IS NULL')
        ->addWhere('parked_at', 'IS NULL')
        ->setValues(['claimed_by' => $claim, 'claimed_at' => 'now'])
        ->execute();
      $group = $this->readClaimed($groupHash, $claim);
      if ($group !== NULL) {
        $groups[] = $group;
      }
    }
    return $groups;
  }

  /**
   * Group hashes whose oldest UNCLAIMED entry has waited at least its window.
   *
   * @return list<string>
   */
  private function dueGroupHashes(): array {
    $groups = HeraldBufferedAlert::get(checkPermissions: FALSE)
      ->addSelect(
        'group_hash',
        'TIMESTAMPDIFF(SECOND, MIN(created_date), NOW()) AS waited',
        'MAX(window_minutes) AS window',
      )
      ->addWhere('claimed_by', 'IS NULL')
      ->addWhere('parked_at', 'IS NULL')
      ->addGroupBy('group_hash')
      ->execute();
    $hashes = [];
    foreach (Rows::of($groups) as $group) {
      if (Cast::toInt($group['waited']) < (Cast::toInt($group['window']) * 60)) {
        continue;
      }
      $hashes[] = Cast::toString($group['group_hash']);
    }
    return $hashes;
  }

  /**
   * The rows one claim actually holds for a group, narrowed to the declared
   * types. NULL when a concurrent run claimed the group first.
   *
   * @return array{group_hash: string, window: int, ids: list<int>, bodies: list<string>, amounts: list<float>, delivery: array<string, mixed>}|null
   */
  private function readClaimed(string $groupHash, string $claim): ?array {
    // Read through the DAO, as add() writes: APIv4 would decode the bodies.
    $dao = new \CRM_Herald_BAO_HeraldBufferedAlert();
    $dao->group_hash = $groupHash;
    $dao->claimed_by = $claim;
    $dao->orderBy('id');
    $dao->find();
    $ids = [];
    $bodies = [];
    $amounts = [];
    $window = 0;
    $deliveryParams = [];
    while ($dao->fetch()) {
      $ids[] = Cast::toInt($dao->id);
      $bodies[] = Cast::toString($dao->body);
      if ($dao->amount !== NULL) {
        $amounts[] = Cast::toFloat($dao->amount);
      }
      $window = max($window, Cast::toInt($dao->window_minutes));
      $delivery = json_decode(Cast::toString($dao->delivery), TRUE);
      if ($deliveryParams === [] && is_array($delivery)) {
        // First decodable row wins: the group's rows share the rule config, but
        // each carries the subject ITS OWN event rendered — composeDelivery()
        // replaces that for an aggregate.
        /** @var array<string, mixed> $deliveryParams */
        $deliveryParams = $delivery;
      }
    }
    if (!$ids) {
      return NULL;
    }
    return [
      'group_hash' => $groupHash,
      'window' => $window,
      'ids' => $ids,
      'bodies' => $bodies,
      'amounts' => $amounts,
      'delivery' => $deliveryParams,
    ];
  }

  /**
   * Offer rows again whose claiming run never finished (crash, killed cron);
   * without this their alerts would sit claimed in the buffer forever.
   */
  private function releaseAbandonedClaims(): void {
    HeraldBufferedAlert::update(checkPermissions: FALSE)
      // claimDue() stamps with APIv4's "now", PHP's clock; compare on the same one.
      ->addWhere('claimed_at', '<', date('YmdHis', time() - (self::CLAIM_TIMEOUT_MINUTES * 60)))
      ->addWhere('parked_at', 'IS NULL')
      ->setValues(['claimed_by' => NULL, 'claimed_at' => NULL])
      ->execute();
  }

  /**
   * Hand claimed rows back to the buffer — used when the composed message
   * could not be delivered, so the next run retries them.
   *
   * @param list<int> $ids
   */
  public function release(array $ids): void {
    if (!$ids) {
      return;
    }
    HeraldBufferedAlert::update(checkPermissions: FALSE)
      ->addWhere('id', 'IN', $ids)
      ->setValues(['claimed_by' => NULL, 'claimed_at' => NULL])
      ->execute();
  }

  /**
   * @param list<int> $ids
   */
  public function deleteRows(array $ids): void {
    if (!$ids) {
      return;
    }
    HeraldBufferedAlert::delete(checkPermissions: FALSE)
      ->addWhere('id', 'IN', $ids)
      ->execute();
  }

}

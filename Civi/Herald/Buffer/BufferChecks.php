<?php

declare(strict_types = 1);

namespace Civi\Herald\Buffer;

use Civi\Core\HookInterface;
use Civi\Core\Service\AutoService;
use Civi\Herald\Api4\Rows;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * System checks on herald's own delivery pipeline. Parked rows stay in the
 * table by design, so they get their own check instead of counting as backlog.
 *
 * @service herald.buffer_checks
 */
final class BufferChecks extends AutoService implements HookInterface {

  public const BACKLOG = 'herald_buffer_backlog';

  public const DEAD_LETTER = 'herald_buffer_dead_letter';

  /**
   * @param array<int, \CRM_Utils_Check_Message> $messages
   */
  public function hook_civicrm_check(array &$messages): void {
    foreach ([self::BACKLOG => self::backlog(...), self::DEAD_LETTER => self::deadLetter(...)] as $name => $check) {
      $message = self::guarded($name, $check);
      if ($message !== NULL) {
        $messages[] = $message;
      }
    }
  }

  /**
   * A check that throws is reported as an error instead of breaking the status page.
   *
   * @param \Closure(): ?\CRM_Utils_Check_Message $check
   */
  public static function guarded(string $name, \Closure $check): ?\CRM_Utils_Check_Message {
    try {
      return $check();
    }
    catch (\Throwable $e) {
      return new \CRM_Utils_Check_Message(
        $name,
        E::ts('Could not check: %1', [1 => htmlspecialchars($e->getMessage())]),
        E::ts('Herald: buffer check failed'),
        // PSR-3 level; Psr\Log\LogLevel is only a transitive dependency here.
        'error',
        'fa-exclamation-triangle',
      );
    }
  }

  public static function backlog(): ?\CRM_Utils_Check_Message {
    $rows = \Civi\Api4\HeraldBufferedAlert::get(checkPermissions: FALSE)
      ->addSelect('TIMESTAMPDIFF(SECOND, created_date, NOW()) AS age', 'window_minutes')
      ->addWhere('parked_at', 'IS NULL')
      ->execute();
    $stuck = 0;
    foreach (Rows::of($rows) as $row) {
      $stuck += Cast::toInt($row['age'] ?? NULL) > (Cast::toInt($row['window_minutes'] ?? NULL) * 180) ? 1 : 0;
    }
    if ($stuck === 0) {
      return NULL;
    }
    return new \CRM_Utils_Check_Message(
      self::BACKLOG,
      E::ts(
        '%1 buffered alert(s) are older than three times their window. The job "Herald: Flush alert aggregation buffer" is not delivering them.',
        [1 => $stuck],
      ),
      E::ts('Herald: buffered alerts not delivered'),
      'error',
      'fa-hourglass-end',
    );
  }

  public static function deadLetter(): ?\CRM_Utils_Check_Message {
    $parked = \Civi\Api4\HeraldBufferedAlert::get(checkPermissions: FALSE)
      ->selectRowCount()
      ->addWhere('parked_at', 'IS NOT NULL')
      ->execute()
      ->count();
    if ($parked === 0) {
      return NULL;
    }
    return new \CRM_Utils_Check_Message(
      self::DEAD_LETTER,
      E::ts(
        '%1 alert(s) are parked in the dead letter. Inspect them under Administer » Herald » Parked alerts or with Herald.getParkedAlerts.',
        [1 => $parked],
      ),
      E::ts('Herald: parked alerts'),
      'warning',
      'fa-inbox',
    );
  }

}

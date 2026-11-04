<?php

declare(strict_types = 1);

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Older releases wrote the monitor state on every run, and detailed logging
 * copied each write into log_civicrm_setting. Those copies carry no history.
 */
final class CRM_Herald_Upgrader_MonitorLog {

  /**
   * Deletes in batches, so no single statement holds a large undo log.
   *
   * @return int Log rows deleted.
   */
  public static function purge(int $batchSize = 10_000): int {
    $table = self::logTable();
    if ($table === NULL) {
      return 0;
    }
    $deleted = 0;
    do {
      /** @var CRM_Core_DAO $dao */
      $dao = CRM_Core_DAO::executeQuery(
        "DELETE FROM {$table} WHERE name = %1 LIMIT " . $batchSize,
        [1 => [\Civi\Herald\Monitor\Monitor::STATE_SETTING, 'String']],
        i18nRewrite: FALSE,
      );
      // DB_DataObject::affectedRows() carries no PHPDoc; the MySQL drivers return the row count.
      /** @var int|string|false $affected */
      $affected = $dao->affectedRows();
      $batch = (int) $affected;
      $deleted += $batch;
    } while ($batch === $batchSize);
    return $deleted;
  }

  /**
   * The log table lives in the logging database when CIVICRM_LOGGING_DSN names one.
   */
  private static function logTable(): ?string {
    $dsn = constant(defined('CIVICRM_LOGGING_DSN') ? 'CIVICRM_LOGGING_DSN' : 'CIVICRM_DSN');
    $parsed = DB::parseDSN(CRM_Utils_SQL::autoSwitchDSN(Cast::toString($dsn)));
    $database = Cast::toString($parsed['database'] ?? '');
    $exists = CRM_Core_DAO::singleValueQuery(
      'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %1 AND TABLE_NAME = %2',
      [1 => [$database, 'String'], 2 => ['log_civicrm_setting', 'String']],
    );
    return Cast::toInt($exists) === 1 ? "`{$database}`.`log_civicrm_setting`" : NULL;
  }

}

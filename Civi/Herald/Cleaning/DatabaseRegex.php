<?php

declare(strict_types = 1);

namespace Civi\Herald\Cleaning;

/**
 * The database runs the cleaning patterns, so the database compiles them.
 */
final class DatabaseRegex {

  // MariaDB's ER_REGEXP_ERROR; MySQL 8's ER_REGEXP_* (3691 measured for "(unclosed").
  private const MARIADB_ERROR = 1139;

  private const MYSQL_ERRORS = [3685, 3700];

  private const MYSQL_GROUP_NAME_ERROR = 3887;

  /**
   * Why the pattern does not compile, NULL when it does.
   */
  public static function error(string $pattern): ?string {
    try {
      \CRM_Core_DAO::singleValueQuery("SELECT '' REGEXP %1", [1 => [$pattern, 'String']]);
      return NULL;
    }
    catch (\Civi\Core\Exception\DBQueryException $e) {
      if (!self::isRegexError($e->getSQLErrorCode())) {
        throw $e;
      }
      return $e->getDBErrorMessage();
    }
  }

  private static function isRegexError(int $code): bool {
    [$first, $last] = self::MYSQL_ERRORS;
    return $code === self::MARIADB_ERROR || $code === self::MYSQL_GROUP_NAME_ERROR || $code >= $first && $code <= $last;
  }

}

<?php

declare(strict_types = 1);

namespace Civi\Herald\Cleaning;

/**
 * The SQL of a cleaned field: the first matching rule names the group, an empty
 * value stays empty, and anything else keeps its text unless a fallback names it.
 */
final class CaseExpression {

  /**
   * @param list<array{match: string, type: string, label: string}> $rules
   */
  public static function sql(string $column, array $rules, string $fallback): string {
    if ($rules === [] && $fallback === '') {
      return $column;
    }
    $sql = "CASE WHEN {$column} IS NULL OR {$column} = '' THEN {$column}";
    foreach ($rules as $rule) {
      $sql .= ' WHEN ' . self::condition($column, $rule) . ' THEN ' . self::quote($rule['label']);
    }
    return $sql . ' ELSE ' . ($fallback === '' ? $column : self::quote($fallback)) . ' END';
  }

  /**
   * @param array{match: string, type: string, label: string} $rule
   */
  private static function condition(string $column, array $rule): string {
    $like = static fn (string $pattern): string => "{$column} LIKE " . self::quote($pattern);
    $text = addcslashes($rule['match'], '\\%_');
    return match ($rule['type']) {
      'contains' => $like("%{$text}%"),
      'prefix' => $like("{$text}%"),
      default => "{$column} REGEXP " . self::quote($rule['match']),
    };
  }

  private static function quote(string $value): string {
    return "'" . \CRM_Core_DAO::escapeString($value) . "'";
  }

}

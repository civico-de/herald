<?php

declare(strict_types = 1);

namespace Civi\Herald\Monitor;

use CRM_Herald_ExtensionUtil as E;

/**
 * The severity from which a check key counts as failing: the global level or
 * the longest matching exception.
 */
final class Threshold {

  public const MIN_SETTING = 'herald_monitor_min_severity';

  public const EXCEPTIONS_SETTING = 'herald_monitor_severity';

  /**
   * @param array<string, int> $exceptions Key pattern => severity id.
   */
  public function __construct(
    private readonly int $min,
    private readonly array $exceptions = [],
  ) {}

  /**
   * @throws \CRM_Core_Exception A stored level a threshold may not use.
   */
  public static function fromSettings(): self {
    $min = \Civi::settings()->get(self::MIN_SETTING);
    $exceptions = \Civi::settings()->get(self::EXCEPTIONS_SETTING);
    $problems = array_filter([self::minProblem($min), ...self::exceptionsProblems($exceptions)]);
    if ($problems !== []) {
      throw new \CRM_Core_Exception(implode("\n", $problems));
    }
    /** @var string $min */
    /** @var array<string, string> $exceptions */
    return new self(
      (int) Severity::id($min),
      array_map(static fn (string $level): int => (int) Severity::id($level), $exceptions),
    );
  }

  public function minFor(string $key): int {
    return KeyPattern::lookup($this->exceptions, $key) ?? $this->min;
  }

  public function fails(string $key, int $severity): bool {
    return $severity >= $this->minFor($key);
  }

  public static function minProblem(mixed $level): ?string {
    if (is_string($level) && in_array($level, Severity::thresholds(), TRUE)) {
      return NULL;
    }
    return E::ts('The monitor threshold must be one of %1.', [1 => implode(', ', Severity::thresholds())]);
  }

  /**
   * @return list<string>
   */
  public static function exceptionsProblems(mixed $map): array {
    if (!is_array($map)) {
      return [E::ts('The monitor threshold exceptions must map check keys to levels.')];
    }
    $problems = [];
    foreach ($map as $pattern => $level) {
      if (!KeyPattern::isValid((string) $pattern)) {
        $problems[] = E::ts('"%1" is not a check key or a prefix ending in *.', [1 => $pattern]);
        continue;
      }
      if (!is_string($level) || !in_array($level, Severity::thresholds(), TRUE)) {
        $problems[] = E::ts('The threshold of "%1" must be one of %2.', [
          1 => $pattern,
          2 => implode(', ', Severity::thresholds()),
        ]);
      }
    }
    return $problems;
  }

  /**
   * validate_callback of the global threshold setting.
   *
   * @throws \CRM_Core_Exception
   */
  public static function validateMin(mixed &$value): bool {
    $problem = self::minProblem($value);
    if ($problem !== NULL) {
      throw new \CRM_Core_Exception($problem);
    }
    return TRUE;
  }

  /**
   * validate_callback of the exceptions setting.
   *
   * @throws \CRM_Core_Exception
   */
  public static function validateExceptions(mixed &$value): bool {
    $problems = self::exceptionsProblems($value);
    if ($problems !== []) {
      throw new \CRM_Core_Exception(implode("\n", $problems));
    }
    return TRUE;
  }

}

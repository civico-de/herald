<?php

namespace Civi\Herald\Digest;

/**
 * Applies the first configured source-normalization rule that matches.
 */
final class SourceNormalizer {

  /** @var list<array{match: string, type: string, label: string}>|null */
  private static ?array $cachedRules = NULL;

  /** @var list<array{match: string, type: string, label: string}> */
  private array $rules;

  /**
   * Passing rules is a test seam; production callers use the process-cached setting.
   *
   * @param array<mixed>|null $rules
   */
  public function __construct(?array $rules = NULL) {
    $this->rules = self::validatedRules($rules ?? self::settingRules());
  }

  public function isEnabled(): bool {
    return $this->rules !== [];
  }

  public function normalize(string $source): string {
    foreach ($this->rules as $rule) {
      $matches = match ($rule['type']) {
        'contains' => str_contains($source, $rule['match']),
        'prefix' => str_starts_with($source, $rule['match']),
        'regex' => @preg_match($rule['match'], $source) === 1,
        default => FALSE,
      };
      if ($matches) {
        return $rule['label'];
      }
    }
    return $source;
  }

  /**
   * @return array<mixed>
   */
  private static function settingRules(): array {
    if (self::$cachedRules === NULL) {
      $configured = \Civi::settings()->get('herald_source_normalization');
      self::$cachedRules = is_array($configured) ? self::validatedRules($configured) : [];
    }
    return self::$cachedRules;
  }

  /**
   * Ignore malformed rules so an optional presentation setting cannot break alerts.
   *
   * @param array<mixed> $rules
   * @return list<array{match: string, type: string, label: string}>
   */
  private static function validatedRules(array $rules): array {
    $valid = [];
    foreach ($rules as $rule) {
      if (!is_array($rule)
        || !isset($rule['match'], $rule['type'], $rule['label'])
        || !is_string($rule['match'])
        || !is_string($rule['type'])
        || !is_string($rule['label'])
        || !in_array($rule['type'], ['contains', 'prefix', 'regex'], TRUE)
      ) {
        continue;
      }
      $valid[] = [
        'match' => $rule['match'],
        'type' => $rule['type'],
        'label' => $rule['label'],
      ];
    }
    return $valid;
  }

}

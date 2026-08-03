<?php

namespace Civi\Herald\Digest;

/**
 * Applies the first configured source-normalization rule that matches.
 */
final class SourceNormalizer {

  /**
   * @var list<array{match: string, type: string, label: string}>|null */
  private static ?array $cachedRules = NULL;

  /**
   * @var list<array{match: string, type: string, label: string}> */
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
        // @mago-expect lint:no-error-control-operator
        // Deliberate: admin-supplied regexes may be invalid; the warning is not actionable.
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
    if (self::$cachedRules !== NULL) {
      return self::$cachedRules;
    }
    // Only a real answer is worth remembering. Caching the no-container
    // fallback would make the result depend on who asked first: one unit test
    // touching a SourceNormalizer before the container is up would pin an
    // empty rule set for every headless test after it in the same process.
    if (!\Civi\Core\Container::isContainerBooted()) {
      return [];
    }
    self::$cachedRules = self::validatedRules(self::readSetting());
    return self::$cachedRules;
  }

  /**
   * Read the setting, tolerating a Civi that cannot answer.
   *
   * Normalization is an optional presentation nicety, so "no Civi to ask" is
   * the same answer as "nothing configured": no rules. The caller checks the
   * container first — without that, merely CONSTRUCTING a
   * ContributionStatsService (whose default collaborator is a
   * SourceNormalizer) reaches for the database, which took the pure @group
   * unit tests down with a DB_DataObject error. class_exists('\Civi') is not
   * enough: the classloader is up long before the container is, and the
   * settings read then dies inside PEAR rather than throwing.
   *
   * @return array<mixed>
   */
  private static function readSetting(): array {
    try {
      $configured = \Civi::settings()->get('herald_source_normalization');
    }
    catch (\Throwable $e) {
      return [];
    }
    return is_array($configured) ? $configured : [];
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
      if (
        !is_array($rule)
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

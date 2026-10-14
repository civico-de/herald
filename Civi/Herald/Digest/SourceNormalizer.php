<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Herald\Admin\SourceRule;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

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
    $this->rules = $rules === NULL ? self::settingRules() : self::validatedRules($rules);
  }

  public function normalize(string $source): string {
    foreach ($this->rules as $rule) {
      $matches = match ($rule['type']) {
        'contains' => str_contains($source, $rule['match']),
        'prefix' => str_starts_with($source, $rule['match']),
        'regex' => preg_match($rule['match'], $source) === 1,
        default => FALSE,
      };
      if ($matches) {
        return $rule['label'];
      }
    }
    return $source;
  }

  /**
   * @return list<array{match: string, type: string, label: string}>
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
   * @return array<mixed>
   */
  private static function readSetting(): array {
    $configured = \Civi::settings()->get('herald_source_normalization');
    return is_array($configured) ? $configured : [];
  }

  /**
   * Setting.set skips the admin screen's checks; a rule that fails them is left
   * out with a warning, so a presentation setting cannot break alerts.
   *
   * @param array<mixed> $rules
   * @return list<array{match: string, type: string, label: string}>
   */
  private static function validatedRules(array $rules): array {
    $valid = [];
    foreach (array_values($rules) as $i => $rule) {
      $rule = is_array($rule) ? $rule : [];
      $problems = SourceRule::problems($rule['match'] ?? NULL, $rule['type'] ?? NULL, $rule['label'] ?? NULL);
      if ($problems !== []) {
        \Civi::log('herald')->warning('herald: source rule {rule} left out', [
          'rule' => $i + 1,
          'problems' => $problems,
        ]);
        continue;
      }
      $valid[] = [
        'match' => Cast::toString($rule['match']),
        'type' => Cast::toString($rule['type']),
        'label' => Cast::toString($rule['label']),
      ];
    }
    return $valid;
  }

}

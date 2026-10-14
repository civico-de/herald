<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * What digest sources must satisfy: distinct names the template can use, a
 * known type with its field, and a display that can fill a digest.
 *
 * @phpstan-import-type Source from Definition
 */
final class SourceRules {

  /**
   * Smarty's own variable.
   */
  public const RESERVED = ['smarty'];

  /**
   * The problems of a digest's sources, each named by the source.
   *
   * @param list<Source> $sources
   * @return list<string>
   */
  public static function listProblems(array $sources): array {
    $problems = [];
    $names = array_column($sources, 'name');
    foreach (array_unique(array_diff_assoc($names, array_unique($names))) as $name) {
      $problems[] = E::ts('Two sources are named %1.', [1 => $name]);
    }
    foreach ($sources as $i => $source) {
      foreach (self::problems($source) as $problem) {
        $problems[] = E::ts('Source %1: %2', [1 => $source['name'] !== '' ? $source['name'] : $i + 1, 2 => $problem]);
      }
    }
    return $problems;
  }

  /**
   * @param array<array-key, mixed> $source HeraldDigestSource values
   * @return array<string, string> field => problem
   */
  public static function problems(array $source): array {
    $problems = array_filter(['name' => self::nameProblem(Cast::toString($source['name'] ?? ''))]);
    $type = Cast::toString($source['type'] ?? '');
    $problem = SourceType::problem(Cast::namedOnly($source));
    if ($problem !== NULL) {
      return $problems + [SourceType::REQUIRED[$type] ?? 'type' => ucfirst($problem)];
    }
    $display = $type === 'search' ? SearchSource::problem(Cast::toInt($source['search_display_id'])) : NULL;
    return $problems + array_filter(['search_display_id' => $display === NULL ? NULL : ucfirst($display)]);
  }

  private static function nameProblem(string $name): ?string {
    return match (TRUE) {
      $name === '' => E::ts('A source needs a name, e.g. donations for {$donations.sum}.'),
      in_array($name, self::RESERVED, TRUE) => E::ts('"%1" is a name Smarty reserves; choose another source name.', [
        1 => $name,
      ]),
      preg_match(Placeholders::NAME, $name) !== 1 => E::ts(
        'A source name starts with a lowercase letter, followed by lowercase letters, digits and underscores.',
      ),
      default => NULL,
    };
  }

}

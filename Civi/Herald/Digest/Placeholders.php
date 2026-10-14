<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use CRM_Herald_ExtensionUtil as E;

/**
 * Names of the values a source hands to the template. A search column is
 * named after its header: "Plätze gesamt" → {$source.plaetze_gesamt}.
 */
final class Placeholders {

  /**
   * Names of sources and values: Smarty reads `.1st` as an index.
   */
  public const NAME = '/^[a-z][a-z0-9_]*$/';

  /**
   * Holds every row of a source next to the first row's values.
   */
  public const ROWS = 'rows';

  /**
   * Lowercase ASCII with "_" for everything else; '' for a header without letters or digits.
   */
  public static function slug(string $label): string {
    $text = strtr(mb_strtolower(trim($label)), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    // Other accents lose their mark: é → e. Invalid UTF-8 does not decompose and keeps its marks.
    $text = (string) preg_replace('/\p{Mn}+/u', '', \Normalizer::normalize($text, \Normalizer::FORM_D) ?: $text);
    return trim((string) preg_replace('/[^a-z0-9]+/', '_', $text), '_');
  }

  /**
   * The name of each column with a header; headers that give no usable name are problems.
   *
   * @param array<int, string> $labels Column headers by column index
   * @return array{names: array<int, string>, problems: list<string>}
   */
  public static function fromLabels(array $labels): array {
    $names = [];
    $problems = [];
    foreach ($labels as $index => $label) {
      $name = self::slug($label);
      if ($name === '') {
        continue;
      }
      $problem = match (TRUE) {
        $name === self::ROWS
          => E::ts('the column "%1" is named rows, which holds the list of rows; rename its header.', [1 => $label]),
        preg_match(self::NAME, $name) !== 1
          => E::ts('the column "%1" starts with a digit; start its header with a letter.', [1 => $label]),
        in_array($name, $names, TRUE) => E::ts('two columns are named %1; give their headers different words.', [
          1 => $name,
        ]),
        default => NULL,
      };
      if ($problem !== NULL) {
        $problems[] = $problem;
        continue;
      }
      $names[$index] = $name;
    }
    return ['names' => $names, 'problems' => $problems];
  }

}

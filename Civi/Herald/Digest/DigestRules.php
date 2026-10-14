<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * What a digest must satisfy: a name rules can reference and no other digest
 * has, a known period, and at least one block, each of which can run.
 */
final class DigestRules {

  /**
   * Problems of the given HeraldDigest values; absent fields are not checked.
   *
   * @param array<array-key, mixed> $values
   * @param int|null $id The digest being updated, NULL for a new one
   * @return array<string, string> field => problem
   */
  public static function recordProblems(array $values, ?int $id): array {
    $name = Cast::toString($values['name'] ?? '');
    $problems = [
      'name' => $name === '' ? NULL : self::nameProblem($name, $id),
      'period' => Options::problem(E::ts('period'), $values['period'] ?? NULL, Options::periods()),
    ];
    return array_map(ucfirst(...), array_filter($problems));
  }

  /**
   * Why the blocks of a digest cannot be sent, one line per broken block.
   *
   * @param list<array<string, mixed>> $blocks
   * @return list<string>
   */
  public static function blockProblems(string $digestName, array $blocks): array {
    if ($blocks === []) {
      return [E::ts('Digest "%1" has no blocks.', [1 => $digestName])];
    }
    $problems = [];
    foreach ($blocks as $i => $block) {
      $problem = BlockType::problem($block);
      if ($problem !== NULL) {
        $problems[] = E::ts('Block %1: %2', [1 => $i + 1, 2 => $problem]);
      }
    }
    return $problems;
  }

  private static function nameProblem(string $name, ?int $id): ?string {
    if (preg_match('/^[a-z0-9_]+$/', $name) !== 1) {
      return E::ts('Use lowercase letters, digits and underscores for the name.');
    }
    $taken = \Civi\Api4\HeraldDigest::get(checkPermissions: FALSE)
      ->selectRowCount()
      ->addWhere('name', '=', $name)
      ->addWhere('id', '!=', $id ?? 0)
      ->execute()
      ->countMatched();
    return $taken > 0 ? E::ts('Another digest is already named "%1".', [1 => $name]) : NULL;
  }

}

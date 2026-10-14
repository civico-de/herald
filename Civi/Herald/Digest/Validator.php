<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Api4\Event\ValidateValuesEvent;
use Civi\Core\HookInterface;
use Civi\Core\Service\AutoService;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Refuses API saves of digests and blocks that could not be sent
 * (DigestRules, BlockType); the digest form is checked by FormValidator.
 *
 * @service herald.digest_validator
 */
final class Validator extends AutoService implements HookInterface {

  public function on_civi_api4_validate(ValidateValuesEvent $e): void {
    match ($e->getEntityName()) {
      'HeraldDigest' => self::validateDigests($e),
      'HeraldDigestBlock' => self::validateBlocks($e),
      default => NULL,
    };
  }

  /**
   * Per diff, not per record: a batch update has one record for many digests.
   */
  private static function validateDigests(ValidateValuesEvent $e): void {
    foreach (self::diffs($e) as $r => [$new, $old]) {
      $id = $old['id'] ?? NULL;
      foreach (DigestRules::recordProblems($new, $id === NULL ? NULL : Cast::toInt($id)) as $field => $problem) {
        $e->addError($r, $field, 'herald_digest', $problem);
      }
    }
  }

  /**
   * An update is checked together with the stored values it keeps.
   */
  private static function validateBlocks(ValidateValuesEvent $e): void {
    foreach (self::diffs($e) as $r => [$new, $old]) {
      $block = $new + $old;
      $problem = BlockType::problem($block);
      if ($problem !== NULL) {
        $field = BlockType::REQUIRED[Cast::toString($block['type'] ?? 'contribution')] ?? 'type';
        $e->addError($r, $field, 'herald_block', ucfirst($problem));
      }
    }
  }

  /**
   * @return array<array-key, array{array<string, mixed>, array<string, mixed>}> record key => [new values, stored values]
   */
  private static function diffs(ValidateValuesEvent $e): array {
    $diffs = [];
    foreach ($e->diffs as $r => $diff) {
      $diff = self::values($diff);
      $diffs[Cast::toString($r)] = [self::values($diff['new'] ?? NULL), self::values($diff['old'] ?? NULL)];
    }
    return $diffs;
  }

  /**
   * @return array<string, mixed>
   */
  private static function values(mixed $values): array {
    return is_array($values) ? Cast::namedOnly($values) : [];
  }

}

<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Api4\Event\ValidateValuesEvent;
use Civi\Core\HookInterface;
use Civi\Core\Service\AutoService;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * Refuses API saves of digests and sources that could not be sent
 * (DigestRules, SourceRules); the digest form is checked by FormValidator,
 * template edits by TemplateValidator.
 *
 * @service herald.digest_validator
 */
final class Validator extends AutoService implements HookInterface {

  public function on_civi_api4_validate(ValidateValuesEvent $e): void {
    match ($e->getEntityName()) {
      'HeraldDigest' => self::validateDigests($e),
      'HeraldDigestSource' => self::validateSources($e),
      default => NULL,
    };
  }

  /**
   * Per diff, not per record: a batch update has one record for many digests.
   */
  private static function validateDigests(ValidateValuesEvent $e): void {
    foreach (self::diffs($e) as $r => [$new, $old]) {
      foreach (DigestRules::recordProblems($new, Definition::idOf($old['id'] ?? NULL)) as $field => $problem) {
        $e->addError($r, $field, 'herald_digest', $problem);
      }
    }
  }

  /**
   * An update is checked together with the stored values it keeps.
   */
  private static function validateSources(ValidateValuesEvent $e): void {
    foreach (self::diffs($e) as $r => [$new, $old]) {
      $source = $new + $old;
      $problems = SourceRules::problems($source);
      if ($problems === [] && (isset($new['name']) || isset($new['digest_id'])) && self::nameTaken($source)) {
        $problems['name'] = E::ts('The digest has another source named %1.', [1 => Cast::toString($source['name'])]);
      }
      foreach ($problems as $field => $problem) {
        $e->addError($r, $field, 'herald_source', $problem);
      }
    }
  }

  /**
   * @param array<string, mixed> $source
   */
  private static function nameTaken(array $source): bool {
    return \Civi\Api4\HeraldDigestSource::get(checkPermissions: FALSE)
      ->selectRowCount()
      ->addWhere('digest_id', '=', $source['digest_id'] ?? 0)
      ->addWhere('name', '=', $source['name'])
      ->addWhere('id', '!=', $source['id'] ?? 0)
      ->execute()
      ->countMatched() > 0;
  }

  /**
   * @return array<array-key, array{array<string, mixed>, array<string, mixed>}> record key => [new values, stored values]
   */
  public static function diffs(ValidateValuesEvent $e): array {
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

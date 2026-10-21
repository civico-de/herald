<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Herald\Dispatch\ChannelConfig;
use Civi\Herald\Dispatch\ChannelSubject;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * What a digest must satisfy: a name rules can reference and no other digest
 * has, a text, and sources with distinct names that can run (SourceRules).
 *
 * @phpstan-import-type Digest from Definition
 */
final class DigestRules {

  /**
   * Problems of the given HeraldDigest values; absent fields are not checked,
   * except the text of a new digest.
   *
   * @param array<array-key, mixed> $values
   * @param int|null $id The digest being updated, NULL for a new one
   * @return array<string, string> field => problem
   */
  public static function recordProblems(array $values, ?int $id): array {
    $name = Cast::toString($values['name'] ?? '');
    // A new digest needs a text; an update only when it writes the field.
    $checked = $id === NULL || array_key_exists('msg_html', $values);
    $missing = $checked && trim(Cast::toString($values['msg_html'] ?? '')) === '';
    return array_filter([
      'name' => $name === '' ? NULL : self::nameProblem($name, $id),
      'msg_html' => $missing ? E::ts('Write the text of the digest.') : NULL,
    ]);
  }

  /**
   * Why the digest cannot be composed before its sources load.
   *
   * @param Digest $digest
   * @return list<string>
   */
  public static function sendProblems(array $digest): array {
    $problems = trim($digest['msg_html']) === '' ? [self::noText($digest)] : [];
    return [...$problems, ...SourceRules::listProblems($digest['sources'])];
  }

  /**
   * Why delivery settings cannot send a digest, or NULL: its subject is its own.
   *
   * @param array<string, mixed> $params Delivery settings
   */
  public static function deliveryProblem(array $params): ?string {
    $subjectKey = ChannelSubject::subjectKeyIn(ChannelConfig::of($params));
    return (
      $subjectKey === NULL
        ? NULL
        : E::ts('A digest has its own subject; remove %1 from the delivery settings.', [1 => $subjectKey])
    );
  }

  /**
   * Problems of an API save: the record's, else a new subject or text that
   * does not render with the stored sources.
   *
   * @param array<string, mixed> $new
   * @param array<string, mixed> $old The stored digest, empty for a new one
   * @return array<string, string> field => problem
   */
  public static function saveProblems(array $new, array $old): array {
    $problems = self::recordProblems($new, Definition::idOf($old['id'] ?? NULL));
    $text = $problems === [] ? (new Composer())->textProblem($new, $old) : NULL;
    return $text === NULL ? $problems : ['msg_html' => $text];
  }

  /**
   * @param Digest $digest
   */
  public static function noText(array $digest): string {
    return E::ts('Digest "%1" has no text; write one in the digest form.', [1 => $digest['title']]);
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

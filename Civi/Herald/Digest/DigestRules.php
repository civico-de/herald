<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Herald\Dispatch\ChannelConfig;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * What a digest must satisfy: a name rules can reference and no other digest
 * has, a message template, and sources with distinct names that can run (SourceRules).
 *
 * @phpstan-import-type Digest from Definition
 */
final class DigestRules {

  /**
   * Problems of the given HeraldDigest values; absent fields are not checked,
   * except the template of a new digest.
   *
   * @param array<array-key, mixed> $values
   * @param int|null $id The digest being updated, NULL for a new one
   * @return array<string, string> field => problem
   */
  public static function recordProblems(array $values, ?int $id): array {
    $name = Cast::toString($values['name'] ?? '');
    // A new digest needs a template; an update only when it writes the field.
    $checked = $id === NULL || array_key_exists('message_template_id', $values);
    $missing = $checked && Definition::idOf($values['message_template_id'] ?? NULL) === NULL;
    return array_filter([
      'name' => $name === '' ? NULL : self::nameProblem($name, $id),
      'message_template_id' => $missing ? E::ts('Choose the message template of the digest.') : NULL,
    ]);
  }

  /**
   * Why the digest cannot be composed before its sources load.
   *
   * @param Digest $digest
   * @return list<string>
   */
  public static function sendProblems(array $digest): array {
    $problems = $digest['template'] === NULL ? [self::noTemplate($digest)] : [];
    return [...$problems, ...SourceRules::listProblems($digest['sources'])];
  }

  /**
   * Why delivery settings cannot send a digest, or NULL: its subject is the template's.
   *
   * @param array<string, mixed> $params Delivery settings, channel_config included
   */
  public static function deliveryProblem(array $params): ?string {
    return trim(Cast::toString(ChannelConfig::of($params)['email_subject'] ?? '')) === ''
      ? NULL
      : E::ts('A digest takes its subject from its message template; remove email_subject from the delivery settings.');
  }

  /**
   * @param Digest $digest
   */
  public static function noTemplate(array $digest): string {
    return E::ts('Digest "%1" has no message template; choose one in the digest form.', [1 => $digest['title']]);
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

<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

use Civi\Herald\Topic\ValueType;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * What a channel type's config schema demands: keys it declares, values of
 * the declared type and the channel keys it marks required.
 */
final class ConfigSpec {

  /**
   * NULL, an empty string and an empty list count as no value.
   */
  public static function blank(mixed $value): bool {
    return in_array($value, [NULL, '', []], TRUE);
  }

  /**
   * @param array<string, mixed>|null $spec
   */
  public static function valueError(string $type, string $key, ?array $spec, mixed $value): ?string {
    if ($spec === NULL) {
      return E::ts('The channel type "%1" has no key "%2".', [1 => $type, 2 => $key]);
    }
    if (self::blank($value)) {
      return NULL;
    }
    $options = is_array($spec['options'] ?? NULL) ? $spec['options'] : [];
    return ValueType::error([
      'label' => Cast::toString($spec['label'] ?? $key),
      'type' => Cast::toString($spec['type'] ?? 'string'),
      'options' => array_map(Cast::toString(...), array_is_list($options) ? $options : array_keys($options)),
    ], $value);
  }

  /**
   * @param array<string, array<string, mixed>> $schema
   * @param array<string, mixed> $config
   * @return list<string>
   */
  public static function missing(array $schema, array $config): array {
    $errors = [];
    foreach ($schema as $key => $spec) {
      if (($spec['scope'] ?? 'delivery') !== 'channel' || empty($spec['required']) || isset($config[$key])) {
        continue;
      }
      $errors[] = E::ts('"%1" is required.', [1 => Cast::toString($spec['label'] ?? $key)]);
    }
    return $errors;
  }

}

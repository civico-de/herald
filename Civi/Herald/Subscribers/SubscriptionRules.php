<?php

declare(strict_types = 1);

namespace Civi\Herald\Subscribers;

use Civi\Herald\Channel\WebhookChannel;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * What a submitted subscription row or group channel must satisfy: a
 * recipient its channel can deliver to, and for a row a topic.
 */
final class SubscriptionRules {

  /**
   * @param array<string, array{0: string, 1: mixed}> $record Field name => [element, value]
   * @param array<string, string> $types Channel name => type
   * @return array<string, string> Element => message
   */
  public static function errors(array $record, array $types): array {
    $type = $types[Cast::toString($record['channel'][1] ?? '')] ?? NULL;
    $errors = [];
    if ($type !== NULL && isset($record['recipient'])) {
      [$element, $value] = $record['recipient'];
      $message = self::recipientError($type, trim(Cast::toString($value)));
      if ($message !== NULL) {
        $errors[$element] = $message;
      }
    }
    if (isset($record['topics']) && !self::picked($record['topics'][1])) {
      $errors[$record['topics'][0]] = E::ts('Pick at least one topic.');
    }
    return $errors + SubscriptionScope::errors($record);
  }

  private static function recipientError(string $type, string $recipient): ?string {
    if ($type === 'email') {
      return $recipient === '' || \CRM_Utils_Rule::email($recipient)
        ? NULL
        : E::ts('Enter an email address, or leave it empty for your primary address.');
    }
    if ($recipient === '') {
      return E::ts('This channel needs a recipient.');
    }
    return $type === 'webhook' ? WebhookChannel::violation($recipient) : NULL;
  }

  /**
   * A checkbox set submits value => 1 for a ticked topic, a multi-select the values.
   */
  private static function picked(mixed $value): bool {
    return is_array($value) ? array_filter($value) !== [] : Cast::toString($value) !== '';
  }

}

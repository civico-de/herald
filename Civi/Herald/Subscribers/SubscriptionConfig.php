<?php

declare(strict_types = 1);

namespace Civi\Herald\Subscribers;

use Civi\Herald\Channel\ChannelRegistry;

/**
 * The channel config a subscription row reaches its contact with.
 */
final class SubscriptionConfig {

  /**
   * Whether a contact can receive through a channel of this type.
   */
  public static function reachable(string $type): bool {
    return self::recipientKey($type) !== NULL;
  }

  /**
   * The config key a subscription's recipient fills for this channel type.
   */
  public static function recipientKey(string $type): ?string {
    $registry = ChannelRegistry::default();
    return $registry->has($type) ? $registry->get($type)->getSemanticFields()['recipient'] ?? NULL : NULL;
  }

  /**
   * The config, or NULL and why not: no_recipient, or suspended for an email
   * contact. An email subscription without recipient goes to the contact's
   * own address.
   *
   * @param array{recipient: string, ...} $subscription
   * @param non-empty-array<int, array<string, mixed>> $contactRows ContactRows::query() rows of the contact
   * @param array<string, mixed> $options
   * @return array{0: array<string, mixed>|null, 1: string}
   */
  public static function of(string $type, array $subscription, array $contactRows, array $options): array {
    $recipient = $subscription['recipient'];
    if ($type !== 'email') {
      $key = self::recipientKey($type);
      return $key === NULL || $recipient === '' ? [NULL, 'no_recipient'] : [[$key => $recipient], ''];
    }
    if ($recipient !== '') {
      return (
        EmailRecipient::blocked($contactRows[0], $options) ? [NULL, 'suspended'] : [['email_to' => $recipient], '']
      );
    }
    $email = EmailRecipient::of($contactRows, $options);
    if ($email['address'] === '') {
      return [NULL, $email['suspended'] ? 'suspended' : 'no_recipient'];
    }
    return [['email_to' => $email['address']], ''];
  }

}

<?php

declare(strict_types = 1);

namespace Civi\Herald\Dispatch;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * One rendered body to many recipients, one channel call each.
 *
 * A failed recipient is logged and the loop goes on; partial failure counts
 * as delivered, since a retry would send twice to those who got it. When
 * every send fails, or the list is empty, the delivery throws, so a buffered
 * flush keeps its rows. An empty list throws NoRecipientsException, which the
 * flush parks at once: nobody to notify is a configuration fault (a
 * topic nobody can subscribe to, emptied group), not something a retry fixes.
 */
final class FanOut {

  /**
   * Contacts resolved by subscription or group, each through the channel
   * their subscription names. Only presentation options travel from the params
   * (ChannelConfig::subscriberSafe()).
   *
   * @param array<string, mixed> $params
   * @param array<int, array{contact_id: int, channel: string, config: array<string, mixed>}> $recipients
   */
  public static function toRecipients(
    string $body,
    array $params,
    array $recipients,
    ?ChannelSender $sender = NULL,
  ): void {
    if ($recipients === []) {
      throw new NoRecipientsException(sprintf(
        'herald: no recipients resolved for delivery_mode "%s" — nothing was sent.',
        Cast::toString($params['delivery_mode'] ?? 'direct'),
      ));
    }
    $sender ??= new ChannelSender();
    $extras = ChannelConfig::subscriberSafe(ChannelConfig::of($params));
    self::sendEach('recipient', array_map(
      static fn (array $recipient): array => [
        static fn () => $sender->sendToRecipient(
          $recipient['channel'],
          $body,
          array_merge($extras, $recipient['config']),
        ),
        ['contact_id' => $recipient['contact_id'], 'channel' => $recipient['channel']],
      ],
      $recipients,
    ));
  }

  /**
   * A raw address list without contacts: one email per address, through the
   * delivery's channel, which must be an email channel.
   *
   * @param array<string, mixed> $params
   */
  public static function toAddresses(string $body, array $params, ?ChannelSender $sender = NULL): void {
    $addresses = self::addressList($params['email_addresses'] ?? '');
    if ($addresses === []) {
      throw new NoRecipientsException('herald: address-list mode requires action parameter "email_addresses".');
    }
    $sender ??= new ChannelSender();
    $name = Delivery::channel($params);
    if ($sender->typeOf($name) !== 'email') {
      throw new \CRM_Core_Exception(sprintf(
        'herald: address lists go through an email channel; "%s" is not one.',
        $name,
      ));
    }
    $config = ChannelConfig::of($params);
    unset($config['email_addresses']);
    self::sendEach('address', array_map(
      static fn (string $address): array => [
        static fn () => $sender->sendToRecipient($name, $body, ['email_to' => $address] + $config),
        ['email_to' => $address],
      ],
      $addresses,
    ));
  }

  /**
   * Split a comma, semicolon or newline separated address list, dropping
   * blanks and repeats.
   *
   * @return list<string>
   */
  public static function addressList(mixed $raw): array {
    if (is_array($raw)) {
      $raw = implode("\n", array_filter($raw, 'is_scalar'));
    }
    if (!is_scalar($raw)) {
      return [];
    }
    $parts = array_map('trim', preg_split('/[,;\n\r]+/', (string) $raw) ?: []);
    return array_values(array_unique(array_filter($parts, static fn (string $part): bool => $part !== '')));
  }

  /**
   * @param array<int, array{\Closure(): void, array<string, mixed>}> $sends
   *   One send each, with the log context naming its recipient.
   */
  private static function sendEach(string $kind, array $sends): void {
    $failed = 0;
    $lastError = '';
    foreach ($sends as [$send, $context]) {
      try {
        $send();
      }
      catch (\Throwable $e) {
        $failed++;
        $lastError = $e->getMessage();
        \Civi::log()->warning("herald: {$kind} dispatch failed", $context + ['error' => $lastError]);
      }
    }
    if ($failed === count($sends)) {
      throw new \CRM_Core_Exception(sprintf(
        'herald: all %d %s sends failed (last error: %s)',
        $failed,
        $kind,
        $lastError,
      ));
    }
  }

}

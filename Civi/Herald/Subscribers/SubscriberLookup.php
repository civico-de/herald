<?php

declare(strict_types = 1);

namespace Civi\Herald\Subscribers;

/**
 * Resolves contacts subscribed to a given notification kind into a flat list
 * of dispatch instructions (which channel, with which channel_config).
 *
 * Why a separate service: keeps the API4 query in one place, lets us unit-test
 * the result-mapping (apv4 result rows → dispatch dicts) without spinning up
 * Civi for every test.
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
 */
class SubscriberLookup {

  /**
   * @param string $subscriptionType One of the values from the
   *   `herald_subscription_type` option group (e.g. "completed_alert").
   * @param array<string, mixed> $options Delivery options: respect_suspension
   *   (bool), location_type_id (int, 0 = primary), scope (see SubscriptionScope).
   * @return array<int, array{contact_id: int, channel: string, config: array<string, mixed>}>
   */
  public function findBySubscriptionType(string $subscriptionType, array $options = []): array {
    if ($subscriptionType === '') {
      return [];
    }

    $query = \Civi\Api4\Contact::get(FALSE)
      ->addSelect(
        'id',
        'herald_preferences.preferred_channel',
        'herald_preferences.telegram_chat_id',
        'herald_preferences.pushover_user_key',
        'herald_preferences.ntfy_topic',
        'herald_preferences.webhook_url',
        'herald_preferences.subscriptions',
        'herald_preferences.source_entity_type',
        'herald_preferences.source_entity_id',
        'email_primary.email',
        'email_primary.on_hold',
        'do_not_email',
        'is_opt_out',
      )
      ->addWhere('is_deleted', '=', FALSE)
      ->addWhere('is_deceased', '=', FALSE)
      ->addWhere('herald_preferences.subscriptions', 'CONTAINS', $subscriptionType);
    self::addLocationEmailJoin($query, $options);
    /** @var array<int, array<string, mixed>> $rows */
    $rows = $query->execute()->getArrayCopy();

    $mapped = self::mapRowsDetailed($rows, $options);
    if ($mapped['skipped'] !== []) {
      \Civi::log()->info('herald: subscriber fan-out skipped recipients', [
        'subscription_type' => $subscriptionType,
        'skipped' => $mapped['skipped'],
      ]);
    }
    return $mapped['recipients'];
  }

  /**
   * Join the preferred-location-type email when the option asks for one.
   *
   * @param \Civi\Api4\Generic\DAOGetAction $query
   * @param array<string, mixed> $options
   */
  public static function addLocationEmailJoin($query, array $options): void {
    $locationTypeId = self::int($options['location_type_id'] ?? 0);
    if ($locationTypeId <= 0) {
      return;
    }
    $query->addJoin(
      'Email AS herald_loc_email',
      'LEFT',
      NULL,
      ['herald_loc_email.contact_id', '=', 'id'],
      ['herald_loc_email.location_type_id', '=', $locationTypeId],
    );
    $query->addSelect('herald_loc_email.email', 'herald_loc_email.on_hold');
  }

  /**
   * Map raw APIv4 result rows to dispatch instructions. Pure — test-seam.
   *
   * Skips contacts with no preferred channel or no recipient ID for that
   * channel; the caller decides what to do with the empty list (typically
   * just no-op the send).
   *
   * @param array<int, array<string, mixed>> $rows
   * @param array<string, mixed> $options
   * @return array<int, array{contact_id: int, channel: string, config: array<string, mixed>}>
   */
  public static function mapRows(array $rows, array $options = []): array {
    return self::mapRowsDetailed($rows, $options)['recipients'];
  }

  /**
   * Like mapRows(), but also counts why rows were dropped, so fan-out callers
   * can log suspension/scope skips instead of silently shrinking the list.
   *
   * @param array<int, array<string, mixed>> $rows
   * @param array<string, mixed> $options
   * @return array{
   *   recipients: array<int, array{contact_id: int, channel: string, config: array<string, mixed>}>,
   *   skipped: array<string, int>,
   * }
   */
  public static function mapRowsDetailed(array $rows, array $options = []): array {
    $scope = $options['scope'] ?? NULL;
    /** @var array{entity_type: string, entity_id: int}|null $scope */
    $scope = is_array($scope) ? $scope : NULL;
    $out = [];
    $skipped = [];
    $seen = [];
    $bump = static function (string $reason) use (&$skipped): void {
      $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;
    };
    foreach ($rows as $row) {
      // The location-email join can duplicate a contact (several addresses of
      // that location type) — one send per contact, first row wins.
      $contactId = self::int($row['id'] ?? 0);
      if ($contactId > 0 && isset($seen[$contactId])) {
        continue;
      }
      $seen[$contactId] = TRUE;
      if (!SubscriptionScope::rowMatches($row, $scope)) {
        $bump('scope_mismatch');
        continue;
      }
      $channel = self::str($row['herald_preferences.preferred_channel'] ?? '');
      if ($channel === '') {
        continue;
      }
      $config = $channel === 'email'
        ? self::emailConfig($row, $options, $bump)
        : self::buildConfigForChannel($channel, $row);
      if ($config === NULL) {
        continue;
      }
      $out[] = [
        'contact_id' => $contactId,
        'channel' => $channel,
        'config' => $config,
      ];
    }
    return ['recipients' => $out, 'skipped' => $skipped];
  }

  /**
   * Email channel config for one row, or NULL when undeliverable; a
   * suspension is counted via $bump before the NULL.
   *
   * @param array<string, mixed> $row
   * @param array<string, mixed> $options
   * @param callable(string): void $bump
   * @return array<string, mixed>|null
   */
  private static function emailConfig(array $row, array $options, callable $bump): ?array {
    $delivery = self::resolveEmailDelivery($row, $options);
    if ($delivery['suspended']) {
      $bump('suspended');
      return NULL;
    }
    return $delivery['address'] !== '' ? ['email_to' => $delivery['address']] : NULL;
  }

  /**
   * Resolve the deliverable email address for one contact row, honouring the
   * suspension option. do_not_email is always a hard block (privacy flag);
   * is_opt_out only counts under respect_suspension. Address pick: the
   * preferred-location-type address, falling back to primary — under
   * respect_suspension an on-hold candidate is passed over in favour of the
   * next usable one, and suspended is only reported when every existing
   * address is on hold.
   *
   * @param array<string, mixed> $row
   * @param array<string, mixed> $options
   * @return array{address: string, suspended: bool}
   *   address '' + suspended FALSE means "no address at all".
   */
  public static function resolveEmailDelivery(array $row, array $options): array {
    if (!empty($row['do_not_email'])) {
      return ['address' => '', 'suspended' => TRUE];
    }
    $respect = !empty($options['respect_suspension']);
    if ($respect && !empty($row['is_opt_out'])) {
      return ['address' => '', 'suspended' => TRUE];
    }
    $candidates = [
      [self::str($row['herald_loc_email.email'] ?? ''), !empty($row['herald_loc_email.on_hold'])],
      [self::str($row['email_primary.email'] ?? ''), !empty($row['email_primary.on_hold'])],
    ];
    $sawAddress = FALSE;
    foreach ($candidates as [$address, $onHold]) {
      if ($address === '') {
        continue;
      }
      $sawAddress = TRUE;
      if ($respect && $onHold) {
        continue;
      }
      return ['address' => $address, 'suspended' => FALSE];
    }
    return ['address' => '', 'suspended' => $sawAddress];
  }

  /**
   * Recipient address for email delivery ignoring hold states: the
   * preferred-location-type address when the query joined one and the contact
   * has it, else primary.
   *
   * @param array<string, mixed> $row
   */
  public static function resolveEmailAddress(array $row): string {
    $loc = self::str($row['herald_loc_email.email'] ?? '');
    return $loc !== '' ? $loc : self::str($row['email_primary.email'] ?? '');
  }

  /**
   * Build the channel config dict for one contact row, or NULL if the
   * recipient ID required by their preferred channel is missing.
   *
   * @param array<string, mixed> $row
   * @return array<string, mixed>|null
   */
  public static function buildConfigForChannel(string $channel, array $row): ?array {
    return match ($channel) {
      'telegram' => self::nonEmpty($row, 'herald_preferences.telegram_chat_id', [
        'chat_id' => $row['herald_preferences.telegram_chat_id'] ?? '',
      ]),
      'pushover' => self::nonEmpty($row, 'herald_preferences.pushover_user_key', [
        'pushover_user_key' => $row['herald_preferences.pushover_user_key'] ?? '',
      ]),
      'ntfy' => self::nonEmpty($row, 'herald_preferences.ntfy_topic', [
        'ntfy_topic' => $row['herald_preferences.ntfy_topic'] ?? '',
      ]),
      'webhook' => self::nonEmpty($row, 'herald_preferences.webhook_url', [
        'webhook_url' => $row['herald_preferences.webhook_url'] ?? '',
      ]),
      // Email needs no preference field — the preferred-location address (when
      // requested and present) or the primary address is the recipient.
      // do_not_email is a hard privacy flag and trumps the opt-in.
      'email' => self::resolveEmailAddress($row) !== '' && empty($row['do_not_email'])
        ? ['email_to' => self::resolveEmailAddress($row)]
        : NULL,
      default => NULL,
    };
  }

  /**
   * Returns $config if $row[$key] is non-empty, else NULL.
   *
   * @param array<string, mixed> $row
   * @param array<string, mixed> $config
   * @return array<string, mixed>|null
   */
  private static function nonEmpty(array $row, string $key, array $config): ?array {
    return !empty($row[$key]) ? $config : NULL;
  }

  /**
   * Narrow a mixed APIv4-row value to string. Guards the cast against the
   * theoretical mixed type without changing behaviour for well-formed data.
   */
  private static function str(mixed $value): string {
    return is_scalar($value) ? (string) $value : '';
  }

  /**
   * Narrow a mixed APIv4-row value to int. Same rationale as self::str().
   */
  private static function int(mixed $value): int {
    return is_scalar($value) ? (int) $value : 0;
  }

}

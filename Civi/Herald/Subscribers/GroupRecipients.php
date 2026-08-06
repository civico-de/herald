<?php

declare(strict_types = 1);

namespace Civi\Herald\Subscribers;

/**
 * Resolves the members of a CiviCRM group into dispatch instructions.
 *
 * Contacts with usable herald_preferences go out via their own preferred
 * channel (same mapping as subscriber mode). Contacts without get the email
 * fallback — their own resolved address — since that is the only channel with
 * a per-contact recipient that needs no preference record. Non-email default
 * channels have no per-contact recipient and are counted as skipped.
 */
class GroupRecipients {

  /**
   * @param int $groupId Static or smart group id (APIv4 "groups" semantics).
   * @param array<string, mixed> $options Same options bag as SubscriberLookup:
   *   respect_suspension, location_type_id.
   * @return array<int, array{contact_id: int, channel: string, config: array<string, mixed>}>
   */
  public function findByGroup(int $groupId, array $options = []): array {
    if ($groupId <= 0) {
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
        'email_primary.email',
        'email_primary.on_hold',
        'do_not_email',
        'is_opt_out',
      )
      ->addWhere('is_deleted', '=', FALSE)
      ->addWhere('is_deceased', '=', FALSE)
      ->addWhere('groups', 'IN', [$groupId]);
    SubscriberLookup::addLocationEmailJoin($query, $options);
    /** @var array<int, array<string, mixed>> $rows */
    $rows = $query->execute()->getArrayCopy();

    $mapped = self::mapGroupRows($rows, $options);
    if ($mapped['skipped'] !== []) {
      \Civi::log()->info('herald: group fan-out skipped recipients', [
        'group_id' => $groupId,
        'skipped' => $mapped['skipped'],
      ]);
    }
    return $mapped['recipients'];
  }

  /**
   * Map group-member rows to dispatch instructions. Pure — test-seam.
   *
   * @param array<int, array<string, mixed>> $rows
   * @param array<string, mixed> $options
   * @return array{
   *   recipients: array<int, array{contact_id: int, channel: string, config: array<string, mixed>}>,
   *   skipped: array<string, int>,
   * }
   */
  public static function mapGroupRows(array $rows, array $options = []): array {
    $out = [];
    $skipped = [];
    $bump = static function (string $reason) use (&$skipped): void {
      $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;
    };
    foreach (SubscriberLookup::groupRowsByContact($rows) as $contactRows) {
      // The location-email join can duplicate a contact (several addresses of
      // that location type); preference fields are identical on every row, so
      // the first speaks for the contact — but email deliverability must be
      // judged across ALL of a contact's rows, not just the first.
      $row = $contactRows[0];
      $contactId = is_scalar($row['id'] ?? NULL) ? (int) $row['id'] : 0;
      $channel = is_scalar($row['herald_preferences.preferred_channel'] ?? NULL)
        ? (string) $row['herald_preferences.preferred_channel']
        : '';
      $config =
        $channel !== '' && $channel !== 'email'
          ? SubscriberLookup::buildConfigForChannel($channel, $row)
          : NULL;
      if ($config === NULL) {
        // Email path: preferred channel email, or the fallback for contacts
        // without (usable) preferences — their own resolved address.
        $channel = 'email';
        $delivery = SubscriberLookup::resolveEmailDeliveryRows($contactRows, $options);
        if ($delivery['suspended']) {
          $bump('suspended');
          continue;
        }
        if ($delivery['address'] === '') {
          $bump('no_recipient');
          continue;
        }
        $config = ['email_to' => $delivery['address']];
      }
      $out[] = ['contact_id' => $contactId, 'channel' => $channel, 'config' => $config];
    }
    return ['recipients' => $out, 'skipped' => $skipped];
  }

}

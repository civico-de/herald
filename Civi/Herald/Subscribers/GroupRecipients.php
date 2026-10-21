<?php

declare(strict_types = 1);

namespace Civi\Herald\Subscribers;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Resolves the members of a CiviCRM group into dispatch instructions.
 *
 * Contacts with usable herald_preferences go out via their own preferred
 * channel (same mapping as subscriber mode). Contacts without get their own
 * resolved address through the contact email channel
 * (ContactRows::emailChannel()), the only recipient that needs no preference.
 */
class GroupRecipients {

  /**
   * @param int $groupId Static or smart group id (APIv4 "groups" semantics).
   * @param array<string, mixed> $options respect_suspension (bool),
   *   location_type_id (int, 0 = primary).
   * @return array<int, array{contact_id: int, channel: string, config: array<string, mixed>}>
   */
  public function findByGroup(int $groupId, array $options = []): array {
    if ($groupId <= 0) {
      return [];
    }
    /** @var array<int, array<string, mixed>> $rows */
    $rows = ContactRows::query($options)->addWhere('groups', 'IN', [$groupId])->execute()->getArrayCopy();
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
   * @param array<string, string>|null $types Channel name => type; default ContactRows::subscribable().
   * @return array{
   *   recipients: array<int, array{contact_id: int, channel: string, config: array<string, mixed>}>,
   *   skipped: array<string, int>,
   * }
   */
  public static function mapGroupRows(
    array $rows,
    array $options = [],
    ?array $types = NULL,
    ?string $emailChannel = NULL,
  ): array {
    $types ??= ContactRows::subscribable();
    $emailChannel ??= ContactRows::emailChannel();
    $out = [];
    /** @var array<string, int> $skipped */
    $skipped = [];
    $bump = static function (string $reason) use (&$skipped): void {
      $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;
    };
    foreach (ContactRows::byContact($rows) as $contactRows) {
      // The location-email join can duplicate a contact (several addresses of
      // that location type); preference fields are identical on every row, so
      // the first speaks for the contact — but email deliverability must be
      // judged across ALL of a contact's rows, not just the first.
      $row = $contactRows[0];
      $contactId = Cast::toInt($row['id'] ?? 0);
      $channel = Cast::toString($row['herald_preferences.preferred_channel'] ?? '');
      $type = $types[$channel] ?? '';
      $config = $type !== '' && $type !== 'email' ? ContactRows::channelConfig($type, $row) : NULL;
      if ($config === NULL) {
        // Email path: an email channel preferred, or the fallback for contacts
        // without (usable) preferences — their own resolved address.
        $channel = $type === 'email' ? $channel : $emailChannel;
        $delivery = EmailRecipient::of($contactRows, $options);
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

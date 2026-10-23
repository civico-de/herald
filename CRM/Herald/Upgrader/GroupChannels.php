<?php

declare(strict_types = 1);

use Civi\Herald\Subscribers\Subscriptions;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * The group channel the upgrade writes from a contact's preference record.
 *
 * @phpstan-import-type Subscription from Subscriptions
 */
final class CRM_Herald_Upgrader_GroupChannels {

  /**
   * Contacts, the trash included, that have a group channel already.
   *
   * @return array<int, int> Contact id => index
   */
  public static function present(): array {
    return array_flip(array_map(
      Cast::toInt(...),
      \Civi\Api4\Contact::get(checkPermissions: FALSE)
        ->addSelect('id')
        ->addWhere('is_deleted', 'IN', [FALSE, TRUE])
        ->addWhere(Subscriptions::GROUP_DELIVERY . '.channel', 'IS NOT EMPTY')
        ->execute()
        ->column('id'),
    ));
  }

  /**
   * @param Subscription $row
   * @return array<string, mixed>
   */
  public static function record(array $row): array {
    return [
      'id' => $row['contact_id'],
      Subscriptions::GROUP_DELIVERY . '.channel' => $row['channel'],
      Subscriptions::GROUP_DELIVERY . '.recipient' => $row['recipient'] !== '' ? $row['recipient'] : NULL,
    ];
  }

  /**
   * @param list<array<string, mixed>> $records
   */
  public static function save(array $records): void {
    foreach (array_chunk($records, 500) as $chunk) {
      \Civi\Api4\Contact::save(checkPermissions: FALSE)->setRecords($chunk)->execute();
    }
  }

}

<?php

declare(strict_types = 1);

namespace Civi\Herald\Trigger;

use Civi\Herald\Privacy\Mask;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * The contact, group and membership variables of a trigger. Each returns
 * nothing when the trigger carries no such entity.
 */
final class ContactVars {

  /**
   * @return array<string, mixed>
   */
  public static function contact(int $contactId): array {
    if ($contactId <= 0) {
      return [];
    }
    $displayName = Cast::toString(\CRM_Core_DAO::singleValueQuery(
      'SELECT display_name FROM civicrm_contact WHERE id = %1',
      [1 => [$contactId, 'Integer']],
    ));
    $email = Cast::toString(\CRM_Core_DAO::singleValueQuery(
      'SELECT email FROM civicrm_email WHERE contact_id = %1 ORDER BY is_primary DESC, id ASC LIMIT 1',
      [1 => [$contactId, 'Integer']],
    ));
    return ['contact' => [
      'id' => $contactId,
      'display_name' => $displayName,
      'email' => $email,
      'masked_name' => Mask::words($displayName),
      'masked_email' => Mask::email($email),
    ]];
  }

  /**
   * @return array<string, mixed>
   */
  public static function group(int $groupId): array {
    if ($groupId <= 0) {
      return [];
    }
    $title = Cast::toString(\CRM_Core_DAO::singleValueQuery(
      'SELECT title FROM civicrm_group WHERE id = %1',
      [1 => [$groupId, 'Integer']],
    ));
    return ['group' => ['id' => $groupId, 'title' => $title]];
  }

  /**
   * @param array<array-key, mixed> $membership
   * @return array<string, mixed>
   */
  public static function membership(array $membership): array {
    if (empty($membership['id'])) {
      return [];
    }
    $membership += ['membership_type_id' => 0, 'status_id' => 0];
    return ['membership' => [
      'membership_type' => self::label(
        'civicrm_membership_type',
        'name',
        Cast::toInt($membership['membership_type_id']),
      ),
      'status' => self::label('civicrm_membership_status', 'label', Cast::toInt($membership['status_id'])),
    ]];
  }

  /**
   * Single-column lookup by primary key; '' when the row is gone.
   */
  public static function label(string $table, string $column, int $id): string {
    if ($id <= 0) {
      return '';
    }
    return Cast::toString(\CRM_Core_DAO::singleValueQuery(
      "SELECT `{$column}` FROM `{$table}` WHERE id = %1",
      [1 => [$id, 'Integer']],
    ));
  }

}

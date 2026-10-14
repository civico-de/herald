<?php

declare(strict_types = 1);

namespace Civi\Herald\Trigger;

use Civi\Api4\Email;
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
    $displayName = self::label('Contact', 'display_name', $contactId);
    $email = Cast::toString(
      Email::get(checkPermissions: FALSE)
        ->addSelect('email')
        ->addWhere('contact_id', '=', $contactId)
        ->addOrderBy('is_primary', 'DESC')
        ->addOrderBy('id')
        ->setLimit(1)
        ->execute()
        ->first()['email'] ?? '',
    );
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
    $title = self::label('Group', 'title', $groupId);
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
        'MembershipType',
        'name',
        Cast::toInt($membership['membership_type_id']),
      ),
      'status' => self::label('MembershipStatus', 'label', Cast::toInt($membership['status_id'])),
    ]];
  }

  /**
   * Single-column lookup by primary key; '' when the row is gone. A get by id
   * skips APIv4's default filters, so deleted, test and template rows count.
   */
  public static function label(string $entity, string $column, int $id): string {
    if ($id <= 0) {
      return '';
    }
    $row = civicrm_api4($entity, 'get', [
      'select' => [$column],
      'where' => [['id', '=', $id]],
      'checkPermissions' => FALSE,
    ])->first();
    return Cast::toString($row[$column] ?? '');
  }

}

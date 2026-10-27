<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Validates and resolves the contacts named in channel configuration.
 */
final class ActivityContactId {

  public static function optional(mixed $value): ?int {
    if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
      return NULL;
    }
    $value = (int) $value;
    return $value > 0 ? $value : NULL;
  }

  /**
   * The contact with this external identifier; NULL when none is configured.
   *
   * @throws \CRM_Core_Exception when no contact outside the trash has it
   */
  public static function byExternalIdentifier(mixed $value): ?int {
    $identifier = is_string($value) ? trim($value) : '';
    if ($identifier === '') {
      return NULL;
    }
    $contact = \Civi\Api4\Contact::get(checkPermissions: FALSE)
      ->addSelect('id')
      ->addWhere('external_identifier', '=', $identifier)
      ->addWhere('is_deleted', '=', FALSE)
      ->execute()
      ->first();
    if ($contact === NULL) {
      throw new \CRM_Core_Exception(sprintf(
        'herald: no contact outside the trash has the external identifier "%s".',
        $identifier,
      ));
    }
    return Cast::toInt($contact['id']);
  }

}

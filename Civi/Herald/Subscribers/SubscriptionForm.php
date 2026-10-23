<?php

declare(strict_types = 1);

namespace Civi\Herald\Subscribers;

use Civi\Herald\Api4\Rows;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Checks a subscription row on the contact tab and the self-service profile
 * against what its channel needs, so a bad row fails on save, not on send.
 */
final class SubscriptionForm {

  private const FORMS = ['CRM_Contact_Form_CustomData', 'CRM_Profile_Form_Edit'];

  /**
   * The tab names a row's elements custom_<field>_<row>, the profile custom_<field>.
   *
   * @param array<array-key, mixed> $fields
   * @param array<array-key, mixed> $errors
   */
  public static function validate(string $formName, array $fields, array &$errors): void {
    if (!in_array($formName, self::FORMS, TRUE)) {
      return;
    }
    $names = self::fieldNames();
    $records = [];
    foreach ($fields as $element => $value) {
      if (preg_match('/^custom_(\d+)(_-?\d+)?$/', (string) $element, $match) !== 1) {
        continue;
      }
      $name = $names[(int) $match[1]] ?? NULL;
      if ($name !== NULL) {
        $records[$match[2] ?? ''][$name] = [(string) $element, $value];
      }
    }
    foreach ($records as $record) {
      foreach (SubscriptionRules::errors($record, ContactRows::subscribable()) as $element => $message) {
        $errors[$element] = $message;
      }
    }
  }

  /**
   * @return array<int, string> Field id => name
   */
  private static function fieldNames(): array {
    $names = [];
    foreach (Rows::of(
      \Civi\Api4\CustomField::get(checkPermissions: FALSE)
        ->addSelect('id', 'name')
        ->addWhere('custom_group_id:name', '=', Subscriptions::GROUP)
        ->execute(),
    ) as $field) {
      $names[Cast::toInt($field['id'])] = Cast::toString($field['name']);
    }
    return $names;
  }

}

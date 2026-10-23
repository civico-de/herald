<?php

declare(strict_types = 1);

namespace Civi\Herald\Subscribers;

use Civi\Herald\Api4\Rows;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Checks subscription rows and the group channel on the contact screens and
 * the self-service profile against what their channel needs, so a bad entry
 * fails on save, not on send.
 */
final class SubscriptionForm {

  private const FORMS = [
    'CRM_Contact_Form_CustomData',
    'CRM_Contact_Form_Inline_CustomData',
    'CRM_Contact_Form_Contact',
    'CRM_Profile_Form_Edit',
  ];

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
      [$group, $name] = $names[(int) $match[1]] ?? [NULL, NULL];
      if ($name !== NULL) {
        $records[$group . ($match[2] ?? '')][$name] = [(string) $element, $value];
      }
    }
    foreach ($records as $record) {
      foreach (SubscriptionRules::errors($record, ContactRows::subscribable()) as $element => $message) {
        $errors[$element] = $message;
      }
    }
  }

  /**
   * Both groups name their fields channel and recipient.
   *
   * @return array<int, array{0: string, 1: string}> Field id => [group, name]
   */
  private static function fieldNames(): array {
    $names = [];
    foreach (Rows::of(
      \Civi\Api4\CustomField::get(checkPermissions: FALSE)
        ->addSelect('id', 'name', 'custom_group_id:name')
        ->addWhere('custom_group_id:name', 'IN', [Subscriptions::GROUP, Subscriptions::GROUP_DELIVERY])
        ->execute(),
    ) as $field) {
      $names[Cast::toInt($field['id'])] = [
        Cast::toString($field['custom_group_id:name']),
        Cast::toString($field['name']),
      ];
    }
    return $names;
  }

}

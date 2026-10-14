<?php

declare(strict_types = 1);

use Civi\Herald\Channel\ChannelRegistry;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * The option lists of herald's send action forms.
 */
final class CRM_Herald_CivirulesAdapter_Form_SendOptions {

  /**
   * @return array<string, string>
   */
  public static function deliveryModes(): array {
    return [
      'direct' => E::ts('Direct — send to one recipient configured below'),
      'subscribers' => E::ts('Subscribers — fan out to all contacts opted into a subscription type'),
      'group' => E::ts('Group — fan out to all contacts of a CiviCRM group'),
      'addresses' => E::ts('Address list — send to raw email addresses (no contact linkage)'),
    ];
  }

  /**
   * @return array<string, string>
   */
  public static function channels(): array {
    $options = [];
    foreach (ChannelRegistry::default()->listKeys() as $key) {
      $options[$key] = ChannelRegistry::label($key);
    }
    return $options;
  }

  /**
   * Active values of the herald_subscription_type option group.
   *
   * @return array<string, string>
   */
  public static function subscriptionTypes(): array {
    $rows = \Civi\Api4\OptionValue::get(checkPermissions: FALSE)
      ->addSelect('value', 'label')
      ->addWhere('option_group_id:name', '=', 'herald_subscription_type')
      ->addWhere('is_active', '=', value: TRUE)
      ->addOrderBy('weight', 'ASC')
      ->execute()
      ->getArrayCopy();
    return ['' => E::ts('— none —')] + self::labels($rows, 'value', 'label');
  }

  /**
   * Active, visible groups for group-mode delivery.
   *
   * @return array<string, string>
   */
  public static function groups(): array {
    $rows = \Civi\Api4\Group::get(checkPermissions: FALSE)
      ->addSelect('id', 'title')
      ->addWhere('is_active', '=', value: TRUE)
      ->addWhere('is_hidden', '=', value: FALSE)
      ->addOrderBy('title', 'ASC')
      ->execute()
      ->getArrayCopy();
    return ['' => E::ts('- select group -')] + self::labels($rows, 'id', 'title');
  }

  /**
   * @return array<string, string>
   */
  public static function locationTypes(): array {
    $rows = \Civi\Api4\LocationType::get(checkPermissions: FALSE)
      ->addSelect('id', 'display_name')
      ->addWhere('is_active', '=', value: TRUE)
      ->addOrderBy('display_name', 'ASC')
      ->execute()
      ->getArrayCopy();
    return ['' => E::ts('- primary -')] + self::labels($rows, 'id', 'display_name');
  }

  /**
   * Workflow templates herald ships, by workflow name.
   *
   * @return array<string, string>
   */
  public static function templates(): array {
    $rows = \Civi\Api4\MessageTemplate::get(checkPermissions: FALSE)
      ->addSelect('workflow_name', 'msg_title')
      ->addWhere('workflow_name', 'LIKE', 'herald_%')
      ->addWhere('is_default', '=', value: TRUE)
      ->execute()
      ->getArrayCopy();
    return self::labels($rows, 'workflow_name', 'msg_title');
  }

  /**
   * A row without a label is shown by its value.
   *
   * @param array<array-key, mixed> $rows
   * @return array<string, string>
   */
  private static function labels(array $rows, string $valueKey, string $labelKey): array {
    $options = [];
    foreach ($rows as $row) {
      $value = Cast::toString(((array) $row)[$valueKey] ?? '');
      $label = Cast::toString(((array) $row)[$labelKey] ?? '');
      $options[$value] = $label !== '' ? $label : $value;
    }
    return $options;
  }

}

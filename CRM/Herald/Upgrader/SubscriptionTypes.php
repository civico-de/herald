<?php

declare(strict_types = 1);

use Civi\Herald\Subscribers\SubscribableTopics;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * The subscription types of an install before they became topics, with their labels.
 */
final class CRM_Herald_Upgrader_SubscriptionTypes {

  private const OLD_GROUP = 'herald_subscription_type';

  /**
   * The reconcile ahead of the upgrade removes the options herald shipped.
   */
  private const SHIPPED = [
    'completed_alert' => 'Every completed contribution',
    'pending_alert' => 'Every pending contribution',
    'status_changed_alert' => 'Pending → Completed transitions',
    'daily_digest' => 'Daily digest',
    'weekly_digest' => 'Weekly digest',
  ];

  /**
   * A reconcile ahead of the upgrade would move the field and delete the
   * types with their labels, so the field keeps them until they are read.
   */
  public static function fieldGroup(): string {
    $old = CRM_Core_DAO::singleValueQuery(
      'SELECT COUNT(*) FROM civicrm_option_group WHERE name = %1',
      [1 => [self::OLD_GROUP, 'String']],
    );
    return Cast::toInt($old) > 0 ? self::OLD_GROUP : SubscribableTopics::OPTION_GROUP;
  }

  /**
   * The shipped types as an older release declared them, so contacts keep
   * them on forms until the upgrade has read them.
   *
   * @return list<array<string, mixed>>
   */
  public static function declarations(): array {
    $managed = [[
      'name' => 'OptionGroup_herald_subscription_type',
      'entity' => 'OptionGroup',
      'cleanup' => 'unused',
      'update' => 'unmodified',
      'params' => ['version' => 4, 'match' => ['name'], 'values' => ['name' => self::OLD_GROUP, 'is_active' => TRUE]],
    ]];
    foreach (array_keys(self::SHIPPED) as $index => $name) {
      $managed[] = [
        'name' => 'OptionValue_herald_subscription_type_' . $name,
        'entity' => 'OptionValue',
        'cleanup' => 'unused',
        'update' => 'unmodified',
        'params' => [
          'version' => 4,
          'match' => ['option_group_id', 'name'],
          'values' => [
            'option_group_id.name' => self::OLD_GROUP,
            'name' => $name,
            'value' => $name,
            'label' => E::ts(self::SHIPPED[$name]),
            'weight' => ($index + 1) * 10,
            'is_active' => TRUE,
          ],
        ],
      ];
    }
    return $managed;
  }

  /**
   * Once the types are read; the field loses its options until the reconcile moves it.
   */
  public static function retire(): void {
    \Civi\Api4\OptionGroup::delete(checkPermissions: FALSE)
      ->addWhere('name', '=', self::OLD_GROUP)
      ->execute();
  }

  /**
   * @param array<array-key, mixed> $params
   * @return string|null NULL without the key
   */
  public static function of(array $params): ?string {
    if (!array_key_exists('subscription_type', $params)) {
      return NULL;
    }
    $type = $params['subscription_type'];
    return is_scalar($type) ? trim((string) $type) : '';
  }

  /**
   * Remaining options, subscribed values and named types; a shipped type
   * nobody subscribed to and nothing names is left out.
   *
   * @param list<string> $named
   * @return array<string, string> Name => label
   */
  public static function labels(array $named): array {
    $options = \Civi\Api4\OptionValue::get(checkPermissions: FALSE)
      ->addSelect('value', 'label')
      ->addWhere('option_group_id:name', '=', self::OLD_GROUP)
      ->execute()
      ->column('label', 'value');
    $own = array_diff(array_map(Cast::toString(...), array_keys($options)), array_keys(self::SHIPPED));
    $labels = [];
    foreach (array_unique([...$own, ...self::subscribed(), ...$named]) as $name) {
      $shipped = isset(self::SHIPPED[$name]) ? E::ts(self::SHIPPED[$name]) : $name;
      $labels[$name] = Cast::toString($options[$name] ?? $shipped);
    }
    return $labels;
  }

  /**
   * The distinct values stored in the subscriptions field, without the contacts.
   *
   * @return list<string>
   */
  private static function subscribed(): array {
    $field = \Civi\Api4\CustomField::get(checkPermissions: FALSE)
      ->addSelect('column_name', 'custom_group_id.table_name')
      ->addWhere('custom_group_id:name', '=', 'herald_preferences')
      ->addWhere('name', '=', 'subscriptions')
      ->execute()
      ->first();
    if ($field === NULL) {
      return [];
    }
    $column = Cast::toString($field['column_name']);
    /** @var list<array{value: mixed}> $rows */
    $rows = CRM_Utils_SQL_Select::from(Cast::toString($field['custom_group_id.table_name']))
      ->select("DISTINCT `{$column}` AS value")
      ->where("`{$column}` IS NOT NULL")
      ->execute()
      ->fetchAll();
    $values = [];
    foreach ($rows as $row) {
      $values = [
        ...$values,
        ...array_map(
          Cast::toString(...),
          CRM_Utils_Array::explodePadded(Cast::toString($row['value'])) ?? [],
        ),
      ];
    }
    return array_values(array_unique(array_filter($values, static fn (string $value): bool => $value !== '')));
  }

}

<?php

declare(strict_types = 1);

use Civi\Herald\Channel\ChannelRegistry;
use Civi\Herald\Channel\ChannelStore;
use Civi\Herald\Topic\Router;
use Civi\Herald\Topic\TopicMeta;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * The option lists of herald's send action forms.
 */
final class CRM_Herald_CivirulesAdapter_SendOptions {

  /**
   * @return array<string, string>
   */
  public static function deliveryModes(): array {
    return [
      'direct' => E::ts('Direct — send to one recipient configured below'),
      'subscribers' => E::ts('Subscribers — fan out to all contacts subscribed to a topic'),
      'group' => E::ts('Group — fan out to all contacts of a CiviCRM group'),
      'addresses' => E::ts('Address list — send to raw email addresses (no contact linkage)'),
    ];
  }

  /**
   * Switched-off channels stay listed, so a rule naming one keeps it.
   *
   * @return array<string, string>
   */
  public static function channels(): array {
    $options = [];
    foreach ((new ChannelStore())->all() as $name => $record) {
      $options[$name] =
        $record['label']
        . ' ('
        . ChannelRegistry::label($record['type'])
        . ')'
        . ($record['active'] ? '' : ' ' . E::ts('(switched off)'));
    }
    return $options;
  }

  /**
   * The configured channels by name and their type, for the form script.
   *
   * @return array<string, string>
   */
  public static function channelTypes(): array {
    return array_map(static fn (array $record): string => $record['type'], (new ChannelStore())->all());
  }

  /**
   * @return array<string, string>
   */
  public static function topics(): array {
    $map = \Civi::settings()->get(Router::SETTING);
    $names = array_map('strval', array_keys(is_array($map) ? $map : []));
    return array_combine($names, $names);
  }

  /**
   * The topics contacts may subscribe to.
   *
   * @return array<string, string>
   */
  public static function subscribableTopics(): array {
    $map = \Civi::settings()->get(Router::SETTING);
    return ['' => E::ts('— none —')] + TopicMeta::subscribable(is_array($map) ? $map : []);
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

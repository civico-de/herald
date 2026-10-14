<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * Option lists of the HeraldDigest and HeraldDigestBlock fields, and the
 * schema defaults the digest form gives a new block.
 */
final class Options {

  /**
   * Settings of the Angular module heraldDigest (CRM.heraldDigest).
   *
   * @return array{blockDefaults: array<string, mixed>}
   */
  public static function angularSettings(): array {
    /** @var list<array<string, mixed>> $fields */
    $fields = \Civi\Api4\HeraldDigestBlock::getFields(checkPermissions: FALSE)
      ->setAction('create')
      ->addSelect('name', 'default_value')
      ->addWhere('default_value', 'IS NOT NULL')
      ->execute()
      ->getArrayCopy();
    return ['blockDefaults' => Cast::namedOnly(array_column($fields, 'default_value', 'name'))];
  }

  /**
   * Why the value is not a key of $options, or NULL; an empty value means the default.
   *
   * @param array<string, string> $options
   */
  public static function problem(string $label, mixed $value, array $options): ?string {
    $key = Cast::toString($value ?? '');
    if ($key === '' || array_key_exists($key, $options)) {
      return NULL;
    }
    return E::ts('unknown %1 "%2".', [1 => $label, 2 => $key]);
  }

  /**
   * @return array<string, string>
   */
  public static function periods(): array {
    return [
      'yesterday' => E::ts('Yesterday'),
      'today' => E::ts('Today'),
      'last_7_days' => E::ts('Last 7 days'),
      'last_30_days' => E::ts('Last 30 days'),
    ];
  }

  /**
   * @return array<string, string>
   */
  public static function blockTypes(): array {
    return [
      'contribution' => E::ts('Contributions'),
      'search' => E::ts('Search display'),
      'section' => E::ts('Section from an extension'),
    ];
  }

  /**
   * @return array<string, string>
   */
  public static function groupings(): array {
    return [
      'source' => E::ts('Contribution source'),
      'line_item' => E::ts('Line item and unit price'),
    ];
  }

  /**
   * Default message templates whose workflow name starts with herald_ and names a digest.
   *
   * @return array<string, string>
   */
  public static function blockTemplates(): array {
    /** @var list<array<string, mixed>> $rows */
    $rows = \Civi\Api4\MessageTemplate::get(checkPermissions: FALSE)
      ->addSelect('workflow_name', 'msg_title')
      ->addWhere('workflow_name', 'LIKE', 'herald\_%digest%')
      ->addWhere('is_default', '=', value: TRUE)
      ->addOrderBy('msg_title')
      ->execute()
      ->getArrayCopy();
    $options = [];
    foreach ($rows as $row) {
      $name = Cast::toString($row['workflow_name'] ?? '');
      $title = Cast::toString($row['msg_title'] ?? '');
      $options[$name] = $title !== '' ? $title : $name;
    }
    return $options;
  }

}

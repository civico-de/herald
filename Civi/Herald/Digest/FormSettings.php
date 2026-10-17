<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Settings of the Angular module heraldDigest (CRM.heraldDigest): the schema
 * defaults the digest form gives a new source, and the ready-made sources it offers.
 */
final class FormSettings {

  /**
   * @return array{sourceDefaults: array<string, mixed>, readyMade: array<string, mixed>}
   */
  public static function angular(): array {
    /** @var list<array<string, mixed>> $fields */
    $fields = \Civi\Api4\HeraldDigestSource::getFields(checkPermissions: FALSE)
      ->setAction('create')
      ->addSelect('name', 'default_value')
      ->addWhere('default_value', 'IS NOT NULL')
      ->execute()
      ->getArrayCopy();
    return [
      'sourceDefaults' => Cast::namedOnly(array_column($fields, 'default_value', 'name')),
      'readyMade' => [
        'figures' => ShippedSearches::figureLabels(),
        'periods' => array_map(
          static fn (string $label): string => mb_strtoupper(mb_substr($label, 0, 1)) . mb_substr($label, 1),
          ShippedSearches::periodLabels(),
        ),
        'displays' => ShippedSearches::available(),
      ],
    ];
  }

}

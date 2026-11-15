<?php

declare(strict_types = 1);

namespace Civi\Herald\Condition;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * The entities the field-transition condition offers.
 */
final class EntityOptions {

  /**
   * Name => title of the entities stored in tables, which fire the hooks the
   * condition watches; a stored name outside them stays selectable.
   *
   * @return array<string, string>
   */
  public static function forCondition(string $current = ''): array {
    $entities = \Civi\Api4\Entity::get(FALSE)
      ->addSelect('name', 'title')
      ->addWhere('type', 'CONTAINS', 'DAOEntity')
      ->execute();
    $options = [];
    foreach ($entities as $entity) {
      if (!is_array($entity)) {
        continue;
      }
      $options[Cast::toString($entity['name'] ?? '')] = Cast::toString($entity['title'] ?? '');
    }
    if ($current !== '' && !isset($options[$current])) {
      $options[$current] = E::ts('%1 (unknown entity)', [1 => $current]);
    }
    natcasesort($options);
    return $options;
  }

}

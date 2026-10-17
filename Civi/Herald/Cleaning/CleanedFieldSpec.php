<?php

declare(strict_types = 1);

namespace Civi\Herald\Cleaning;

use Civi\Api4\Service\Spec\FieldSpec;
use Civi\Api4\Service\Spec\Provider\Generic\SpecProviderInterface;
use Civi\Api4\Service\Spec\RequestSpec;
use Civi\Core\Service\AutoService;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * Adds each cleaned field to its entity's get action, computed in SQL like
 * core's Contact.age_years, so searches can filter and group by it.
 *
 * @service herald.cleaned_field_spec
 */
final class CleanedFieldSpec extends AutoService implements SpecProviderInterface {

  public function applies($entity, $action): bool {
    return $action === 'get' && CleanedFields::forEntity($entity) !== [];
  }

  public function modifySpec(RequestSpec $spec): void {
    foreach (CleanedFields::forEntity($spec->getEntity()) as $entry) {
      $raw = $spec->getFieldByName($entry['field']);
      if ($raw === NULL) {
        continue;
      }
      $field = new FieldSpec(CleanedFields::name($entry['field']), $spec->getEntity(), 'String');
      $title = E::ts('%1 (cleaned)', [1 => $raw->getTitle()]);
      $field
        ->setLabel($title)
        ->setTitle($title)
        ->setColumnName($raw->getColumnName())
        ->setInputType('Text')
        ->setDescription(E::ts('The field after the cleaning rules of herald.'))
        ->setType('Extra')
        ->setReadonly(TRUE)
        ->setPermission($raw->getPermission())
        ->setSqlRenderer([self::class, 'render']);
      $spec->addFieldSpec($field);
    }
  }

  /**
   * Rules are read when the query runs, so a saved change applies at once.
   *
   * @param array<string, mixed> $field
   */
  public static function render(array $field): string {
    $column = Cast::toString($field['sql_name']);
    foreach (CleanedFields::forEntity(Cast::toString($field['entity'])) as $entry) {
      if (CleanedFields::name($entry['field']) === $field['name']) {
        return CaseExpression::sql($column, $entry['rules'], $entry['fallback']);
      }
    }
    return $column;
  }

}

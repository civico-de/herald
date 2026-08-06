<?php

declare(strict_types = 1);

/**
 * Reads pre-edit field values out of CiviRules' trigger data.
 *
 * CiviRules serialises the original entity values into its queue, which is the
 * only pre-edit source that survives delayed rule execution. Only trigger
 * objects implementing CRM_Civirules_TriggerData_Interface_OriginalData carry
 * them; everything else answers "unknown".
 */
final class CRM_Herald_CivirulesAdapter_OriginalData {

  /**
   * Pre-edit value of any field: ['known' => FALSE] when the trigger carries
   * no original data for this entity or the field is absent from it.
   *
   * @return array{known: bool, value: mixed}
   */
  public static function rawField(
    CRM_Civirules_TriggerData_TriggerData $triggerData,
    string $entity,
    string $field,
  ): array {
    if (
      !$triggerData instanceof CRM_Civirules_TriggerData_Interface_OriginalData
      || strtolower((string) $triggerData->getOriginalEntity()) !== strtolower($entity)
    ) {
      return ['known' => FALSE, 'value' => NULL];
    }
    $original = $triggerData->getOriginalData();
    if (!array_key_exists($field, $original)) {
      return ['known' => FALSE, 'value' => NULL];
    }
    return ['known' => TRUE, 'value' => $original[$field]];
  }

  /**
   * Pre-edit value of an integer field, or $default when unknown.
   */
  public static function intField(
    CRM_Civirules_TriggerData_TriggerData $triggerData,
    string $entity,
    string $field,
    int $default = 0,
  ): int {
    if (
      !$triggerData instanceof CRM_Civirules_TriggerData_Interface_OriginalData
      || strtolower((string) $triggerData->getOriginalEntity()) !== strtolower($entity)
    ) {
      return $default;
    }
    $original = $triggerData->getOriginalData();
    $value = $original[$field] ?? NULL;
    if ($value === NULL || $value === '' || !is_scalar($value)) {
      return $default;
    }
    return (int) $value;
  }

}

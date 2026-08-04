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

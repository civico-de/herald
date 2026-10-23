<?php

declare(strict_types = 1);

/**
 * Reads one edit's field values out of CiviRules' trigger data.
 *
 * CiviRules serialises the original entity values into its queue, which is the
 * only pre-edit source that survives delayed rule execution. Only trigger
 * objects implementing CRM_Civirules_TriggerData_Interface_OriginalData carry
 * them; everything else answers "unknown".
 */
final class CRM_Herald_CivirulesAdapter_EditData {

  /**
   * Pre-edit value of any field: ['known' => FALSE] when the trigger carries
   * no original data for this entity or the field is absent from it.
   * CiviRules keeps custom fields as custom_N, so a group.field stays unknown.
   *
   * @return array{known: bool, value: mixed}
   */
  public static function before(
    CRM_Civirules_TriggerData_TriggerData $triggerData,
    string $entity,
    string $field,
  ): array {
    if (
      !$triggerData instanceof CRM_Civirules_TriggerData_Interface_OriginalData
      || strtolower($triggerData->getOriginalEntity()) !== strtolower($entity)
    ) {
      return ['known' => FALSE, 'value' => NULL];
    }
    return \Civi\Herald\Condition\StoredRow::value($entity, $field, $triggerData->getOriginalData());
  }

  /**
   * Value of a field as this edit wrote it. Rules run after the commit, when the
   * live row may already hold a later edit's value; unknown when the edit lacks the field.
   *
   * @return array{known: bool, value: mixed}
   */
  public static function after(
    CRM_Civirules_TriggerData_TriggerData $triggerData,
    string $entity,
    string $field,
  ): array {
    return \Civi\Herald\Condition\WrittenRow::value($entity, $field, $triggerData->getEntityData($entity));
  }

  /**
   * All pre-edit values the trigger carries, keyed by its entity name; [] when none.
   *
   * @return array<string, array<array-key, mixed>>
   */
  public static function originals(CRM_Civirules_TriggerData_TriggerData $triggerData): array {
    if (!$triggerData instanceof CRM_Civirules_TriggerData_Interface_OriginalData) {
      return [];
    }
    $entity = $triggerData->getOriginalEntity();
    return $entity !== '' ? [$entity => $triggerData->getOriginalData()] : [];
  }

}

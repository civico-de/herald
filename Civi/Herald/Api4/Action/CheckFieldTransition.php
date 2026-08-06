<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Herald\Condition\FieldTransition;
use Civi\Herald\Condition\FieldTransitionSnapshotter;
use Civi\Herald\PreEditSnapshot;

/**
 * Did field X of entity E transition from (operator, value) to (operator, value)?
 *
 * The decision behind the "Field transition" CiviRules condition, addressable
 * as an API so the adapter carries no logic of its own. The pre-edit value is
 * resolved original-data first (per-edit, survives delayed execution), then
 * herald's hook_civicrm_pre snapshot; the post-edit value is read from the
 * live row, not the trigger payload (which may omit the field entirely).
 */
class CheckFieldTransition extends AbstractAction {

  /**
   * APIv4 entity name of the edited row (e.g. "Contribution").
   * @var string
   */
  protected string $entity = '';

  /**
   * Id of the edited row.
   * @var int
   */
  protected int $entityId = 0;

  /**
   * Field name to compare (APIv4 field name, custom fields as "group.field").
   * @var string
   */
  protected string $field = '';

  /**
   * Operator for the pre-edit side; '' = side not configured.
   * @var string
   */
  protected string $fromOperator = '';

  /**
   * Comparison value for the pre-edit side.
   * @var string
   */
  protected string $fromValue = '';

  /**
   * Operator for the post-edit side; '' = side not configured.
   * @var string
   */
  protected string $toOperator = '';

  /**
   * Comparison value for the post-edit side.
   * @var string
   */
  protected string $toValue = '';

  /**
   * Pre-edit field value from the caller's own original data (CiviRules
   * serialises it into its queue); only read when $hasOriginal is TRUE.
   * @var mixed
   */
  protected mixed $originalValue = NULL;

  /**
   * TRUE when the caller could supply an original-data value at all.
   * @var bool
   */
  protected bool $hasOriginal = FALSE;

  public function _run(Result $result): void {
    $result[] = ['matched' => $this->matched()];
  }

  private function matched(): bool {
    if ($this->entity === '' || $this->field === '' || $this->entityId <= 0) {
      return FALSE;
    }

    $beforeState = self::resolveBeforeState(
      ['known' => $this->hasOriginal, 'value' => $this->originalValue],
      $this->entity,
      $this->entityId,
      $this->field,
    );

    $current = FieldTransitionSnapshotter::currentValue($this->entity, $this->entityId, $this->field);
    if (!$current['known']) {
      return FALSE;
    }

    return FieldTransition::matches(
      FieldTransition::side($this->fromOperator, $this->fromValue),
      FieldTransition::side($this->toOperator, $this->toValue),
      $beforeState,
      $current['value'],
    );
  }

  /**
   * Original-data first, herald's hook_civicrm_pre snapshot as fallback. A
   * snapshotted NULL is a *known* before-value (PreEditSnapshot::has()), so
   * NULL→x transitions match "from is_empty" conditions.
   *
   * @param array{known: bool, value: mixed} $original Caller-supplied
   *   original-data state (known=FALSE when none was available).
   * @return array{known: bool, value: mixed}
   */
  public static function resolveBeforeState(
    array $original,
    string $entity,
    int $entityId,
    string $field,
  ): array {
    if ($original['known']) {
      return ['known' => TRUE, 'value' => $original['value']];
    }
    if (PreEditSnapshot::has($entity, $entityId, $field)) {
      return ['known' => TRUE, 'value' => PreEditSnapshot::get($entity, $entityId, $field)];
    }
    return ['known' => FALSE, 'value' => NULL];
  }

}

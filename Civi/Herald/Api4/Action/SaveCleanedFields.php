<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Herald\Cleaning\CleanedFields;
use Civi\Herald\Cleaning\CleanedFieldsInput;

/**
 * Validate and store the cleaned fields; regexes are compiled by the database first.
 *
 * Example:
 *   cv api4 Herald.saveCleanedFields 'fields=[{"entity":"Contribution","field":"source","rules":[{"match":"Bank TX","type":"contains","label":"Transfer"}],"fallback":""}]'
 *
 * @method $this setFields(list<array<string, mixed>> $fields)
 */
class SaveCleanedFields extends AbstractAction {

  /**
   * Entries of entity, field, ordered rules (match, type: contains, prefix, regex; label) and fallback.
   * @var array
   * @phpstan-var array<array-key, mixed>
   */
  protected array $fields = [];

  public function _run(Result $result): void {
    $fields = CleanedFieldsInput::clean($this->fields);
    \Civi::settings()->set(CleanedFields::SETTING, $fields);
    \Civi::cache('metadata')->clear();
    $result->exchangeArray($fields);
  }

}

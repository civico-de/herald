<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Herald\Admin\SourceRules;

/**
 * Validate and store the contribution-source rules; regexes are compiled first.
 *
 * Example:
 *   cv api4 Herald.saveSourceRules 'rules=[{"match":"Bank TX","type":"contains","label":"Transfer"}]'
 *
 * @method $this setRules(list<array<string, mixed>> $rules)
 */
class SaveSourceRules extends AbstractAction {

  /**
   * Ordered rules of match, type (contains, prefix, regex) and label. Empty = no normalization.
   * @var array
   * @phpstan-var array<array-key, mixed>
   */
  protected array $rules = [];

  public function _run(Result $result): void {
    $rules = SourceRules::clean($this->rules);
    \Civi::settings()->set(SourceRules::SETTING, $rules);
    $result->exchangeArray($rules);
  }

}

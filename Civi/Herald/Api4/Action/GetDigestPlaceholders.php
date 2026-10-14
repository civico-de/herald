<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Herald\Digest\Definition;
use Civi\Herald\Digest\DigestProblem;
use Civi\Herald\Digest\Snippet;
use Civi\Herald\Digest\SourceRules;
use CRM_Herald_ExtensionUtil as E;

/**
 * The placeholders of one digest source and a snippet that uses them, for the
 * digest form; `problems` says why there are none.
 *
 * Example:
 *   cv api4 Herald.getDigestPlaceholders 'source={"name":"donations","type":"search","search_display_id":12}'
 */
class GetDigestPlaceholders extends AbstractAction {

  /**
   * HeraldDigestSource values: name, type, and search_display_id or section_key.
   * @var array
   * @phpstan-var array<string, mixed>
   */
  protected array $source = [];

  /**
   * Name of the digest; a section is asked with it as context.
   * @var string
   */
  protected string $digest = '';

  public function _run(Result $result): void {
    $source = Definition::source($this->source);
    $problems = array_values(SourceRules::problems($source));
    if ($problems === []) {
      try {
        $result[] = Snippet::of($source, $this->digest) + ['problems' => []];
        return;
      }
      catch (DigestProblem $e) {
        $problems = $e->problems();
      }
      catch (\CRM_Core_Exception $e) {
        $problems = [E::ts('The placeholders cannot be read: %1', [1 => $e->getMessage()])];
      }
    }
    $result[] = ['placeholders' => [], 'snippet' => '', 'problems' => $problems];
  }

}

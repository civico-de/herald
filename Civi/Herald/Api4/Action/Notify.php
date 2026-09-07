<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Herald\Dispatch\Dispatcher;
use Civi\Herald\Subscribers\SubscriptionScope;
use Civi\Herald\Trigger\ContextVars;

/**
 * Render a herald workflow template and deliver it; the CiviRules adapter and
 * any other caller with a params bag and trigger context enter here.
 *
 * Example:
 *   cv api4 Herald.notify +v 'actionParams={"template":"herald_generic","channel":"telegram"}'
 */
class Notify extends AbstractAction {

  /**
   * The flat parameter bag (see Dispatcher) plus `vars`. Plain `@var array`:
   * core parses this at runtime and rejects generics.
   *
   * @var array
   * @phpstan-var array<string, mixed>
   */
  protected array $actionParams = [];

  /**
   * `contact_id` plus raw entity payloads keyed by entity name; all become
   * template variables, explicit `vars` win.
   *
   * @var array
   * @phpstan-var array<string, mixed>
   */
  protected array $triggerContext = [];

  public function _run(Result $result): void {
    $params = $this->actionParams;
    // Resolved here so buffered sends carry the scope through the flush.
    $scope = SubscriptionScope::fromContext($params, $this->triggerContext);
    if ($scope !== NULL) {
      $params['scope_entity_type'] = $scope['entity_type'];
      $params['scope_entity_id'] = $scope['entity_id'];
    }
    $vars = ContextVars::decodeVars($params) + ContextVars::build($this->triggerContext);
    $result[] = (new Dispatcher())->dispatch($params, $vars);
  }

}

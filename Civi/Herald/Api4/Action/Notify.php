<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Herald\Dispatch\Dispatcher;
use Civi\Herald\Trigger\ContextVars;

/**
 * Render a herald workflow template and deliver it (generic notification).
 *
 * String-addressed entry point behind which the whole notification pipeline
 * sits: the CiviRules adapter unpacks its trigger and calls this, and so can
 * anything else that has a params bag and a trigger context.
 *
 * Example:
 *   cv api4 Herald.notify +v 'actionParams={"template":"herald_generic","channel":"telegram"}'
 */
class Notify extends AbstractAction {

  /**
   * The flat parameter bag: template, channel, delivery_mode, aggregate_minutes,
   * channel-specific keys, plus `vars` (JSON object or array of static template
   * variables). See Civi\Herald\Dispatch\Dispatcher.
   *
   * Plain `@var array` is deliberate — core parses this annotation at RUNTIME
   * to type the API parameter and does not understand generics. The generic
   * lives in @phpstan-var so static analysis loses nothing.
   *
   * @var array
   * @phpstan-var array<string, mixed>
   */
  protected array $actionParams = [];

  /**
   * Trigger context: `contact_id` plus raw entity payloads keyed by entity name
   * (GroupContact, Membership, Participant, Event). Everything present is
   * turned into template variables; explicit keys in `vars` win.
   *
   * @var array
   * @phpstan-var array<string, mixed>
   */
  protected array $triggerContext = [];

  public function _run(Result $result): void {
    $vars = ContextVars::decodeVars($this->actionParams) + ContextVars::build($this->triggerContext);
    $result[] = (new Dispatcher())->dispatch($this->actionParams, $vars);
  }

}

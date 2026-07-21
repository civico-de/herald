<?php

namespace Civi\Herald\Api4\Action;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Herald\Renderer\TemplateRenderer;

/**
 * Render a workflow MessageTemplate with arbitrary variables and return the body.
 * For previewing template output without sending anything.
 *
 * Example:
 *   cv api4 Herald.renderTemplate +v 'workflow=herald_contribution_alert_completed' \
 *       +v 'vars={"contribution":{"total_amount":42.5,"source":"Stripe"}}'
 */
class RenderTemplate extends AbstractAction {

  /**
   * MessageTemplate.workflow_name to render.
   * @var string
   */
  protected string $workflow = '';

  /**
   * Variables passed to Smarty as `{$key}`.
   * @var array
   * @phpstan-var array<string, mixed>
   */
  protected array $vars = [];

  public function _run(Result $result): void {
    if ($this->workflow === '') {
      throw new \CRM_Core_Exception('Herald.renderTemplate: "workflow" parameter is required.');
    }
    $renderer = new TemplateRenderer();
    $body = $renderer->render($this->workflow, $this->vars);
    $result[] = ['workflow' => $this->workflow, 'body' => $body];
  }

}

<?php

namespace Civi\CivicoNotify\Api4\Action;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\CivicoNotify\Renderer\TemplateRenderer;

/**
 * Render a workflow MessageTemplate with arbitrary variables and return the body.
 * For previewing template output without sending anything.
 *
 * Example:
 *   cv api4 CivicoNotify.renderTemplate +v 'workflow=civico_notify_contribution_alert_completed' \
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
   */
  protected array $vars = [];

  public function _run(Result $result) {
    if ($this->workflow === '') {
      throw new \CRM_Core_Exception('CivicoNotify.renderTemplate: "workflow" parameter is required.');
    }
    $renderer = new TemplateRenderer();
    $body = $renderer->render($this->workflow, $this->vars);
    $result[] = ['workflow' => $this->workflow, 'body' => $body];
  }

}

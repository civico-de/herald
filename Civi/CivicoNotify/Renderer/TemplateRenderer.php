<?php

namespace Civi\CivicoNotify\Renderer;

/**
 * Look up a CiviCRM MessageTemplate by workflow_name and render its msg_html
 * with Smarty, exposing caller-supplied variables as `{$name}` in the template.
 *
 * Workflow templates are admin-editable in *Mailings → Message Templates* —
 * everything we ship via managed/MessageTemplate.mgd.php lands there.
 */
class TemplateRenderer {

  /**
   * @param string $workflowName The MessageTemplate.workflow_name to look up.
   * @param array $vars Smarty variables. Reachable as `{$key}` in the template.
   * @return string The rendered message body.
   * @throws \CRM_Core_Exception when the template is missing.
   */
  public function render(string $workflowName, array $vars): string {
    $template = \Civi\Api4\MessageTemplate::get(FALSE)
      ->addSelect('msg_html', 'msg_text')
      ->addWhere('workflow_name', '=', $workflowName)
      ->addWhere('is_default', '=', TRUE)
      ->setLimit(1)
      ->execute()
      ->first();

    if (!$template) {
      throw new \CRM_Core_Exception(sprintf(
        'civico_notify: MessageTemplate "%s" not found. Is the civico_notify extension enabled?',
        $workflowName
      ));
    }

    $body = (string) ($template['msg_html'] ?? $template['msg_text'] ?? '');
    if ($body === '') {
      throw new \CRM_Core_Exception(sprintf(
        'civico_notify: MessageTemplate "%s" has empty msg_html and msg_text.',
        $workflowName
      ));
    }

    return $this->renderInline($body, $vars);
  }

  /**
   * Render an arbitrary Smarty string with the given vars. Used when callers
   * already hold the raw template body (e.g. tests).
   */
  public function renderInline(string $body, array $vars): string {
    $smarty = \CRM_Core_Smarty::singleton();
    $smarty->pushScope($vars);
    try {
      return $smarty->fetch('string:' . $body);
    }
    finally {
      $smarty->popScope();
    }
  }

}

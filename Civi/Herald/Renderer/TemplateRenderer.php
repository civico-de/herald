<?php

namespace Civi\Herald\Renderer;

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
   * @param array<string, mixed> $vars Smarty variables. Reachable as `{$key}` in the template.
   * @return string The rendered message body.
   * @throws \CRM_Core_Exception when the template is missing.
   */
  public function render(string $workflowName, array $vars): string {
    return $this->renderParts($workflowName, $vars)['body'];
  }

  /**
   * Render body AND subject of a workflow template. The subject (msg_subject,
   * also Smarty) is used as the default email subject; channels without a
   * subject concept ignore it.
   *
   * @param array<string, mixed> $vars
   * @return array{subject: ?string, body: string} subject is NULL when the
   *   template has no msg_subject.
   * @throws \CRM_Core_Exception when the template is missing.
   */
  public function renderParts(string $workflowName, array $vars): array {
    $template = \Civi\Api4\MessageTemplate::get(FALSE)
      ->addSelect('msg_html', 'msg_text', 'msg_subject')
      ->addWhere('workflow_name', '=', $workflowName)
      ->addWhere('is_default', '=', TRUE)
      ->setLimit(1)
      ->execute()
      ->first();

    if (!$template) {
      throw new \CRM_Core_Exception(sprintf(
        'herald: MessageTemplate "%s" not found. Is the herald extension enabled?',
        $workflowName,
      ));
    }

    $body = self::str($template['msg_html'] ?? $template['msg_text'] ?? '');
    if ($body === '') {
      throw new \CRM_Core_Exception(sprintf(
        'herald: MessageTemplate "%s" has empty msg_html and msg_text.',
        $workflowName,
      ));
    }

    $subject = trim(self::str($template['msg_subject'] ?? ''));
    return [
      'subject' => $subject !== '' ? $this->renderInline($subject, $vars) : NULL,
      'body' => $this->renderInline($body, $vars),
    ];
  }

  /**
   * Render an arbitrary Smarty string with the given vars. Used when callers
   * already hold the raw template body (e.g. tests).
   *
   * @param array<string, mixed> $vars
   */
  public function renderInline(string $body, array $vars): string {
    $smarty = \CRM_Core_Smarty::singleton();
    $smarty->pushScope($vars);
    try {
      // CRM_Core_Smarty (via CRM_Core_SmartyCompatibility) has no fetch()
      // method of its own — it forwards unknown calls to the wrapped
      // Smarty\Smarty instance through __call(). phpstan cannot see through
      // that magic dispatch (and the project may not add a core stub to
      // teach it), but fetch() genuinely exists on the wrapped object and
      // returns a string here.
      // @phpstan-ignore method.notFound, return.type
      return $smarty->fetch('string:' . $body);
    }
    finally {
      $smarty->popScope();
    }
  }

  /**
   * Narrow a mixed APIv4-row value to string. Message template fields are
   * declared as string columns; this guards the cast against the
   * theoretical mixed type without changing behaviour for well-formed data.
   */
  private static function str(mixed $value): string {
    return is_scalar($value) ? (string) $value : '';
  }

}

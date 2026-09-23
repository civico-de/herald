<?php

declare(strict_types = 1);

namespace Civi\Herald\Renderer;

use Civi\Herald\Channel\PlainText;
use Civi\Herald\Util\Cast;

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
   * @return array{subject: ?string, body: string} body is HTML (a msg_text
   *   body is escaped into it), subject is text or NULL without msg_subject.
   * @throws \CRM_Core_Exception when the template is missing.
   */
  public function renderParts(string $workflowName, array $vars): array {
    $template = \Civi\Api4\MessageTemplate::get(checkPermissions: FALSE)
      ->addSelect('msg_html', 'msg_text', 'msg_subject')
      ->addWhere('workflow_name', '=', $workflowName)
      ->addWhere('is_default', '=', value: TRUE)
      ->setLimit(1)
      ->execute()
      ->first();

    if (!$template) {
      throw new \CRM_Core_Exception(sprintf(
        'herald: MessageTemplate "%s" not found. Is the herald extension enabled?',
        $workflowName,
      ));
    }

    $isHtml = isset($template['msg_html']);
    $body = self::str($template['msg_html'] ?? $template['msg_text'] ?? '');
    if ($body === '') {
      throw new \CRM_Core_Exception(sprintf(
        'herald: MessageTemplate "%s" has empty msg_html and msg_text.',
        $workflowName,
      ));
    }

    $subject = trim(self::str($template['msg_subject'] ?? ''));
    $textVars = self::mapStrings($vars, self::decode(...));
    return [
      'subject' => $subject !== '' ? $this->renderInline($subject, $textVars) : NULL,
      'body' => $isHtml
        ? $this->renderInline($body, self::escapeHtml($vars))
        : PlainText::toHtml($this->renderInline($body, $textVars)),
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
      // CRM_Core_Smarty forwards fetch() to the wrapped Smarty\Smarty via
      // __call(), which phpstan cannot see through.
      // @phpstan-ignore method.notFound, return.type
      return $smarty->fetch('string:' . $body);
    }
    finally {
      $smarty->popScope();
    }
  }

  /**
   * Vars are plain text; msg_html is HTML (Telegram parse_mode=HTML, email), so
   * `<`, `>` and `&` in values must not be read as markup.
   *
   * @template K of array-key
   * @param array<K, mixed> $vars
   * @return array<K, mixed>
   */
  public static function escapeHtml(array $vars): array {
    return self::mapStrings($vars, static fn (string $v): string => PlainText::toHtml(self::decode($v)));
  }

  /**
   * APIv3 and forms store `<`/`>` as `&lt;`/`&gt;` (HTMLInputCoder).
   */
  private static function decode(string $value): string {
    return Cast::toString(\CRM_Utils_API_HTMLInputCoder::singleton()->decodeValue($value));
  }

  /**
   * @template K of array-key
   * @param array<K, mixed> $vars
   * @param \Closure(string): string $fn
   * @return array<K, mixed>
   */
  private static function mapStrings(array $vars, \Closure $fn): array {
    return array_map(
      static fn (mixed $v): mixed => match (TRUE) {
        is_string($v) => $fn($v),
        is_array($v) => self::mapStrings($v, $fn),
        default => $v,
      },
      $vars,
    );
  }

  /**
   * Non-scalar config values collapse to '' instead of "Array".
   */
  private static function str(mixed $value): string {
    return is_scalar($value) ? (string) $value : '';
  }

}

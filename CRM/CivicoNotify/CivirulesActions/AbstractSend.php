<?php

use Civi\CivicoNotify\Channel\ChannelRegistry;
use Civi\CivicoNotify\Renderer\TemplateRenderer;

/**
 * Shared render-and-dispatch logic for civico_notify CiviRules actions.
 *
 * Subclasses build the variable bag for the template; this base resolves the
 * channel and workflow template, renders, and sends.
 */
abstract class CRM_CivicoNotify_CivirulesActions_AbstractSend extends CRM_Civirules_Action {

  /**
   * Return the URL of the per-action parameter form. Subclasses point to their
   * concrete CRM_CivicoNotify_Form_CivirulesAction_* path.
   */
  public function getExtraDataInputUrl($ruleActionId) {
    return \CRM_Utils_System::url($this->getFormPath(), 'reset=1&action=update&id=' . $ruleActionId);
  }

  /**
   * Civi route of the parameter form. Override in each concrete subclass.
   */
  abstract protected function getFormPath(): string;

  /**
   * Render the chosen template with $vars and dispatch through the chosen channel.
   *
   * Action parameters (set in the CiviRules action editor):
   *   channel        — channel key (default "telegram")
   *   template       — MessageTemplate workflow_name to render (required)
   *   chat_id        — channel-specific recipient (Telegram chat/group ID)
   *   parse_mode     — Telegram parse mode (default HTML)
   *   disable_notification, disable_web_page_preview — bool, optional
   */
  protected function dispatch(array $vars): void {
    $params = $this->getActionParameters();
    $channelKey = $params['channel'] ?? 'telegram';
    $workflowName = $params['template'] ?? '';
    if ($workflowName === '') {
      throw new \CRM_Core_Exception('civico_notify: action parameter "template" (workflow_name) is required.');
    }

    $renderer = new TemplateRenderer();
    $body = $renderer->render($workflowName, $vars);

    $channelConfig = array_filter([
      'chat_id' => $params['chat_id'] ?? NULL,
      'parse_mode' => $params['parse_mode'] ?? NULL,
      'disable_notification' => $params['disable_notification'] ?? NULL,
      'disable_web_page_preview' => $params['disable_web_page_preview'] ?? NULL,
    ], static fn($v) => $v !== NULL && $v !== '');

    $channel = ChannelRegistry::default()->get($channelKey);
    $channel->send($body, $channelConfig);
  }

  protected function buildFilters(array $params): array {
    return array_filter([
      'campaign_id' => isset($params['campaign_id']) ? (int) $params['campaign_id'] : NULL,
      'financial_type_id' => isset($params['financial_type_id']) ? (int) $params['financial_type_id'] : NULL,
      'source_pattern' => $params['source_pattern'] ?? NULL,
      'status' => $params['status'] ?? NULL,
    ], static fn($v) => $v !== NULL && $v !== '');
  }

}

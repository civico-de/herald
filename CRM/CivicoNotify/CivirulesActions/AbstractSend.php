<?php

use Civi\CivicoNotify\Channel\ChannelRegistry;
use Civi\CivicoNotify\Renderer\TemplateRenderer;
use Civi\CivicoNotify\Subscribers\SubscriberLookup;

/**
 * Shared render-and-dispatch logic for civico_notify CiviRules actions.
 *
 * Subclasses build the variable bag for the template; this base resolves the
 * channel and workflow template, renders, and sends — either directly to a
 * single recipient configured on the rule, or fanned out to all contacts
 * subscribed to a given subscription type (delivery_mode=subscribers).
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
   *   channel             — channel key (default "telegram"); used for direct delivery
   *   template            — MessageTemplate workflow_name to render (required)
   *   delivery_mode       — "direct" (default) or "subscribers"
   *   subscription_type   — required when delivery_mode=subscribers; one of the
   *                         civico_notify_subscription_type option-group values
   *   chat_id, parse_mode, disable_notification, … — channel-specific keys
   *                         (read by the channel implementations)
   */
  protected function dispatch(array $vars): void {
    $params = $this->getActionParameters();
    $workflowName = $params['template'] ?? '';
    if ($workflowName === '') {
      throw new \CRM_Core_Exception('civico_notify: action parameter "template" (workflow_name) is required.');
    }

    $renderer = new TemplateRenderer();
    $body = $renderer->render($workflowName, $vars);

    if (($params['delivery_mode'] ?? 'direct') === 'subscribers') {
      $this->dispatchToSubscribers($body, $params);
    }
    else {
      $this->dispatchDirect($body, $params);
    }
  }

  /**
   * Direct delivery: one rendered body, one channel call. The historical path.
   */
  protected function dispatchDirect(string $body, array $params): void {
    $channel = ChannelRegistry::default()->get($params['channel'] ?? 'telegram');
    $channel->send($body, self::buildChannelConfig($params));
  }

  /**
   * Subscriber-fanout delivery: look up all contacts opted into the given
   * subscription_type, send the same rendered body through *each contact's*
   * preferred channel and recipient ID. Per-recipient errors are caught so
   * one bad chat_id can't blank out everyone else's notification.
   */
  protected function dispatchToSubscribers(string $body, array $params): void {
    $subscriptionType = (string) ($params['subscription_type'] ?? '');
    if ($subscriptionType === '') {
      throw new \CRM_Core_Exception('civico_notify: subscriber-mode requires action parameter "subscription_type".');
    }

    $registry = ChannelRegistry::default();
    $extras = self::buildChannelConfig($params);

    foreach ($this->makeSubscriberLookup()->findBySubscriptionType($subscriptionType) as $sub) {
      try {
        $channel = $registry->get($sub['channel']);
        $channel->send($body, array_merge($extras, $sub['config']));
      }
      catch (\Throwable $e) {
        \Civi::log()->warning('civico_notify: subscriber dispatch failed', [
          'contact_id' => $sub['contact_id'] ?? NULL,
          'channel' => $sub['channel'] ?? NULL,
          'error' => $e->getMessage(),
        ]);
      }
    }
  }

  /**
   * Test-seam — overridable in subclass tests to inject a fake lookup.
   */
  protected function makeSubscriberLookup(): SubscriberLookup {
    return new SubscriberLookup();
  }

  /**
   * Build the per-call config dict passed to a channel.
   *
   * The full action params are forwarded verbatim (channels read whichever
   * keys they care about — `chat_id` for Telegram, `webhook_url` for Webhook,
   * `ntfy_topic` for Ntfy, etc.) plus any per-call overrides decoded from the
   * `channel_config` JSON textarea on the form.
   *
   * Test-seam: pure, no Civi dependencies.
   */
  public static function buildChannelConfig(array $params): array {
    $config = $params;
    if (!empty($params['channel_config'])) {
      $extra = is_array($params['channel_config'])
        ? $params['channel_config']
        : json_decode((string) $params['channel_config'], TRUE);
      if (is_array($extra)) {
        $config = array_merge($config, $extra);
      }
    }
    unset($config['channel_config'], $config['channel'], $config['template']);
    return $config;
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

<?php

declare(strict_types = 1);

namespace Civi\Herald\Dispatch;

use Civi\Herald\Buffer\AlertBuffer;
use Civi\Herald\Channel\ChannelRegistry;
use Civi\Herald\Renderer\TemplateRenderer;
use Civi\Herald\Subscribers\SubscriberLookup;
use Civi\Herald\Util\Cast;

/**
 * Render-and-dispatch core of herald.
 *
 * Owns everything that happens once a variable bag exists: resolve the
 * workflow template, render it, then either park the result in the alert
 * buffer (aggregation window) or deliver it — directly to the recipient
 * configured on the caller's params, or fanned out to all contacts subscribed
 * to a subscription type.
 *
 * Callers: the Herald.notify* APIv4 actions (the CiviRules adapters go through
 * those) and Job.herald_buffer_flush, which re-enters at deliver().
 *
 * Parameters (the flat "params" bag; historically CiviRules action params):
 *   channel             — channel key (default "telegram"); used for direct delivery
 *   template            — MessageTemplate workflow_name to render (required)
 *   delivery_mode       — "direct" (default) or "subscribers"
 *   subscription_type   — required when delivery_mode=subscribers; one of the
 *                         herald_subscription_type option-group values
 *   aggregate_minutes   — int (default 0 = send immediately). When > 0, the
 *                         rendered body is parked in the alert buffer and
 *                         Job.herald_buffer_flush sends everything collected
 *                         within the window as ONE message.
 *   chat_id, parse_mode, disable_notification, … — channel-specific keys
 *                         (read by the channel implementations)
 * @mago-expect lint:cyclomatic-complexity
 */
class Dispatcher {

  /**
   * Config keys safe to forward from the caller's params into subscriber-
   * fanout sends: presentation-only options. Recipient and credential keys
   * (chat_id, webhook_url, webhook_auth_token, webhook_headers, ntfy_topic,
   * pushover_user_key, …) are deliberately absent — in subscriber mode the
   * recipient comes from the contact's own preferences, and caller-level
   * credentials must never be sent to a contact-supplied endpoint.
   */
  private const SUBSCRIBER_SAFE_KEYS = [
    'parse_mode',
    'disable_notification',
    'disable_web_page_preview',
    'webhook_body_format',
    'webhook_body_key',
    'ntfy_title',
    'ntfy_priority',
    'ntfy_tags',
    'ntfy_click',
    'ntfy_markdown',
    'pushover_title',
    'pushover_priority',
    'pushover_sound',
    'pushover_url',
    'pushover_url_title',
    'pushover_html',
    'pushover_retry',
    'pushover_expire',
    'email_subject',
    'email_format',
  ];

  private ?TemplateRenderer $renderer;

  private ?AlertBuffer $buffer;

  private ?SubscriberLookup $lookup;

  public function __construct(
    ?TemplateRenderer $renderer = NULL,
    ?AlertBuffer $buffer = NULL,
    ?SubscriberLookup $lookup = NULL,
  ) {
    $this->renderer = $renderer;
    $this->buffer = $buffer;
    $this->lookup = $lookup;
  }

  /**
   * Render $params['template'] with $vars and dispatch it.
   *
   * @param array<string, mixed> $params
   * @param array<string, mixed> $vars Template variable bag.
   * @return array{mode: string, template: string}
   *   mode is "buffered" when the send was parked for aggregation, else "sent".
   */
  public function dispatch(array $params, array $vars): array {
    // Group key for aggregation, taken from the RAW params: stable per caller
    // configuration. Must be computed before applyDefaultEmailSubject() mixes
    // a per-event rendered subject into $params.
    $groupHash = md5(json_encode($params) ?: '');
    $workflowName = Cast::toString($params['template'] ?? '');
    if ($workflowName === '') {
      throw new \CRM_Core_Exception('herald: action parameter "template" (workflow_name) is required.');
    }

    $renderer = $this->renderer ?? new TemplateRenderer();
    $parts = $renderer->renderParts($workflowName, $vars);
    $body = $parts['body'];
    $params = self::applyDefaultEmailSubject($params, $parts['subject']);

    $aggregateMinutes = Cast::toInt($params['aggregate_minutes'] ?? 0);
    if ($aggregateMinutes > 0) {
      ($this->buffer ?? new AlertBuffer())
        ->add($groupHash, $aggregateMinutes, $body, $params, self::aggregateAmount($vars));
      return ['mode' => 'buffered', 'template' => $workflowName];
    }

    if (($params['delivery_mode'] ?? 'direct') === 'subscribers') {
      self::deliver($body, ['delivery_mode' => 'subscribers'] + $params, $this->lookup);
      return ['mode' => 'sent', 'template' => $workflowName];
    }
    self::deliver($body, ['delivery_mode' => 'direct'] + $params);
    return ['mode' => 'sent', 'template' => $workflowName];
  }

  /**
   * Monetary value of the event behind this alert, summed into the aggregate
   * header ("N Meldungen, gesamt X €"). Default: the contribution amount when
   * the variable bag carries one; NULL keeps the sum out of the header.
   *
   * @param array<string, mixed> $vars
   */
  public static function aggregateAmount(array $vars): ?float {
    $contribution = $vars['contribution'] ?? NULL;
    if (is_array($contribution) && is_numeric($contribution['total_amount'] ?? NULL)) {
      return (float) $contribution['total_amount'];
    }
    return NULL;
  }

  /**
   * Delivery of an already-rendered body. Same direct/subscriber branch as
   * dispatch(), minus rendering — the buffer-flush job re-enters here.
   *
   * @param array<string, mixed> $params
   */
  public static function deliver(string $body, array $params, ?SubscriberLookup $lookup = NULL): void {
    if (($params['delivery_mode'] ?? 'direct') === 'subscribers') {
      self::deliverToSubscribers($body, $params, $lookup);
      return;
    }
    $channel = ChannelRegistry::default()->get(Cast::toString($params['channel'] ?? 'telegram'));
    $channel->send($body, self::buildChannelConfig($params));
  }

  /**
   * Subscriber-fanout delivery: look up all contacts opted into the given
   * subscription_type, send the same rendered body through *each contact's*
   * preferred channel and recipient ID. Per-recipient errors are caught so one
   * bad chat_id can't blank out everyone else's notification.
   *
   * @param array<string, mixed> $params
   */
  private static function deliverToSubscribers(string $body, array $params, ?SubscriberLookup $lookup): void {
    $subscriptionType = Cast::toString($params['subscription_type'] ?? '');
    if ($subscriptionType === '') {
      throw new \CRM_Core_Exception('herald: subscriber-mode requires action parameter "subscription_type".');
    }
    $registry = ChannelRegistry::default();
    $extras = self::filterSubscriberSafe(self::buildChannelConfig($params));
    $lookup ??= new SubscriberLookup();
    foreach ($lookup->findBySubscriptionType($subscriptionType) as $sub) {
      try {
        $registry->get($sub['channel'])->send($body, array_merge($extras, $sub['config']));
      }
      catch (\Throwable $e) {
        \Civi::log()->warning('herald: subscriber dispatch failed', [
          'contact_id' => $sub['contact_id'] ?? NULL,
          'channel' => $sub['channel'] ?? NULL,
          'error' => $e->getMessage(),
        ]);
      }
    }
  }

  /**
   * Build the per-call config dict passed to a channel.
   *
   * The full params are forwarded verbatim (channels read whichever keys they
   * care about — `chat_id` for Telegram, `webhook_url` for Webhook,
   * `ntfy_topic` for Ntfy, etc.) plus any per-call overrides decoded from the
   * `channel_config` JSON textarea on the form.
   *
   * @param array<string, mixed> $params
   * @return array<string, mixed>
   */
  public static function buildChannelConfig(array $params): array {
    $config = $params;
    if (!empty($params['channel_config'])) {
      $extra = is_array($params['channel_config'])
        ? $params['channel_config']
        : json_decode(Cast::toString($params['channel_config']), TRUE);
      if (is_array($extra)) {
        $config = array_merge($config, $extra);
      }
    }
    unset($config['channel_config'], $config['channel'], $config['template']);
    return Cast::namedOnly($config);
  }

  /**
   * Reduce a channel config to the keys allowed in subscriber-fanout sends.
   *
   * @param array<array-key, mixed> $config
   * @return array<string, mixed>
   */
  public static function filterSubscriberSafe(array $config): array {
    return array_intersect_key($config, array_flip(self::SUBSCRIBER_SAFE_KEYS));
  }

  /**
   * Default the email_subject param to the template's rendered msg_subject.
   * An explicit email_subject wins, as does one set in the channel_config JSON
   * (buildChannelConfig merges that on top later).
   *
   * @param array<string, mixed> $params
   * @return array<string, mixed>
   */
  public static function applyDefaultEmailSubject(array $params, ?string $subject): array {
    if ($subject !== NULL && $subject !== '' && empty($params['email_subject'])) {
      $params['email_subject'] = $subject;
    }
    return $params;
  }

}

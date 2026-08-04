<?php

declare(strict_types = 1);

use Civi\Herald\Buffer\AlertBuffer;
use Civi\Herald\Channel\ChannelRegistry;
use Civi\Herald\Renderer\TemplateRenderer;
use Civi\Herald\Subscribers\SubscriberLookup;

/**
 * Shared render-and-dispatch logic for herald CiviRules actions.
 *
 * Subclasses build the variable bag for the template; this base resolves the
 * channel and workflow template, renders, and sends — either directly to a
 * single recipient configured on the rule, or fanned out to all contacts
 * subscribed to a given subscription type (delivery_mode=subscribers).
 * @mago-expect lint:too-many-methods
 * @mago-expect lint:cyclomatic-complexity
 */
abstract class CRM_Herald_CivirulesActions_AbstractSend extends CRM_Civirules_Action {

  /**
   * Return the URL of the per-action parameter form. Subclasses point to their
   * concrete CRM_Herald_Form_CivirulesAction_* path.
   */
  public function getExtraDataInputUrl($ruleActionId) {
    return \CRM_Utils_System::url($this->getFormPath(), 'reset=1&action=update&id=' . $ruleActionId);
  }

  /**
   * Civi route of the parameter form. Override in each concrete subclass.
   */
  abstract protected function getFormPath(): string;

  /**
   * Action parameters as CiviRules hands them over.
   *
   * CiviRules stores them serialised in civirule_rule_action.action_params, so
   * everything that comes back out is untyped: the form only ever writes
   * scalars and nested arrays, but nothing enforces that. Hence the shape is
   * "string keys, mixed values" and every read goes through the asString() /
   * asInt() / asFloat() coercers below.
   *
   * @return array<string, mixed>
   */
  protected function getActionParameters() {
    $params = [];
    foreach (parent::getActionParameters() as $key => $value) {
      $params[(string) $key] = $value;
    }
    return $params;
  }

  /**
   * Render the chosen template with $vars and dispatch through the chosen channel.
   *
   * Action parameters (set in the CiviRules action editor):
   *   channel             — channel key (default "telegram"); used for direct delivery
   *   template            — MessageTemplate workflow_name to render (required)
   *   delivery_mode       — "direct" (default) or "subscribers"
   *   subscription_type   — required when delivery_mode=subscribers; one of the
   *                         herald_subscription_type option-group values
   *   aggregate_minutes   — int (default 0 = send immediately). When > 0, the
   *                         rendered body is parked in the alert buffer and
   *                         Job.herald_buffer_flush sends everything the rule
   *                         collected within the window as ONE message.
   *   chat_id, parse_mode, disable_notification, … — channel-specific keys
   *                         (read by the channel implementations)
   *
   * @param array<string, mixed> $vars Template variable bag built by the subclass.
   */
  protected function dispatch(array $vars): void {
    $params = $this->getActionParameters();
    // Group key for aggregation, taken from the RAW stored params: stable per
    // rule action. Must be computed before applyDefaultEmailSubject() mixes a
    // per-event rendered subject into $params.
    $groupHash = md5(json_encode($params) ?: '');
    $workflowName = self::asString($params['template'] ?? '');
    if ($workflowName === '') {
      throw new \CRM_Core_Exception('herald: action parameter "template" (workflow_name) is required.');
    }

    $renderer = new TemplateRenderer();
    $parts = $renderer->renderParts($workflowName, $vars);
    $body = $parts['body'];
    $params = self::applyDefaultEmailSubject($params, $parts['subject']);

    $aggregateMinutes = self::asInt($params['aggregate_minutes'] ?? 0);
    if ($aggregateMinutes > 0) {
      $this->makeAlertBuffer()->add($groupHash, $aggregateMinutes, $body, $params, self::aggregateAmount($vars));
      return;
    }

    if (($params['delivery_mode'] ?? 'direct') === 'subscribers') {
      $this->dispatchToSubscribers($body, $params);
      return;
    }
    $this->dispatchDirect($body, $params);
  }

  /**
   * Test-seam — overridable in tests to intercept buffering.
   */
  protected function makeAlertBuffer(): AlertBuffer {
    return new AlertBuffer();
  }

  /**
   * Monetary value of the event behind this alert, summed into the aggregate
   * header ("N Meldungen, gesamt X €"). Default: the contribution amount when
   * the variable bag carries one; NULL keeps the sum out of the header.
   *
   * @param array<string, mixed> $vars
   */
  protected static function aggregateAmount(array $vars): ?float {
    $contribution = $vars['contribution'] ?? NULL;
    if (is_array($contribution) && is_numeric($contribution['total_amount'] ?? NULL)) {
      return (float) $contribution['total_amount'];
    }
    return NULL;
  }

  /**
   * Static delivery entry point for non-CiviRules callers (the buffer-flush
   * job). Same direct/subscriber branch as dispatch(), minus rendering —
   * $body is already final.
   *
   * @param array<string, mixed> $params Stored action params.
   */
  public static function deliver(string $body, array $params, ?SubscriberLookup $lookup = NULL): void {
    if (($params['delivery_mode'] ?? 'direct') === 'subscribers') {
      $subscriptionType = self::asString($params['subscription_type'] ?? '');
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
      return;
    }
    $channel = ChannelRegistry::default()->get(self::asString($params['channel'] ?? 'telegram'));
    $channel->send($body, self::buildChannelConfig($params));
  }

  /**
   * Direct delivery: one rendered body, one channel call. The historical path.
   *
   * @param array<string, mixed> $params
   */
  protected function dispatchDirect(string $body, array $params): void {
    self::deliver($body, ['delivery_mode' => 'direct'] + $params);
  }

  /**
   * Config keys safe to forward from the rule into subscriber-fanout sends:
   * presentation-only options. Recipient and credential keys (chat_id,
   * webhook_url, webhook_auth_token, webhook_headers, ntfy_topic,
   * pushover_user_key, …) are deliberately absent — in subscriber mode the
   * recipient comes from the contact's own preferences, and rule-level
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

  /**
   * Subscriber-fanout delivery: look up all contacts opted into the given
   * subscription_type, send the same rendered body through *each contact's*
   * preferred channel and recipient ID. Per-recipient errors are caught so
   * one bad chat_id can't blank out everyone else's notification.
   *
   * @param array<string, mixed> $params
   */
  protected function dispatchToSubscribers(string $body, array $params): void {
    self::deliver($body, ['delivery_mode' => 'subscribers'] + $params, $this->makeSubscriberLookup());
  }

  /**
   * Test-seam — overridable in subclass tests to inject a fake lookup.
   */
  protected function makeSubscriberLookup(): SubscriberLookup {
    return new SubscriberLookup();
  }

  /**
   * Keep only entries that can be addressed by name.
   *
   * Both template variables and channel config are addressed by name, but the
   * values come from JSON blobs and CiviRules hands through whatever was
   * stored — a JSON array decodes to integer keys. A numeric key is not a
   * usable Smarty variable name (it could never be read as `{$0}`) and matches
   * no channel config field, so dropping it loses nothing and lets the type say
   * what the receivers actually get.
   *
   * @param array<array-key, mixed> $vars
   * @return array<string, mixed>
   */
  protected static function namedOnly(array $vars): array {
    $named = [];
    foreach ($vars as $key => $value) {
      if (!is_string($key)) {
        continue;
      }

      $named[$key] = $value;
    }

    return $named;
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
   *
   * @param array<string, mixed> $params
   * @return array<string, mixed>
   */
  public static function buildChannelConfig(array $params): array {
    $config = $params;
    if (!empty($params['channel_config'])) {
      $extra = is_array($params['channel_config'])
        ? $params['channel_config']
        : json_decode(self::asString($params['channel_config']), TRUE);
      if (is_array($extra)) {
        $config = array_merge($config, $extra);
      }
    }
    unset($config['channel_config'], $config['channel'], $config['template']);
    return self::namedOnly($config);
  }

  /**
   * Reduce a channel config to the keys allowed in subscriber-fanout sends.
   *
   * Test-seam: pure, no Civi dependencies.
   *
   * @param array<array-key, mixed> $config
   * @return array<string, mixed>
   */
  public static function filterSubscriberSafe(array $config): array {
    return array_intersect_key($config, array_flip(self::SUBSCRIBER_SAFE_KEYS));
  }

  /**
   * Default the email_subject param to the template's rendered msg_subject.
   * An explicit email_subject on the action wins, as does one set in the
   * channel_config JSON (buildChannelConfig merges that on top later).
   *
   * Test-seam: pure, no Civi dependencies.
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

  /**
   * Coerce an untyped value (action parameter, APIv4 row cell) to string.
   *
   * Non-scalars — arrays and objects — have no meaningful string form in any
   * of these slots and collapse to $default instead of producing "Array".
   */
  protected static function asString(mixed $value, string $default = ''): string {
    return is_scalar($value) ? (string) $value : $default;
  }

  /**
   * Coerce an untyped value to int. See asString() for the non-scalar rule.
   */
  protected static function asInt(mixed $value, int $default = 0): int {
    return is_scalar($value) ? (int) $value : $default;
  }

  /**
   * Coerce an untyped value to float. See asString() for the non-scalar rule.
   */
  protected static function asFloat(mixed $value, float $default = 0.0): float {
    return is_scalar($value) ? (float) $value : $default;
  }

}

<?php

declare(strict_types = 1);

namespace Civi\Herald\Dispatch;

use Civi\Herald\Buffer\AlertBuffer;
use Civi\Herald\Channel\ChannelRegistry;
use Civi\Herald\Renderer\TemplateRenderer;
use Civi\Herald\Subscribers\GroupRecipients;
use Civi\Herald\Subscribers\SubscriberLookup;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

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
 * Parameters (the flat "params" bag):
 *   channel             — channel key (default "telegram"); used for direct delivery
 *   template            — MessageTemplate workflow_name to render (required)
 *   delivery_mode       — "direct" (default), "subscribers", "group" or "addresses"
 *   subscription_type   — required when delivery_mode=subscribers; one of the
 *                         herald_subscription_type option-group values
 *   group_id            — required when delivery_mode=group; recipients are the
 *                         group's contacts (own preferred channel, email fallback)
 *   email_addresses     — required when delivery_mode=addresses; raw address
 *                         list (comma/semicolon/newline separated), no contacts
 *   respect_suspension  — bool; skip is_opt_out / on-hold email recipients in
 *                         contact fan-outs (do_not_email is always skipped)
 *   email_location_type — int location_type_id for contact email resolution;
 *                         0/absent = primary address
 *   scope_entity_type / scope_entity_id — source-entity scope of this
 *                         notification; scoped subscriptions must match it
 *   aggregate_minutes   — int (default 0 = send immediately). When > 0, the
 *                         rendered body is parked in the alert buffer and
 *                         Job.herald_buffer_flush sends everything collected
 *                         within the window as ONE message.
 *   chat_id, parse_mode, disable_notification, … — channel-specific keys
 *                         (read by the channel implementations)
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
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
    $workflowName = Cast::toString($params['template'] ?? '');
    if ($workflowName === '') {
      throw new \CRM_Core_Exception('herald: action parameter "template" (workflow_name) is required.');
    }
    $parts = ($this->renderer ?? new TemplateRenderer())->renderParts($workflowName, $vars);
    $mode = $this->dispatchRendered($params, $parts['body'], $parts['subject'], self::aggregateAmount($vars));
    return ['mode' => $mode, 'template' => $workflowName];
  }

  /**
   * Buffer or deliver a body the caller has already rendered.
   *
   * @param array<string, mixed> $params
   * @return 'buffered'|'sent'
   */
  public function dispatchRendered(array $params, string $body, ?string $subject, ?float $amount): string {
    // Must be computed before applyDefaultEmailSubject() mixes a per-event
    // rendered subject into $params — see groupHash().
    $groupHash = self::groupHash($params);
    $params = self::applyDefaultEmailSubject($params, $subject);
    $aggregateMinutes = Cast::toInt($params['aggregate_minutes'] ?? 0);
    if ($aggregateMinutes > 0) {
      ($this->buffer ?? new AlertBuffer())->add($groupHash, $aggregateMinutes, $body, $params, $amount);
      return 'buffered';
    }
    self::deliver($body, $params, $this->lookup);
    return 'sent';
  }

  /**
   * Aggregation key for a caller configuration: everything written with the
   * same key composes into one message. Callers must pass the RAW params — a
   * per-event rendered subject in there gives every message its own group and
   * aggregation never happens.
   *
   * @param array<string, mixed> $params
   * @param string $prefix Namespace for keys of a different kind (topics).
   */
  public static function groupHash(array $params, string $prefix = ''): string {
    try {
      return md5($prefix . json_encode($params, JSON_THROW_ON_ERROR));
    }
    catch (\JsonException $e) {
      // An empty replacement would collapse unrelated configurations onto one
      // hash and mix their alerts into a single message.
      throw new \CRM_Core_Exception(
        'herald: could not compute the aggregation group key: ' . $e->getMessage(),
        0,
        [],
        $e,
      );
    }
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
    $mode = $params['delivery_mode'] ?? 'direct';
    if ($mode === 'subscribers') {
      self::deliverToSubscribers($body, $params, $lookup);
      return;
    }
    if ($mode === 'group') {
      $groupId = Cast::toInt($params['group_id'] ?? 0);
      if ($groupId <= 0) {
        throw new NoRecipientsException('herald: group mode requires action parameter "group_id".');
      }
      self::deliverToRecipients(
        $body,
        $params,
        (new GroupRecipients())->findByGroup($groupId, self::deliveryOptions($params)),
      );
      return;
    }
    if ($mode === 'addresses') {
      self::deliverToAddresses($body, $params);
      return;
    }
    $channel = ChannelRegistry::default()->get(Cast::toString($params['channel'] ?? 'telegram'));
    $channel->send($body, self::buildChannelConfig($params));
  }

  /**
   * Fan-out delivery options derived from the caller's params.
   *
   * @param array<string, mixed> $params
   * @return array<string, mixed>
   */
  public static function deliveryOptions(array $params): array {
    $options = [
      'respect_suspension' => !empty($params['respect_suspension']),
      'location_type_id' => Cast::toInt($params['email_location_type'] ?? 0),
    ];
    $scopeType = Cast::toString($params['scope_entity_type'] ?? '');
    $scopeId = Cast::toInt($params['scope_entity_id'] ?? 0);
    if ($scopeType !== '' && $scopeId > 0) {
      $options['scope'] = ['entity_type' => $scopeType, 'entity_id' => $scopeId];
    }
    return $options;
  }

  /**
   * Send one rendered body to a resolved recipient list, one channel call per
   * recipient. Per-recipient errors are logged instead of aborting the loop —
   * but when EVERY send fails, the whole delivery throws, so a buffered flush
   * keeps its rows and retries (a fully down transport must not count as
   * sent). Partial failure counts as delivered: retrying would double-send to
   * the recipients that already got the message.
   *
   * An EMPTY list throws for the same reason: nobody was notified, so the
   * caller must not record a delivery — a fan-out that resolves to nobody is a
   * configuration fault (deactivated subscription type, emptied group), not a
   * successful send. It throws NoRecipientsException, which the buffer flush
   * treats as permanent and parks instead of retrying forever.
   *
   * @param array<string, mixed> $params
   * @param array<int, array{contact_id: int, channel: string, config: array<string, mixed>}> $recipients
   */
  public static function deliverToRecipients(
    string $body,
    array $params,
    array $recipients,
    ?ChannelRegistry $registry = NULL,
  ): void {
    if ($recipients === []) {
      throw new NoRecipientsException(sprintf(
        'herald: no recipients resolved for delivery_mode "%s" — nothing was sent.',
        Cast::toString($params['delivery_mode'] ?? 'direct'),
      ));
    }
    $registry ??= ChannelRegistry::default();
    $extras = self::filterSubscriberSafe(self::buildChannelConfig($params));
    $failed = 0;
    $lastError = NULL;
    foreach ($recipients as $recipient) {
      try {
        $registry->get($recipient['channel'])->send($body, array_merge($extras, $recipient['config']));
      }
      catch (\Throwable $e) {
        $failed++;
        $lastError = $e;
        \Civi::log()->warning('herald: recipient dispatch failed', [
          'contact_id' => $recipient['contact_id'],
          'channel' => $recipient['channel'],
          'error' => $e->getMessage(),
        ]);
      }
    }
    if ($failed > 0 && $failed === count($recipients)) {
      throw new \CRM_Core_Exception(
        sprintf('herald: all %d recipient sends failed (last error: %s)', $failed, $lastError?->getMessage() ?? ''),
      );
    }
  }

  /**
   * Ops-style delivery to a raw address list (no contact linkage): one email
   * per address through the email channel. Same failure semantics as
   * deliverToRecipients(): per-address errors are logged, but a total failure
   * throws so buffered flushes retry instead of counting the send as done.
   *
   * @param array<string, mixed> $params
   */
  public static function deliverToAddresses(string $body, array $params, ?ChannelRegistry $registry = NULL): void {
    $addresses = self::parseAddressList($params['email_addresses'] ?? '');
    if ($addresses === []) {
      throw new NoRecipientsException('herald: address-list mode requires action parameter "email_addresses".');
    }
    $channel = ($registry ?? ChannelRegistry::default())->get('email');
    $config = self::buildChannelConfig($params);
    unset($config['email_addresses']);
    $failed = 0;
    $lastError = NULL;
    foreach ($addresses as $address) {
      try {
        $channel->send($body, ['email_to' => $address] + $config);
      }
      catch (\Throwable $e) {
        $failed++;
        $lastError = $e;
        \Civi::log()->warning('herald: address dispatch failed', [
          'email_to' => $address,
          'error' => $e->getMessage(),
        ]);
      }
    }
    if ($failed > 0 && $failed === count($addresses)) {
      throw new \CRM_Core_Exception(
        sprintf('herald: all %d address sends failed (last error: %s)', $failed, $lastError?->getMessage() ?? ''),
      );
    }
  }

  /**
   * Split a comma/semicolon/newline-separated address list. Pure — test-seam.
   *
   * @return string[]
   */
  public static function parseAddressList(mixed $raw): array {
    if (is_array($raw)) {
      $raw = implode("\n", array_filter($raw, 'is_scalar'));
    }
    if (!is_scalar($raw)) {
      return [];
    }
    $parts = preg_split('/[,;\n\r]+/', (string) $raw) ?: [];
    $out = [];
    foreach ($parts as $part) {
      $part = trim($part);
      if ($part !== '' && !in_array($part, $out, TRUE)) {
        $out[] = $part;
      }
    }
    return $out;
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
      throw new NoRecipientsException('herald: subscriber-mode requires action parameter "subscription_type".');
    }
    $lookup ??= new SubscriberLookup();
    self::deliverToRecipients(
      $body,
      $params,
      $lookup->findBySubscriptionType($subscriptionType, self::deliveryOptions($params)),
    );
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

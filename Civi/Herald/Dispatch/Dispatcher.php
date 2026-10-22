<?php

declare(strict_types = 1);

namespace Civi\Herald\Dispatch;

use Civi\Herald\Buffer\AlertBuffer;
use Civi\Herald\Channel\ChannelRegistry;
use Civi\Herald\Renderer\TemplateRenderer;
use Civi\Herald\Subscribers\SubscriberLookup;
use Civi\Herald\Topic\RouteFields;
use Civi\Herald\Topic\Router;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Render-and-dispatch core of herald.
 *
 * Owns everything that happens once a variable bag exists: resolve the
 * workflow template, render it, then either park the result in the alert
 * buffer (aggregation window) or deliver it — directly to the recipient
 * configured on the caller's params, or fanned out to all contacts subscribed
 * to a topic.
 *
 * Callers: the Herald.notify* APIv4 actions (the CiviRules adapters go through
 * those). Job.herald_buffer_flush and Herald.publish go to Delivery::send()
 * directly.
 *
 * Parameters (the flat "params" bag):
 *   topic               — take the delivery from this topic's route, resolved
 *                         at send time; no other delivery keys beside it
 *   channel             — name of a configured channel; direct and address delivery
 *   template            — MessageTemplate workflow_name to render (required)
 *   delivery_mode       — "direct" (default), "subscribers", "group" or "addresses"
 *   subscribers_of      — the topic whose subscribers delivery_mode=subscribers
 *                         reaches; a topic's route defaults it to that topic
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
 *   chat_id, parse_mode, disable_notification, … — delivery keys of the
 *                         channel's type, over the channel's own
 */
class Dispatcher {

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
    $params = self::withTopic($params);
    // Must be computed before ChannelSubject::withSubject() mixes a per-event
    // rendered subject into $params — see groupHash().
    $groupHash = self::groupHash($params);
    $params = ChannelSubject::withSubject($params, $subject);
    $aggregateMinutes = Cast::toInt($params['aggregate_minutes'] ?? 0);
    if ($aggregateMinutes > 0) {
      ($this->buffer ?? new AlertBuffer())->add($groupHash, $aggregateMinutes, $body, $params, $amount);
      return 'buffered';
    }
    Delivery::send($body, $params, $this->lookup);
    return 'sent';
  }

  /**
   * A topic's route in place of the delivery keys; the rest of the params
   * (template, scope, vars) stays.
   *
   * @param array<string, mixed> $params
   * @return array<string, mixed>
   */
  public static function withTopic(array $params): array {
    $topic = Cast::toString($params['topic'] ?? '');
    if ($topic === '') {
      return $params;
    }
    $own = array_diff_key($params, RouteFields::all(ChannelRegistry::default()), ['topic' => TRUE]);
    return array_merge($own, (new Router())->resolve($topic));
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

}

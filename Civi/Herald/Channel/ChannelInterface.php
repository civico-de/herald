<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

interface ChannelInterface {

  /**
   * Send a rendered message body through this channel.
   *
   * @param string $renderedBody
   *   The fully-rendered message as HTML; text sinks send PlainText::fromHtml() of it.
   * @param array<string, mixed> $config
   *   The configured channel's keys with the delivery's keys on top (Channels::send()).
   */
  public function send(string $renderedBody, array $config): void;

  /**
   * Registry key, e.g. "telegram", "email".
   */
  public function getKey(): string;

  /**
   * The keys of a channel of this type. Scope `channel` (account, server,
   * sender) is set only on the channel, `delivery` (the default) also per
   * action and route; `recipient` keys come from a subscriber's own
   * preferences, `secret` ones are stored encrypted, and a `bound_to` key is
   * dropped when a delivery replaces the key it belongs to.
   *
   * @return array<string, array{label: string, required: bool, type: string, options?: array<int|string, string>, default?: string, help?: string, scope?: 'channel'|'delivery', secret?: bool, recipient?: bool, bound_to?: string}>
   */
  public function getConfigSchema(): array;

  /**
   * Map the semantic names Herald.send accepts ('subject', 'format' = text|html)
   * onto this channel's config keys. Omit a semantic the channel cannot express.
   *
   * @return array<string, string>
   *   Semantic name => key in getConfigSchema().
   */
  public function getSemanticFields(): array;

}

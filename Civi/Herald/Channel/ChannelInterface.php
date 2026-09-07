<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

interface ChannelInterface {

  /**
   * Send a rendered message body through this channel.
   *
   * @param string $renderedBody
   *   The fully-rendered message (HTML, Markdown, or plain — channel decides what it accepts).
   * @param array<string, mixed> $config
   *   Per-call configuration. Shape depends on the channel; see getConfigSchema().
   *   For Telegram: ['chat_id' => string, 'parse_mode' => 'HTML'|'MarkdownV2'|null].
   */
  public function send(string $renderedBody, array $config): void;

  /**
   * Registry key, e.g. "telegram", "email".
   */
  public function getKey(): string;

  /**
   * Per-call config fields this channel accepts; the CiviRules action UI renders them.
   *
   * @return array<string, array{label: string, required: bool, type: string, options?: array<int|string, string>, default?: string, help?: string}>
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

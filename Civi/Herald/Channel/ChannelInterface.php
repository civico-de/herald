<?php

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
   * Stable key used to look the channel up by name.
   * E.g. "telegram", "slack", "email".
   */
  public function getKey(): string;

  /**
   * Describe the per-call config fields this channel accepts.
   * Used by the CiviRules action UI to render input fields.
   *
   * Return shape: ['field_key' => ['label' => string, 'required' => bool, 'type' => 'string'|'int'|'enum', 'options' => ?array]]
   *
   * @return array<string, array{label: string, required: bool, type: string, options?: array<int|string, string>, default?: string, help?: string}>
   */
  public function getConfigSchema(): array;

}

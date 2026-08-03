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

  /**
   * Map channel-agnostic message semantics onto this channel's own config keys.
   *
   * A caller that only knows "this alert has a subject and its body is plain
   * text" must not have to know that the email channel spells that
   * `email_subject`/`email_format` while ntfy spells it `ntfy_title` and
   * telegram has no such concept at all. Herald.send takes the semantic names
   * and translates them here, so the per-channel config schema stays the one
   * and only place where those key names are written down.
   *
   * Recognised semantic names:
   *   subject — short headline/title accompanying the body (string)
   *   format  — how the body should be interpreted: 'text' or 'html'
   *
   * Omit a semantic the channel has no equivalent for; Herald.send then drops
   * that value rather than inventing a key.
   *
   * @return array<string, string>
   *   Semantic name => key in getConfigSchema().
   */
  public function getSemanticFields(): array;

}

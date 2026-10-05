<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Herald\Channel\ChannelRegistry;
use Civi\Herald\Channel\PlainText;

/**
 * Deliver an already-rendered message through one channel; the entry point
 * for other extensions (string-addressed, so the dependency stays optional).
 *
 * `subject`/`format` are mapped via getSemanticFields() and dropped by channels
 * without an equivalent. Unknown `config` keys are passed through and reported
 * as `unknown_config_keys`, so a typo is visible without changing delivery.
 * Channels take HTML bodies: a format=text message is escaped, so text sinks
 * get the text back and HTML sinks show it instead of parsing it.
 *
 * Example:
 *   cv api4 Herald.send 'channel=ntfy' 'message="disk almost full"' \
 *       'subject="[ops] storage"' 'config={"ntfy_priority":4}'
 */
class Send extends AbstractAction {

  /**
   * Channel key as registered in ChannelRegistry (see Herald.getChannels).
   * @var string
   */
  protected string $channel = '';

  /**
   * The fully-rendered message body: HTML, or plain text with format=text.
   * @var string
   */
  protected string $message = '';

  /**
   * Per-call channel config; keys per the channel's getConfigSchema().
   * Plain `@var array`: core parses this at runtime and rejects generics
   * with "Unknown parameter type".
   *
   * @var array
   * @phpstan-var array<string, mixed>
   */
  protected array $config = [];

  /**
   * Channel-agnostic headline; ignored by channels without one.
   * @var string
   */
  protected string $subject = '';

  /**
   * 'text' or 'html' (default): the format of `message`, and the MIME part or
   * body format of channels that offer a choice.
   * @var string
   */
  protected string $format = '';

  public function _run(Result $result): void {
    if ($this->channel === '') {
      throw new \CRM_Core_Exception('Herald.send: "channel" parameter is required.');
    }
    if ($this->message === '') {
      throw new \CRM_Core_Exception('Herald.send: "message" parameter is required.');
    }
    if ($this->format !== '' && !in_array($this->format, ['text', 'html'], TRUE)) {
      throw new \CRM_Core_Exception('Herald.send: "format" must be "text" or "html".');
    }

    // Throws for an unregistered key: a config error, not a delivery outcome.
    $channel = ChannelRegistry::default()->get($this->channel);

    $schema = $channel->getConfigSchema();
    $unknown = array_values(array_diff(array_keys($this->config), array_keys($schema)));

    $config = self::applySemantics($this->config, $channel->getSemanticFields(), $this->subject, $this->format);

    $channel->send($this->format === 'text' ? PlainText::toHtml($this->message) : $this->message, $config);

    $result[] = [
      'channel' => $this->channel,
      'sent' => TRUE,
      'message_length' => strlen($this->message),
      'unknown_config_keys' => $unknown,
    ];
  }

  /**
   * An explicit entry in $config wins over the semantic value.
   *
   * @param array<string, mixed> $config
   * @param array<string, string> $semanticFields
   * @return array<string, mixed>
   */
  public static function applySemantics(array $config, array $semanticFields, string $subject, string $format): array {
    $values = ['subject' => $subject, 'format' => $format];
    foreach ($values as $semantic => $value) {
      if ($value === '' || !isset($semanticFields[$semantic])) {
        continue;
      }
      $key = $semanticFields[$semantic];
      if (!isset($config[$key])) {
        $config[$key] = $value;
      }
    }
    return $config;
  }

}

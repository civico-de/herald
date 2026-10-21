<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Herald\Channel\ChannelRegistry;
use Civi\Herald\Channel\ChannelStore;
use Civi\Herald\Channel\PlainText;
use Civi\Herald\Dispatch\ChannelSender;
use Civi\Herald\Dispatch\ChannelSubject;

/**
 * Deliver an already-rendered message through one configured channel; the
 * entry point for other extensions (string-addressed, so the dependency stays
 * optional). `config` carries delivery keys over the channel's own; a
 * channel key (token, server, sender) there is an error.
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
   * Name of a configured channel (see Herald.getChannels).
   * @var string
   */
  protected string $channel = '';

  /**
   * The fully-rendered message body: HTML, or plain text with format=text.
   * @var string
   */
  protected string $message = '';

  /**
   * Delivery keys per the channel type's getConfigSchema().
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

    // Throws for an unknown channel: a config error, not a delivery outcome.
    $channel = ChannelRegistry::default()->get((new ChannelStore())->typeOf($this->channel));

    $schema = $channel->getConfigSchema();
    $unknown = array_values(array_diff(array_keys($this->config), array_keys($schema)));

    $config = ChannelSubject::withSemantics(
      $this->config,
      $channel->getSemanticFields(),
      $this->subject,
      $this->format,
    );

    (new ChannelSender())->send(
      $this->channel,
      $this->format === 'text' ? PlainText::toHtml($this->message) : $this->message,
      $config,
    );

    $result[] = [
      'channel' => $this->channel,
      'sent' => TRUE,
      'message_length' => strlen($this->message),
      'unknown_config_keys' => $unknown,
    ];
  }

}

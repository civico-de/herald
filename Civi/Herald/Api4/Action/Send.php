<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Herald\Buffer\AlertBuffer;
use Civi\Herald\Buffer\Window;
use Civi\Herald\Channel\ChannelRegistry;
use Civi\Herald\Channel\ChannelStore;
use Civi\Herald\Channel\PlainText;
use Civi\Herald\Dispatch\ChannelConfig;
use Civi\Herald\Dispatch\ChannelSender;
use Civi\Herald\Dispatch\ChannelSubject;
use Civi\Herald\Dispatch\Dispatcher;

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
 * The message collects in the alert buffer for `aggregateMinutes`, or the
 * site's default window; 0 sends at once and reports a failure to the caller.
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

  /**
   * Aggregation window of this message; 0 sends at once.
   * @var int|null
   */
  protected ?int $aggregateMinutes = NULL;

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

    // An account key from the caller fails here, also when the message is buffered.
    ChannelConfig::merged($channel, [], $config);
    $body = $this->format === 'text' ? PlainText::toHtml($this->message) : $this->message;
    $minutes = Window::of([Window::KEY => $this->aggregateMinutes]);
    match ($minutes) {
      0 => (new ChannelSender())->send($this->channel, $body, $config),
      default => $this->buffer($minutes, $body, $channel->getSemanticFields(), array_keys($schema)),
    };

    $result[] = [
      'channel' => $this->channel,
      'sent' => $minutes === 0,
      'buffered' => $minutes > 0,
      'message_length' => strlen($this->message),
      'unknown_config_keys' => $unknown,
    ];
  }

  /**
   * Messages with one subject, destination and window collect in one group;
   * unknown keys stay out, as the flush would read them as routing.
   *
   * @param array<string, string> $semantic
   * @param list<string> $keys
   */
  private function buffer(int $minutes, string $body, array $semantic, array $keys): void {
    $config = array_intersect_key($this->config, array_flip($keys));
    $delivery = ['channel' => $this->channel] + ChannelSubject::withSemantics($config, $semantic, '', $this->format);
    (new AlertBuffer())->add(
      Dispatcher::groupHash($delivery + ['subject' => $this->subject], 'send:' . $minutes . ':'),
      $minutes,
      $body,
      ChannelSubject::withSubject($delivery, $this->subject === '' ? NULL : $this->subject),
    );
  }

}

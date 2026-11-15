<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Herald\Buffer\AlertBuffer;
use Civi\Herald\Buffer\Window;
use Civi\Herald\Channel\PlainText;
use Civi\Herald\Dispatch\ChannelSubject;
use Civi\Herald\Dispatch\Delivery;
use Civi\Herald\Dispatch\Dispatcher;
use Civi\Herald\Renderer\TemplateRenderer;
use Civi\Herald\Topic\Router;

/**
 * Publish a message to a topic; the `herald_topics` setting maps topics to
 * channel, recipient and aggregation, so publishers carry no deployment specifics.
 *
 * Body: `message` (pre-rendered HTML, or plain text with format=text) or
 * `template` (workflow_name) plus `vars`.
 * The message collects in the alert buffer for the route's aggregation window,
 * or the site's default one; `aggregateMinutes` overrides both, 0 sends at once.
 *
 * Example:
 *   cv api4 Herald.publish 'topic=ops' 'message="🔴 disk almost full"'
 *
 * Accessors are served by AbstractAction::__call; declared here as part of the contract.
 *
 * @method $this setTopic(string $topic)
 * @method string getTopic()
 * @method $this setMessage(string $message)
 * @method string getMessage()
 * @method $this setFormat(string $format)
 * @method string getFormat()
 * @method $this setTemplate(string $template)
 * @method string getTemplate()
 * @method $this setVars(array<string, mixed> $vars)
 * @method array<string, mixed> getVars()
 * @method $this setAggregateMinutes(?int $aggregateMinutes)
 * @method int|null getAggregateMinutes()
 * @method $this setAmount(?float $amount)
 * @method float|null getAmount()
 */
class Publish extends AbstractAction {

  /**
   * Topic name resolved via the herald_topics setting.
   * @var string
   */
  protected string $topic = '';

  /**
   * Pre-rendered message body. Mutually exclusive with template.
   * @var string
   */
  protected string $message = '';

  /**
   * 'text' or 'html' (default): the format of `message`.
   * @var string
   */
  protected string $format = '';

  /**
   * MessageTemplate workflow_name to render with vars.
   * @var string
   */
  protected string $template = '';

  /**
   * Template variables; plain `@var array` for the same reason as Send::$config.
   *
   * @var array
   * @phpstan-var array<string, mixed>
   */
  protected array $vars = [];

  /**
   * Aggregation window of this message in place of the topic's; 0 sends at once.
   * @var int|null
   */
  protected ?int $aggregateMinutes = NULL;

  /**
   * Optional monetary value summed into aggregate headers.
   * @var float|null
   */
  protected ?float $amount = NULL;

  public function _run(Result $result): void {
    if ($this->topic === '') {
      throw new \CRM_Core_Exception('Herald.publish: "topic" parameter is required.');
    }
    if (($this->message === '') === ($this->template === '')) {
      throw new \CRM_Core_Exception('Herald.publish: pass exactly one of "message" or "template".');
    }
    if ($this->format !== '' && !in_array($this->format, ['text', 'html'], TRUE)) {
      throw new \CRM_Core_Exception('Herald.publish: "format" must be "text" or "html".');
    }
    if ($this->format !== '' && $this->template !== '') {
      throw new \CRM_Core_Exception('Herald.publish: "format" applies to "message" only; a template body is HTML.');
    }

    $params = (new Router())->resolve($this->topic);
    // Before a rendered subject enters $params, or every publish forms its own group.
    $aggregateMinutes = Window::of([Window::KEY => $this->aggregateMinutes ?? $params[Window::KEY] ?? NULL]);
    // A window named at the call forms its own group, so it is not stretched by a longer one.
    $groupHash = Dispatcher::groupHash($params, 'topic:' . $this->topic . ':' . $aggregateMinutes . ':');

    $body = $this->format === 'text' ? PlainText::toHtml($this->message) : $this->message;
    if ($this->template !== '') {
      $parts = (new TemplateRenderer())->renderParts($this->template, $this->vars);
      $body = $parts['body'];
      $params = ChannelSubject::withSubject($params, $parts['subject']);
    }

    if ($aggregateMinutes > 0) {
      (new AlertBuffer())->add(
        $groupHash,
        $aggregateMinutes,
        $body,
        $params,
        $this->amount,
      );
      $result[] = ['topic' => $this->topic, 'buffered' => TRUE];
      return;
    }

    Delivery::send($body, $params);
    // A fan-out reaches each recipient on their own channel.
    $fanOut = in_array($params['delivery_mode'] ?? 'direct', ['subscribers', 'group'], TRUE);
    $result[] = ['topic' => $this->topic, 'buffered' => FALSE, 'channel' => $fanOut ? NULL : $params['channel']];
  }

}

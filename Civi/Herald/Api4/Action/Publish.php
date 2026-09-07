<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Herald\Buffer\AlertBuffer;
use Civi\Herald\Dispatch\Dispatcher;
use Civi\Herald\Renderer\TemplateRenderer;
use Civi\Herald\Topic\Router;

/**
 * Publish a message to a topic; the `herald_topics` setting maps topics to
 * channel, recipient and aggregation, so publishers carry no deployment specifics.
 *
 * Body: `message` (pre-rendered) or `template` (workflow_name) plus `vars`.
 * A route with aggregate_minutes > 0 goes through the alert buffer.
 *
 * Example:
 *   cv api4 Herald.publish +v 'topic=ops' +v 'message="🔴 disk almost full"'
 *
 * Accessors are served by AbstractAction::__call; declared here as part of the contract.
 *
 * @method $this setTopic(string $topic)
 * @method string getTopic()
 * @method $this setMessage(string $message)
 * @method string getMessage()
 * @method $this setTemplate(string $template)
 * @method string getTemplate()
 * @method $this setVars(array<string, mixed> $vars)
 * @method array<string, mixed> getVars()
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

    $params = (new Router())->resolve($this->topic);
    // Before a rendered subject enters $params, or every publish forms its own group.
    $groupHash = Dispatcher::groupHash($params, 'topic:' . $this->topic . ':');

    $body = $this->message;
    if ($this->template !== '') {
      $parts = (new TemplateRenderer())->renderParts($this->template, $this->vars);
      $body = $parts['body'];
      $params = Dispatcher::applyDefaultEmailSubject($params, $parts['subject']);
    }

    $rawMinutes = $params['aggregate_minutes'] ?? 0;
    $aggregateMinutes = is_scalar($rawMinutes) ? (int) $rawMinutes : 0;
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

    Dispatcher::deliver($body, $params);
    $result[] = ['topic' => $this->topic, 'buffered' => FALSE, 'channel' => $params['channel']];
  }

}

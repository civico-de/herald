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
 * Publish a message to a TOPIC — the recipient-agnostic entry point for
 * other extensions and scripts.
 *
 * Where Herald.send addresses a channel directly (caller supplies chat_id
 * etc.), Herald.publish only names a topic; the `herald_topics` setting maps
 * topics to channel + recipient + aggregation. Integrations stay free of
 * deployment specifics, and admins can reroute or batch a whole topic
 * without touching the publisher.
 *
 * Body: either `message` (pre-rendered) or `template` (MessageTemplate
 * workflow_name) plus `vars`. When the topic's route sets
 * aggregate_minutes > 0, the message goes through the same aggregation
 * buffer as the CiviRules alerts.
 *
 * Example:
 *   cv api4 Herald.publish +v 'topic=ops' +v 'message="🔴 disk almost full"'
 *
 * The setters/getters below are served by AbstractAction::__call (APIv4's magic
 * accessors for the protected parameter properties); they are declared so the
 * fluent PHP API is part of the contract instead of an undocumented side effect.
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
   * Template variables (see Send::$config for why the annotation is plain
   * `array`).
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
        md5('topic:' . $this->topic . ':' . json_encode($params)),
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

<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Herald\Admin\InvalidInput;
use Civi\Herald\Topic\Router;
use Civi\Herald\Topic\RouteValidator;
use CRM_Herald_ExtensionUtil as E;

/**
 * Validate one topic route and store it in herald_topics; a rename keeps the
 * topic's position. Setting.set stays the unchecked bulk path.
 *
 * Example:
 *   cv api4 Herald.saveTopic topic=ops 'route={"channel":"email","email_to":"ops@example.org"}'
 *
 * @method $this setTopic(string $topic)
 * @method $this setRoute(array<string, mixed> $route)
 * @method $this setPreviousName(string $previousName)
 */
class SaveTopic extends AbstractAction {

  /**
   * Topic name; "default" backs every topic.
   * @var string
   * @required
   */
  protected string $topic = '';

  /**
   * Delivery params, merged over the "default" entry when the topic is published.
   * @var array
   * @phpstan-var array<array-key, mixed>
   */
  protected array $route = [];

  /**
   * The topic's current name when renaming it. Empty = add or replace `topic`.
   * @var string
   */
  protected string $previousName = '';

  public function _run(Result $result): void {
    $map = \Civi::settings()->get(Router::SETTING);
    $map = is_array($map) ? $map : [];

    $errors = (new RouteValidator())->errors($this->topic, $this->route);
    $renaming = $this->previousName !== '' && $this->previousName !== $this->topic;
    if ($this->previousName !== '' && !array_key_exists($this->previousName, $map)) {
      $errors[] = E::ts('The topic "%1" no longer exists. Reload the page.', [1 => $this->previousName]);
    }
    if (($renaming || $this->previousName === '') && array_key_exists($this->topic, $map)) {
      $errors[] = E::ts('A topic named "%1" already exists.', [1 => $this->topic]);
    }
    InvalidInput::throwIfAny($errors);

    $stored = [];
    foreach ($map as $name => $route) {
      $stored[(string) $name === $this->previousName ? $this->topic : $name] = $route;
    }
    $stored[$this->topic] = $this->route;
    \Civi::settings()->set(Router::SETTING, $stored);

    $result[] = ['topic' => $this->topic, 'route' => $this->route];
  }

}

<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Herald\Admin\InvalidInput;
use Civi\Herald\Admin\ParamsInUse;
use Civi\Herald\Topic\DefaultRules;
use Civi\Herald\Topic\Router;
use Civi\Herald\Topic\RouteValidator;
use Civi\Herald\Topic\TopicMeta;
use Civi\Herald\Topic\TopicRules;
use Civi\Herald\Topic\WithdrawRules;
use CRM_Herald_ExtensionUtil as E;

/**
 * Validate one topic and its route and store them in herald_topics; a rename
 * keeps the topic's position. Setting.set stays the unchecked bulk path.
 *
 * Example:
 *   cv api4 Herald.saveTopic topic=ops label=Operations subscribable=1 'route={"delivery_mode":"subscribers"}'
 *
 * @method $this setTopic(string $topic)
 * @method $this setRoute(array<string, mixed> $route)
 * @method $this setLabel(string $label)
 * @method $this setSubscribable(bool $subscribable)
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
   * Name of the topic in the subscriptions of contacts and the admin screens.
   * @var string
   */
  protected string $label = '';

  /**
   * Whether contacts may subscribe to the topic.
   * @var bool
   */
  protected bool $subscribable = FALSE;

  /**
   * The topic's current name when renaming it. Empty = add or replace `topic`.
   * @var string
   */
  protected string $previousName = '';

  public function _run(Result $result): void {
    $map = \Civi::settings()->get(Router::SETTING);
    $map = is_array($map) ? $map : [];

    $own = TopicRules::renamed($this->route, $this->previousName, $this->topic);
    $meta = ['label' => trim($this->label), 'subscribable' => $this->subscribable];
    $current = $this->previousName === '' ? $this->topic : $this->previousName;
    $default = TopicMeta::route(is_array($map['default'] ?? NULL) ? $map['default'] : []);
    $errors = array_merge(
      (new RouteValidator())->errors($this->topic, $own),
      TopicRules::errors($this->topic, $own, $meta, $default),
      DefaultRules::errors($this->topic, $map, $own),
      WithdrawRules::errors($this->topic, $map[$current] ?? NULL, $meta, $own, $default),
      $this->nameErrors($map),
    );
    InvalidInput::throwIfAny($errors);

    $stored = [];
    foreach ($map as $name => $route) {
      $stored[(string) $name === $this->previousName ? $this->topic : $name] = $route;
    }
    $stored[$this->topic] = $own + array_filter($meta);
    \Civi::settings()->set(Router::SETTING, $stored);

    $result[] = ['topic' => $this->topic, 'route' => $own] + $meta;
  }

  /**
   * @param array<array-key, mixed> $map
   * @return list<string>
   */
  private function nameErrors(array $map): array {
    $errors = [];
    $renaming = $this->previousName !== '' && $this->previousName !== $this->topic;
    if ($this->previousName !== '' && !array_key_exists($this->previousName, $map)) {
      $errors[] = E::ts('The topic "%1" no longer exists. Reload the page.', [1 => $this->previousName]);
    }
    if (($renaming || $this->previousName === '') && array_key_exists($this->topic, $map)) {
      $errors[] = E::ts('A topic named "%1" already exists.', [1 => $this->topic]);
    }
    if (!$renaming) {
      return $errors;
    }
    $errors = array_merge($errors, TopicRules::renameErrors($this->previousName));
    $users = ParamsInUse::ofTopic($this->previousName);
    return (
      $users === []
        ? $errors
        : [...$errors, E::ts('The topic "%1" cannot be renamed while in use:', [1 => $this->previousName]), ...$users]
    );
  }

}

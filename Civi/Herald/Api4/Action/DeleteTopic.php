<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Herald\Admin\InvalidInput;
use Civi\Herald\Admin\ParamsInUse;
use Civi\Herald\Topic\Router;
use CRM_Herald_ExtensionUtil as E;

/**
 * Remove one topic route from herald_topics; refused while a rule, job or
 * the monitor names the topic.
 *
 * Example:
 *   cv api4 Herald.deleteTopic topic=ops
 *
 * @method $this setTopic(string $topic)
 */
class DeleteTopic extends AbstractAction {

  /**
   * @var string
   * @required
   */
  protected string $topic = '';

  public function _run(Result $result): void {
    $map = \Civi::settings()->get(Router::SETTING);
    $map = is_array($map) ? $map : [];
    if (!array_key_exists($this->topic, $map)) {
      throw new InvalidInput([E::ts('The topic "%1" does not exist.', [1 => $this->topic])]);
    }
    $users = ParamsInUse::ofTopic($this->topic);
    if ($users !== []) {
      throw new InvalidInput(array_merge([E::ts('The topic "%1" is still in use:', [1 => $this->topic])], $users));
    }
    unset($map[$this->topic]);
    \Civi::settings()->set(Router::SETTING, $map);
    $result[] = ['topic' => $this->topic];
  }

}

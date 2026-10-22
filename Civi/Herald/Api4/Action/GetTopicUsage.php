<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Herald\Admin\ParamsInUse;
use Civi\Herald\Subscribers\SubscribableTopics;
use Civi\Herald\Topic\Router;

/**
 * Who names each topic (rule actions, scheduled jobs, routes, the monitor)
 * and how many contacts subscribe to it.
 *
 * Example:
 *   cv api4 Herald.getTopicUsage
 */
class GetTopicUsage extends AbstractAction {

  public function _run(Result $result): void {
    $map = \Civi::settings()->get(Router::SETTING);
    foreach (array_keys(is_array($map) ? $map : []) as $topic) {
      $result[] = [
        'topic' => (string) $topic,
        'used_by' => ParamsInUse::ofTopic((string) $topic),
        'subscribers' => SubscribableTopics::subscribers((string) $topic),
      ];
    }
  }

}

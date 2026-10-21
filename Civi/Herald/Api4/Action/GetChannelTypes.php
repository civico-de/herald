<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Herald\Channel\ChannelRegistry;
use Civi\Herald\Subscribers\ContactRows;

/**
 * List the channel types with the config keys each one accepts.
 *
 * Example:
 *   cv api4 Herald.getChannelTypes
 */
class GetChannelTypes extends AbstractAction {

  /**
   * Restrict the output to this type. Empty = all registered types.
   * @var string
   */
  protected string $type = '';

  public function _run(Result $result): void {
    $registry = ChannelRegistry::default();
    $keys = $this->type !== '' ? [$this->type] : $registry->listKeys();

    $rows = [];
    foreach ($keys as $key) {
      // Throws for an unknown key: an empty result would read as "no config".
      $channel = $registry->get($key);
      $rows[] = [
        'key' => $channel->getKey(),
        'label' => ChannelRegistry::label($channel->getKey()),
        'config_schema' => $channel->getConfigSchema(),
        'semantic_fields' => $channel->getSemanticFields(),
        'subscribable' => ContactRows::reachable($channel->getKey()),
      ];
    }
    $result->exchangeArray($rows);
  }

}

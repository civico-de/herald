<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Herald\Channel\ChannelRegistry;

/**
 * List the registered channels with the config keys each one accepts.
 *
 * Example:
 *   cv api4 Herald.getChannels
 */
class GetChannels extends AbstractAction {

  /**
   * Restrict the output to this channel key. Empty = all registered channels.
   * @var string
   */
  protected string $channel = '';

  public function _run(Result $result): void {
    $registry = ChannelRegistry::default();
    $keys = $this->channel !== '' ? [$this->channel] : $registry->listKeys();

    $rows = [];
    foreach ($keys as $key) {
      // Throws for an unknown key: an empty result would read as "no config".
      $channel = $registry->get($key);
      $rows[] = [
        'key' => $channel->getKey(),
        'label' => ChannelRegistry::label($channel->getKey()),
        'config_schema' => $channel->getConfigSchema(),
        'semantic_fields' => $channel->getSemanticFields(),
      ];
    }
    $result->exchangeArray($rows);
  }

}

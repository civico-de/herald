<?php

namespace Civi\Herald\Api4\Action;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Herald\Channel\ChannelRegistry;

/**
 * List the registered channels together with the config contract each one
 * accepts.
 *
 * The counterpart to Herald.send: a caller (or an admin filling in a
 * channel_config JSON field) can discover which keys a channel understands
 * instead of copying key names out of herald's source into its own code.
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
      // Throws for an unknown explicit key — silently returning nothing would
      // read as "the channel exists but has no config".
      $channel = $registry->get($key);
      $rows[] = [
        'key' => $channel->getKey(),
        'config_schema' => $channel->getConfigSchema(),
        'semantic_fields' => $channel->getSemanticFields(),
      ];
    }
    $result->exchangeArray($rows);
  }

}

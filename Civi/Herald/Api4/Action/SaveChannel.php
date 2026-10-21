<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Herald\Admin\InvalidInput;
use Civi\Herald\Channel\ChannelStore;
use Civi\Herald\Channel\PreferredChannelOptions;

/**
 * Validate one channel and store it in herald_channels, its secrets
 * encrypted. The name cannot change; the type of a stored channel neither.
 *
 * Example:
 *   cv api4 Herald.saveChannel name=ops_bot isNew=1 \
 *       'channel={"type":"telegram","label":"Ops bot","config":{"bot_token":"…","chat_id":"-100…"}}'
 *
 * @method $this setName(string $name)
 * @method $this setChannel(array<string, mixed> $channel)
 * @method $this setIsNew(bool $isNew)
 */
class SaveChannel extends AbstractAction {

  /**
   * @var string
   * @required
   */
  protected string $name = '';

  /**
   * type, label, active, subscribable and config. A secret left out or empty
   * keeps its stored value, NULL clears it.
   * @var array
   * @phpstan-var array<array-key, mixed>
   */
  protected array $channel = [];

  /**
   * TRUE adds a channel, FALSE replaces the stored one.
   * @var bool
   */
  protected bool $isNew = FALSE;

  public function _run(Result $result): void {
    $store = new ChannelStore();
    InvalidInput::throwIfAny(
      $this->isNew
        ? $store->create($this->name, $this->channel)
        : $store->replace($this->name, $this->channel),
    );
    PreferredChannelOptions::sync();
    foreach ($store->masked() as $row) {
      if ($row['name'] !== $this->name) {
        continue;
      }
      $result[] = $row;
    }
  }

}

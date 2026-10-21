<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Herald\Admin\InvalidInput;
use Civi\Herald\Admin\ParamsInUse;
use Civi\Herald\Channel\ChannelStore;
use Civi\Herald\Channel\PreferredChannelOptions;
use CRM_Herald_ExtensionUtil as E;

/**
 * Remove one channel; refused while a topic, rule, job or setting names it.
 *
 * Example:
 *   cv api4 Herald.deleteChannel name=ops_bot
 *
 * @method $this setName(string $name)
 */
class DeleteChannel extends AbstractAction {

  /**
   * @var string
   * @required
   */
  protected string $name = '';

  public function _run(Result $result): void {
    $store = new ChannelStore();
    if (!array_key_exists($this->name, $store->all())) {
      throw new InvalidInput([E::ts('The channel "%1" does not exist.', [1 => $this->name])]);
    }
    $users = ParamsInUse::ofChannel($this->name);
    if ($users !== []) {
      throw new InvalidInput(array_merge(
        [E::ts('The channel "%1" is still in use:', [1 => $this->name])],
        $users,
      ));
    }
    $store->delete($this->name);
    PreferredChannelOptions::sync();
    $result[] = ['name' => $this->name];
  }

}

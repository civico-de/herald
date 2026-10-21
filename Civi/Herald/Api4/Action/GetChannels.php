<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Herald\Admin\ParamsInUse;
use Civi\Herald\Channel\ChannelStore;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * List the configured channels. Secret keys show only as {"set": true}.
 *
 * Example:
 *   cv api4 Herald.getChannels
 */
class GetChannels extends AbstractAction {

  /**
   * Also list who names each channel (`used_by`).
   * @var bool
   */
  protected bool $withUsage = FALSE;

  public function _run(Result $result): void {
    $rows = (new ChannelStore())->masked();
    if ($this->withUsage) {
      foreach ($rows as &$row) {
        $row['used_by'] = ParamsInUse::ofChannel(Cast::toString($row['name']));
      }
    }
    $result->exchangeArray($rows);
  }

}

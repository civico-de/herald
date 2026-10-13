<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Herald\Admin\AllowedHosts;

/**
 * Validate and store the webhook host allowlist.
 *
 * Example:
 *   cv api4 Herald.saveAllowedHosts 'hosts=["hooks.slack.com","discord.com"]'
 *
 * @method $this setHosts(list<string> $hosts)
 */
class SaveAllowedHosts extends AbstractAction {

  /**
   * Host names or IP addresses; subdomains of an entry match too. Empty = denylist only.
   * @var array
   * @phpstan-var array<array-key, mixed>
   */
  protected array $hosts = [];

  public function _run(Result $result): void {
    $hosts = AllowedHosts::clean($this->hosts);
    \Civi::settings()->set(AllowedHosts::SETTING, $hosts);
    $result->exchangeArray(array_map(static fn (string $host) => ['host' => $host], $hosts));
  }

}

<?php

declare(strict_types = 1);

use Civi\Herald\Channel\ChannelStore;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * One domain of the site and the webhook URL and token its old settings sent with.
 */
final class CRM_Herald_Upgrader_Domain {

  public function __construct(
    public readonly int $id,
    public readonly string $defaultUrl,
    #[\SensitiveParameter]
    public readonly string $defaultToken = '',
  ) {}

  public static function current(string $defaultUrl = '', #[\SensitiveParameter] string $defaultToken = ''): self {
    return new self(CRM_Core_Config::domainID(), $defaultUrl, $defaultToken);
  }

  /**
   * @param array<string, mixed> $values The domain's old settings
   */
  public static function of(int $id, array $values): self {
    return new self(
      $id,
      trim(Cast::toString($values['herald_webhook_default_url'] ?? '')),
      trim(Cast::toString($values['herald_webhook_default_auth_token'] ?? '')),
    );
  }

  /**
   * @return list<int>
   */
  public static function ids(): array {
    /** @var list<array{id: mixed}> $rows */
    $rows = CRM_Utils_SQL_Select::from('civicrm_domain')->select(['id'])->execute()->fetchAll();
    return array_map(static fn (array $row): int => Cast::toInt($row['id']), $rows);
  }

  public function settings(): \Civi\Core\SettingsBag {
    return Civi::settings($this->id);
  }

  public function store(): ChannelStore {
    return new ChannelStore(NULL, $this->settings());
  }

}

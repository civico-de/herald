<?php

declare(strict_types = 1);

use Civi\Herald\Channel\ChannelStore;
use Civi\Herald\Channel\PreferredChannelOptions;
use Civi\Herald\Topic\Router;
use Civi\Test\HeadlessInterface;
use PHPUnit\Framework\TestCase;

/**
 * The preference field moves from its inline option group to the one the
 * channels fill. Not transactional: a custom field update may alter its table.
 *
 * @group headless
 */
class CRM_Herald_Upgrader_PreferredChannelFieldTest extends TestCase implements HeadlessInterface {

  /**
   * @var array<string, mixed>
   */
  private array $saved = [];

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  protected function setUp(): void {
    parent::setUp();
    \Civi\Herald\LegacyPreferencesFixture::install();
    foreach ([ChannelStore::SETTING, Router::SETTING] as $name) {
      $this->saved[$name] = \Civi::settings()->get($name);
    }
  }

  protected function tearDown(): void {
    foreach ($this->saved as $name => $value) {
      \Civi::settings()->set($name, $value);
    }
    PreferredChannelOptions::sync();
    \Civi\Herald\LegacyPreferencesFixture::remove();
    parent::tearDown();
  }

  private static function fieldGroup(): ?string {
    return (
      \Civi\Api4\CustomField::get(checkPermissions: FALSE)
        ->addSelect('option_group_id:name')
        ->addWhere('custom_group_id:name', '=', 'herald_preferences')
        ->addWhere('name', '=', 'preferred_channel')
        ->execute()
        ->first()['option_group_id:name'] ?? NULL
    );
  }

  public function testTheFieldUsesTheChannelOptionsAndTheInlineGroupGoes(): void {
    $inline = \Civi\Api4\OptionGroup::create(checkPermissions: FALSE)
      ->addValue('name', 'herald_test_inline_channels')
      ->addValue('title', 'herald_test_inline_channels')
      ->execute()
      ->first()['id'];
    \Civi\Api4\CustomField::update(checkPermissions: FALSE)
      ->addWhere('custom_group_id:name', '=', 'herald_preferences')
      ->addWhere('name', '=', 'preferred_channel')
      ->addValue('option_group_id', $inline)
      ->execute();
    $this->assertSame('herald_test_inline_channels', self::fieldGroup());

    CRM_Herald_Upgrader_NamedChannels::migrate();

    $this->assertSame(PreferredChannelOptions::OPTION_GROUP, self::fieldGroup());
    $this->assertSame(
      0,
      \Civi\Api4\OptionGroup::get(checkPermissions: FALSE)->addWhere('id', '=', $inline)->execute()->count(),
    );
  }

}

<?php

declare(strict_types = 1);

namespace Civi\Herald\Admin;

use Civi\Api4\Herald;

/**
 * Herald.saveAllowedHosts.
 *
 * @group headless
 */
class AllowedHostsTest extends AdminTestCase {

  public function testHostsAreStoredTrimmed(): void {
    $rows = self::call('saveAllowedHosts', ['hosts' => [
      ' hooks.slack.com ',
      '',
      'discord.com',
      '192.0.2.10',
      '2001:db8::1',
    ]]);
    $stored = ['hooks.slack.com', 'discord.com', '192.0.2.10', '2001:db8::1'];
    $this->assertSame($stored, array_column($rows, 'host'));
    $this->assertSame($stored, \Civi::settings()->get(AllowedHosts::SETTING));

    self::call('saveAllowedHosts', ['hosts' => \Civi::settings()->get(AllowedHosts::SETTING)]);
    $this->assertSame($stored, \Civi::settings()->get(AllowedHosts::SETTING));
  }

  public function testInvalidHostsAreRejected(): void {
    \Civi::settings()->set(AllowedHosts::SETTING, ['discord.com']);
    $this->assertRejected(
      'saveAllowedHosts',
      [
        'hosts' => ['https://hooks.slack.com', 'example.org/hook', 'example.org:8443', 'not a host', 7],
      ],
      [
        'without "https://"',
        'without a path',
        'without a port',
        '"not a host" is not a valid host name.',
        'Each host must be text.',
      ],
    );
    $this->assertSame(['discord.com'], \Civi::settings()->get(AllowedHosts::SETTING));
  }

  public function testOnlyAdministratorsMayWrite(): void {
    $this->assertAdminOnly(static fn () => Herald::saveAllowedHosts()->execute());
  }

}

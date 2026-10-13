<?php

declare(strict_types = 1);

namespace Civi\Herald\Admin;

use Civi\Api4\Herald;

/**
 * Herald.saveSourceRules and Herald.saveAllowedHosts.
 *
 * @group headless
 */
class SourceRulesAndHostsTest extends AdminTestCase {

  private const RULES = [
    ['match' => 'Bank TX', 'type' => 'contains', 'label' => 'Transfer'],
    ['match' => 'Online', 'type' => 'prefix', 'label' => 'Web'],
    ['match' => '/^SEPA\s/i', 'type' => 'regex', 'label' => 'Direct debit'],
  ];

  public function testRulesAreStoredInTheSettingShape(): void {
    $this->assertSame(self::RULES, self::call('saveSourceRules', ['rules' => self::RULES]));
    $this->assertSame(self::RULES, \Civi::settings()->get(SourceRules::SETTING));

    self::call('saveSourceRules', ['rules' => \Civi::settings()->get(SourceRules::SETTING)]);
    $this->assertSame(self::RULES, \Civi::settings()->get(SourceRules::SETTING));

    self::call('saveSourceRules', ['rules' => []]);
    $this->assertSame([], \Civi::settings()->get(SourceRules::SETTING));
  }

  public function testInvalidRulesAreRejected(): void {
    \Civi::settings()->set(SourceRules::SETTING, self::RULES);
    $this->assertRejected(
      'saveSourceRules',
      [
        'rules' => [
          ['match' => '/(unclosed/', 'type' => 'regex', 'label' => 'x'],
          ['match' => '', 'type' => 'suffix', 'label' => ' '],
          'not a rule',
        ],
      ],
      [
        'Rule 1: the regular expression is invalid: ',
        'Rule 2: enter the text to match.',
        'Rule 2: choose how to match',
        'Rule 2: enter the name to show.',
        'Rule 3: enter the text to match.',
        'Rule 3: choose how to match',
        'Rule 3: enter the name to show.',
      ],
    );
    $this->assertSame(self::RULES, \Civi::settings()->get(SourceRules::SETTING));
  }

  public function testTheRegexErrorNamesTheProblem(): void {
    $this->assertStringContainsString('missing closing parenthesis', (string) RegexCheck::error('/(unclosed/'));
    $this->assertStringContainsString('Delimiter', (string) RegexCheck::error('no delimiters'));
    $this->assertNull(RegexCheck::error('/ok/'));
  }

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
    $this->assertAdminOnly(static fn () => Herald::saveSourceRules()->execute());
    $this->assertAdminOnly(static fn () => Herald::saveAllowedHosts()->execute());
  }

}

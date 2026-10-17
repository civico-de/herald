<?php

declare(strict_types = 1);

use Civi\Herald\Cleaning\CleanedFields;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Upgrade 1013 moves the contribution-source rules into a cleaned field.
 *
 * @group headless
 */
class CRM_Herald_Upgrader_CleanedSourceTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  protected function tearDown(): void {
    Civi::settings()->set(CleanedFields::SETTING, []);
    parent::tearDown();
  }

  public function testTheRulesMoveAndTheOldSettingGoes(): void {
    Civi::settings()->set(CleanedFields::SETTING, []);
    CRM_Core_DAO::executeQuery('INSERT INTO civicrm_setting (name, value, domain_id, is_domain) VALUES (%1, %2, %3, 1)', [
      1 => [CRM_Herald_Upgrader_CleanedSource::OLD_SETTING, 'String'],
      2 => [
        serialize([
          ['match' => 'Bank TX', 'type' => 'contains', 'label' => 'Transfer'],
          ['match' => '#^sepa\s#i', 'type' => 'regex', 'label' => 'Direct debit'],
        ]),
        'String',
      ],
      3 => [CRM_Core_Config::domainID(), 'Integer'],
    ]);

    $this->assertSame(1, CRM_Herald_Upgrader_CleanedSource::move());
    Civi::service('settings_manager')->flush();

    $this->assertSame(
      [[
        'entity' => 'Contribution',
        'field' => 'source',
        'rules' => [
          ['match' => 'Bank TX', 'type' => 'contains', 'label' => 'Transfer'],
          ['match' => '^sepa\s', 'type' => 'regex', 'label' => 'Direct debit'],
        ],
        'fallback' => '',
      ]],
      Civi::settings()->get(CleanedFields::SETTING),
    );
    $this->assertSame(
      0,
      (int) CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_setting WHERE name = %1', [
        1 => [CRM_Herald_Upgrader_CleanedSource::OLD_SETTING, 'String'],
      ]),
    );
    $this->assertSame(0, CRM_Herald_Upgrader_CleanedSource::move());
  }

  /**
   * @return array<string, array{string, string}>
   */
  public static function patterns(): array {
    return [
      'slashes' => ['/^SEPA\s/', '^SEPA\s'],
      'case and unicode flags go' => ['/bank/iu', 'bank'],
      'm, s and x move inline' => ['~a.b~smi', '(?sm)a.b'],
      'brackets' => ['{^(a|b)}', '^(a|b)'],
      'no delimiters stay' => ['plain', 'plain'],
    ];
  }

  /**
   * @dataProvider patterns
   */
  public function testPhpPatternsBecomeDatabasePatterns(string $php, string $sql): void {
    $this->assertSame($sql, CRM_Herald_Upgrader_PhpPattern::toSql($php));
  }

}

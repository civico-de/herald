<?php

declare(strict_types = 1);

namespace Civi\Herald\Monitor;

use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * System.check rows → one observation per check key. The rows are faked at
 * the collector boundary, so no other extension's checks are involved.
 *
 * @group headless
 */
class CheckCollectorTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  private const INFO = 1;

  private const NOTICE = 2;

  private const WARNING = 3;

  private const ERROR = 4;

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  protected function setUp(): void {
    parent::setUp();
    \Civi::settings()->set(ExpectedExtensions::SETTING, []);
  }

  /**
   * @return array<string, mixed>
   */
  private static function row(
    string $name,
    int $severity,
    string $message = '',
    bool $visible = TRUE,
    string $title = 'T',
  ): array {
    return [
      'name' => $name,
      'severity_id' => $severity,
      'message' => $message,
      'is_visible' => (int) $visible,
      'title' => $title,
    ];
  }

  /**
   * @param list<array<string, mixed>> $rows
   * @return array<string, array<string, mixed>>
   */
  private static function observe(array $rows, int $min = self::WARNING): array {
    return (new CheckCollector(static fn (): array => $rows))->observe(new Threshold($min));
  }

  public function testHealthyInfoMessagesAreOkEvenAtTheLowestThreshold(): void {
    $observations = self::observe([
      self::row('checkPhpVersion', self::INFO, 'PHP Up-to-Date'),
      self::row('checkMysqlVersion', self::INFO),
    ], self::NOTICE);
    $this->assertSame(['ok', 'ok'], array_column($observations, 'status'));
  }

  public function testAMessageReachingTheThresholdFails(): void {
    $observations = self::observe([
      self::row('checkOutboundMail', self::WARNING),
      self::row('checkDebug', self::NOTICE),
    ]);
    $this->assertSame('fail', $observations['checkOutboundMail']['status']);
    $this->assertSame('ok', $observations['checkDebug']['status']);
  }

  public function testTheNameSplitsAtTheFirstColon(): void {
    $this->assertSame(['checkExtensionsRequired', 'a,b:c'], CheckCollector::split('checkExtensionsRequired:a,b:c'));
    $this->assertSame(['herald_buffer_backlog', ''], CheckCollector::split('herald_buffer_backlog'));
    $observations = self::observe([self::row('checkExtensionsRequired:a,b', self::ERROR)]);
    $this->assertSame(['checkExtensionsRequired'], array_keys($observations));
    $this->assertSame(['a,b'], $observations['checkExtensionsRequired']['detail']);
  }

  public function testMessagesOfOneKeyMergeIndependentOfTheirOrder(): void {
    $rows = [
      self::row('sentinel_rule:zeta', self::WARNING, 'z', title: 'Zeta'),
      self::row('sentinel_rule:alpha', self::ERROR, 'a', title: 'Alpha'),
      self::row('sentinel_rule:mid', self::NOTICE, 'm', title: 'Mid'),
    ];
    $forward = self::observe($rows);
    $backward = self::observe(array_reverse($rows));
    $this->assertSame($forward, $backward);
    $this->assertSame(self::ERROR, $forward['sentinel_rule']['severity']);
    $this->assertSame(['alpha', 'mid', 'zeta'], $forward['sentinel_rule']['detail']);
    $this->assertSame('Alpha', $forward['sentinel_rule']['title']);
    $this->assertSame("a\n\nz\n\nm", $forward['sentinel_rule']['text']);
  }

  public function testAKeyIsMutedOnlyWhenEveryFailingMessageIsHidden(): void {
    $observations = self::observe([
      self::row('a:1', self::ERROR, visible: FALSE),
      self::row('a:2', self::WARNING),
      self::row('b:1', self::ERROR, visible: FALSE),
      self::row('b:2', self::INFO),
    ]);
    $this->assertTrue($observations['a']['visible']);
    $this->assertFalse($observations['b']['visible']);
  }

  public function testTheMessageHtmlBecomesPlainText(): void {
    $observations = self::observe([
      self::row(
        'checkExtensionsRequired:x',
        self::ERROR,
        '<p>Missing:</p><ul><li><a href="https://example.org">x</a> &amp; more</li></ul>',
      ),
    ]);
    $this->assertSame("Missing:\n- x & more", $observations['checkExtensionsRequired']['text']);
  }

  public function testAnExpectedExtensionThatIsNotEnabledIsAFailingPseudoCheck(): void {
    \Civi::settings()->set(ExpectedExtensions::SETTING, ['herald', 'org.example.absent']);
    $observations = self::observe([]);
    $this->assertSame(['herald_expected_org.example.absent'], array_keys($observations));
    $missing = $observations['herald_expected_org.example.absent'];
    $this->assertSame('fail', $missing['status']);
    $this->assertSame(self::ERROR, $missing['severity']);
    $this->assertStringContainsString('org.example.absent', $missing['text']);
  }

}

<?php

declare(strict_types = 1);

namespace Civi\Herald\Monitor;

use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Threshold, its key patterns and the severities a threshold may use.
 *
 * @group headless
 */
class ThresholdTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  protected function tearDown(): void {
    \Civi::settings()->revert(Threshold::MIN_SETTING);
    \Civi::settings()->revert(Threshold::EXCEPTIONS_SETTING);
    parent::tearDown();
  }

  private const NOTICE = 2;

  private const WARNING = 3;

  private const ERROR = 4;

  private const CRITICAL = 5;

  public function testTheLongestMatchingExceptionWins(): void {
    $threshold = new Threshold(self::WARNING, [
      'warden_*' => self::ERROR,
      'warden_discovery_*' => self::CRITICAL,
      'warden_discovery_main' => self::NOTICE,
    ]);
    $this->assertSame(self::WARNING, $threshold->minFor('pulse_cron'));
    $this->assertSame(self::ERROR, $threshold->minFor('warden_redirect_uri'));
    $this->assertSame(self::CRITICAL, $threshold->minFor('warden_discovery_backup'));
    $this->assertSame(self::NOTICE, $threshold->minFor('warden_discovery_main'));
  }

  public function testAnExactKeyBeatsAPrefixOfTheSameLength(): void {
    $this->assertSame(self::NOTICE, KeyPattern::lookup(['abc*' => self::ERROR, 'abc' => self::NOTICE], 'abc'));
    $this->assertSame(self::NOTICE, KeyPattern::lookup(['abc' => self::NOTICE, 'abc*' => self::ERROR], 'abc'));
    $this->assertNull(KeyPattern::lookup(['abc' => self::NOTICE], 'abcd'));
  }

  public function testWardenWarningsStaySilentUnderTheShippedException(): void {
    $threshold = new Threshold(self::WARNING, ['warden_*' => self::ERROR]);
    $this->assertFalse($threshold->fails('warden_redirect_uri', self::WARNING));
    $this->assertTrue($threshold->fails('warden_redirect_uri', self::ERROR));
    $this->assertTrue($threshold->fails('checkOutboundMail', self::WARNING));
  }

  public function testThresholdsStartAtNotice(): void {
    $this->assertSame(['notice', 'warning', 'error', 'critical', 'alert', 'emergency'], Severity::thresholds());
    $this->assertSame(array_keys(Severity::thresholdOptions()), Severity::thresholds());
    $this->assertSame('warning', Severity::name(self::WARNING));
    $this->assertSame(self::ERROR, Severity::id('error'));
    $this->assertNull(Severity::id('loud'));
  }

  public function testInfoAndDebugAreRejectedAsThreshold(): void {
    $this->assertNull(Threshold::minProblem('notice'));
    $this->assertNotNull(Threshold::minProblem('info'));
    $this->assertNotNull(Threshold::minProblem('debug'));
    $this->assertNotNull(Threshold::minProblem(3));
    $this->assertSame([], Threshold::exceptionsProblems(['warden_*' => 'error', 'checkLastCron' => 'notice']));
    $this->assertCount(5, Threshold::exceptionsProblems([
      'a' => 'info',
      'b c' => 'error',
      'd:detail' => 'error',
      '*' => 'error',
      'e' => ['error'],
    ]));
    $this->assertCount(1, Threshold::exceptionsProblems('warden_*'));
  }

  public function testValidateCallbacksThrowOnALowLevel(): void {
    $ok = 'warning';
    $this->assertTrue(Threshold::validateMin($ok));
    $map = ['warden_*' => 'error'];
    $this->assertTrue(Threshold::validateExceptions($map));
    $low = 'info';
    try {
      Threshold::validateMin($low);
      $this->fail('info accepted as threshold');
    }
    catch (\CRM_Core_Exception $e) {
      $this->assertStringContainsString('notice', $e->getMessage());
    }
    $this->expectException(\CRM_Core_Exception::class);
    $badMap = ['warden_*' => 'debug'];
    Threshold::validateExceptions($badMap);
  }

  public function testTheShippedDefaultsAreWarningAndWardenFromError(): void {
    $threshold = Threshold::fromSettings();
    $this->assertSame(self::WARNING, $threshold->minFor('checkOutboundMail'));
    $this->assertSame(self::ERROR, $threshold->minFor('warden_jwks'));
  }

  public function testSettingSetRejectsALevelBelowNotice(): void {
    \Civi\Api4\Setting::set(FALSE)->addValue(Threshold::MIN_SETTING, 'notice')->execute();
    $this->assertSame('notice', \Civi::settings()->get(Threshold::MIN_SETTING));
    try {
      \Civi\Api4\Setting::set(FALSE)->addValue(Threshold::MIN_SETTING, 'info')->execute();
      $this->fail('Setting.set accepted info as monitor threshold');
    }
    catch (\CRM_Core_Exception $e) {
      $this->assertStringContainsString('notice', $e->getMessage());
    }
    $this->expectException(\CRM_Core_Exception::class);
    \Civi\Api4\Setting::set(FALSE)->addValue(Threshold::EXCEPTIONS_SETTING, ['sentinel_*' => 'debug'])->execute();
  }

  /**
   * A value written past the validation (Civi::settings()->set) fails the run loudly.
   */
  public function testAStoredLevelBelowNoticeFailsInsteadOfFallingBack(): void {
    \Civi::settings()->set(Threshold::MIN_SETTING, 'info');
    $this->expectException(\CRM_Core_Exception::class);
    Threshold::fromSettings();
  }

}

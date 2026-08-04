<?php

declare(strict_types = 1);

namespace Civi\Herald;

use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * {@see RuleHealth} against a booted CiviCRM: the manifests herald ships must
 * name only loadable, instantiable handler classes, and the cached form the
 * pulse check reads must survive untyped settings storage.
 *
 * @group headless
 */
class RuleHealthTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Test::headless()->install('org.civicoop.civirules')->installMe(__DIR__)->apply();
  }

  public function testManifestsNameHandlerClasses(): void {
    $classes = RuleHealth::registeredHandlerClasses();
    self::assertNotEmpty($classes, 'the civirules manifests register at least one handler');
    self::assertSame(array_values(array_unique($classes)), $classes, 'no duplicates');
    foreach ($classes as $class) {
      self::assertStringStartsWith('CRM_Herald_', $class);
    }
  }

  public function testEveryRegisteredHandlerIsLoadable(): void {
    self::assertSame([], RuleHealth::scan());
  }

  public function testProblemNamesAMissingClass(): void {
    self::assertSame(
      'class not found (autoload failed)',
      RuleHealth::problem('CRM_Herald_NoSuchHandler_Nope'),
    );
  }

  public function testProblemNamesAnAbstractClass(): void {
    $problem = RuleHealth::problem(\CRM_Herald_CivirulesActions_AbstractSend::class);
    self::assertIsString($problem);
    self::assertStringContainsString('not instantiable', $problem);
  }

  public function testRefreshCachesAHealthyScan(): void {
    self::assertSame([], RuleHealth::refresh());
    self::assertSame([], RuleHealth::stored());
  }

  public function testStoredNarrowsWhatAnAdminMayHaveWritten(): void {
    \Civi::settings()->set('herald_rule_health', [
      'CRM_Herald_Broken' => 'boom',
      // Non-string key and non-scalar value: dropped rather than trusted.
      7 => 'numeric key',
      'CRM_Herald_Weird' => ['not', 'scalar'],
    ]);
    self::assertSame(['CRM_Herald_Broken' => 'boom'], RuleHealth::stored());
  }

  public function testStoredTreatsANonArraySettingAsHealthy(): void {
    \Civi::settings()->set('herald_rule_health', 'nonsense');
    self::assertSame([], RuleHealth::stored());
  }

}

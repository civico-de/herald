<?php

declare(strict_types = 1);

namespace Civi\Herald\Preflight;

use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * @group headless
 */
class CiviRulesVersionCheckTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Test::headless()->install('org.civicoop.civirules')->installMe(__DIR__)->apply();
  }

  public function testTheInstalledCiviRulesReportsNothing(): void {
    $messages = [];
    \CRM_Utils_Hook::check($messages);
    $names = array_map(static fn (\CRM_Utils_Check_Message $message): string => $message->getName(), $messages);
    self::assertNotContains(CiviRulesVersionCheck::NAME, $names);
  }

  public function testCurrentVersionsPass(): void {
    self::assertNull(CiviRulesVersionCheck::message('3.36.0'));
    self::assertNull(CiviRulesVersionCheck::message('3.44.0'));
    self::assertNull(CiviRulesVersionCheck::message('4.0-dev'));
  }

  public function testAnOlderVersionIsAnErrorNamingBothVersions(): void {
    $message = CiviRulesVersionCheck::message('3.35.2');

    self::assertNotNull($message);
    self::assertSame(\CRM_Utils_Check::severityMap('error'), $message->getLevel());
    self::assertStringContainsString('3.36', $message->getMessage());
    self::assertStringContainsString('3.35.2', $message->getMessage());
  }

}

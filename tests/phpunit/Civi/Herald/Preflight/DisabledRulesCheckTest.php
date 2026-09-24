<?php

declare(strict_types = 1);

namespace Civi\Herald\Preflight;

use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * @group headless
 */
class DisabledRulesCheckTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Test::headless()->install('org.civicoop.civirules')->installMe(__DIR__)->apply();
  }

  protected function tearDown(): void {
    if (is_file(DeployGate::marker())) {
      unlink(DeployGate::marker());
    }
    parent::tearDown();
  }

  public function testAHealthyInstallReportsNothing(): void {
    self::assertFileDoesNotExist(DeployGate::marker());
    self::assertSame([], $this->heraldMessages());
  }

  public function testAMarkerLeftByADeployIsAWarningNamingTheRulesAndTheCommand(): void {
    file_put_contents(DeployGate::marker(), '12,34');

    $messages = $this->heraldMessages();
    self::assertCount(1, $messages);
    self::assertSame(DisabledRulesCheck::NAME, $messages[0]->getName());
    self::assertSame(\CRM_Utils_Check::severityMap('warning'), $messages[0]->getLevel());
    self::assertStringContainsString('12, 34', $messages[0]->getMessage());
    self::assertStringContainsString(
      'cv scr ' . \CRM_Herald_ExtensionUtil::path('bin/rules-reenable.php'),
      $messages[0]->getMessage(),
    );
  }

  /**
   * @return list<\CRM_Utils_Check_Message>
   */
  private function heraldMessages(): array {
    $messages = [];
    \CRM_Utils_Hook::check($messages);
    return array_values(array_filter(
      $messages,
      static fn (\CRM_Utils_Check_Message $message): bool => str_starts_with($message->getName(), 'herald'),
    ));
  }

}

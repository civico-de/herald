<?php

declare(strict_types = 1);

namespace Civi\Herald;

use Civi\Herald\Preflight\DeployGate;
use Civi\Test\HeadlessInterface;
use PHPUnit\Framework\TestCase;

/**
 * herald on a site without CiviRules, and CiviRules installed afterwards.
 * Not transactional: installing an extension is DDL.
 *
 * @group headless
 */
class WithoutCiviRulesTest extends TestCase implements HeadlessInterface {

  private const CIVIRULES = 'org.civicoop.civirules';

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  public function testHeraldInstallsWithoutCiviRules(): void {
    self::assertFalse(\CRM_Herald_CivirulesAdapter_Availability::isInstalled());
    self::assertSame('installed', \CRM_Extension_System::singleton()->getManager()->getStatus('herald'));
    self::assertSame(0, $this->managedHandlerCount());
    self::assertNotEmpty(\civicrm_api4('Herald', 'getChannels', ['checkPermissions' => FALSE])->getArrayCopy());
  }

  /**
   * The scan-classes mixin loads every adapter file. A fresh PHP process, where
   * no CiviRules class can load, must load them all without declaring one.
   */
  public function testAdapterFilesLoadWithoutCiviRules(): void {
    $files = glob(dirname(__DIR__, 4) . '/CRM/Herald/CivirulesAdapter/*/*.php') ?: [];
    self::assertNotEmpty($files);
    $script =
      'foreach (array_slice($argv, 1) as $f) { require $f; }'
      . ' echo count(preg_grep("/^CRM_Herald_CivirulesAdapter_/i", get_declared_classes()));';
    $command =
      escapeshellarg(PHP_BINARY)
      . ' -r '
      . escapeshellarg($script)
      . ' -- '
      . implode(' ', array_map('escapeshellarg', $files))
      . ' 2>&1';

    exec($command, $output, $exitCode);

    self::assertSame([0, ['0']], [$exitCode, $output]);
  }

  public function testDeployGateSaysThereIsNothingToCheck(): void {
    $notes = fopen('php://memory', 'w+');
    self::assertIsResource($notes);

    self::assertSame(0, DeployGate::run($notes));
    self::assertSame(0, DeployGate::noteOthers($notes));

    rewind($notes);
    self::assertStringContainsString('CiviRules is not installed', (string) stream_get_contents($notes));
  }

  public function testInstallingCiviRulesLaterRegistersHeraldsHandlers(): void {
    try {
      \civicrm_api3('Extension', 'install', ['keys' => self::CIVIRULES]);

      $classes = \CRM_Core_DAO::executeQuery(
        "SELECT name, class_name FROM civirule_action WHERE name IN ('herald_send', 'herald_digest')
         UNION ALL SELECT name, class_name FROM civirule_condition WHERE name = 'herald_field_transition'
         UNION ALL SELECT name, class_name FROM civirule_trigger WHERE name = 'herald_schedule'",
      )->fetchMap('name', 'class_name');
      self::assertSame(
        [
          'herald_digest' => 'CRM_Herald_CivirulesAdapter_Action_Digest',
          'herald_field_transition' => 'CRM_Herald_CivirulesAdapter_Condition_FieldTransition',
          'herald_schedule' => 'CRM_Herald_CivirulesAdapter_Trigger_Schedule',
          'herald_send' => 'CRM_Herald_CivirulesAdapter_Action_Notification',
        ],
        $this->sorted($classes),
      );
      self::assertSame(4, $this->managedHandlerCount());
    }
    finally {
      \civicrm_api3('Extension', 'disable', ['keys' => self::CIVIRULES]);
      \civicrm_api3('Extension', 'uninstall', ['keys' => self::CIVIRULES]);
    }

    self::assertFalse(\CRM_Herald_CivirulesAdapter_Availability::isInstalled());
    self::assertSame(0, $this->managedHandlerCount());
  }

  private function managedHandlerCount(): int {
    return (int) \CRM_Core_DAO::singleValueQuery(
      "SELECT COUNT(*) FROM civicrm_managed WHERE module = 'herald' AND entity_type LIKE 'CiviRules%'",
    );
  }

  /**
   * @param array<array-key, mixed> $map
   * @return array<array-key, mixed>
   */
  private function sorted(array $map): array {
    ksort($map);
    return $map;
  }

}

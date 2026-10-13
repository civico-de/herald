<?php

declare(strict_types = 1);

namespace Civi\Herald\Buffer;

use Civi\Api4\HeraldBufferedAlert;
use Civi\Api4\SearchDisplay;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * The buffer table is readable through APIv4 for administrators, and the
 * shipped parked-alerts display lists parked rows without their bodies.
 *
 * @group headless
 */
class BufferedAlertEntityTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  private const BODY = 'body with contact data';

  public function setUpHeadless() {
    return \Civi\Test::headless()->install('org.civicoop.civirules')->installMe(__DIR__)->apply();
  }

  protected function setUp(): void {
    parent::setUp();
    \CRM_Core_DAO::executeQuery('DELETE FROM civicrm_herald_buffer');
    $buffer = new AlertBuffer();
    $buffer->add('grp-parked', 5, self::BODY, ['channel' => 'email']);
    $buffer->add('grp-waiting', 5, self::BODY, ['channel' => 'email']);
    \CRM_Core_DAO::executeQuery(
      "UPDATE civicrm_herald_buffer SET parked_at = NOW(), park_reason = 'no_recipient', attempts = 1
       WHERE group_hash = 'grp-parked'",
    );
  }

  public function testGetReadsTheBufferRows(): void {
    $rows = HeraldBufferedAlert::get(checkPermissions: FALSE)
      ->addSelect('group_hash', 'park_reason', 'attempts')
      ->addWhere('parked_at', 'IS NOT NULL')
      ->execute()
      ->getArrayCopy();

    $this->assertCount(1, $rows);
    $this->assertSame(['grp-parked', 'no_recipient', 1], [
      $rows[0]['group_hash'],
      $rows[0]['park_reason'],
      $rows[0]['attempts'],
    ]);
  }

  public function testOnlyAdministratorsMayRead(): void {
    $permissions = \CRM_Core_Config::singleton()->userPermissionClass;
    $saved = $permissions->permissions;
    $permissions->permissions = ['access CiviCRM'];
    try {
      $this->assertDenied(static fn () => HeraldBufferedAlert::get()->execute());
      $this->assertDenied(static fn () => HeraldBufferedAlert::getFields()->execute());
      $this->assertDenied(
        static fn () => SearchDisplay::run()
          ->setSavedSearch('Herald_Parked_Alerts')
          ->setDisplay('Herald_Parked_Alerts_Table')
          ->execute(),
      );

      $permissions->permissions = ['access CiviCRM', 'administer CiviCRM'];
      $this->assertCount(2, HeraldBufferedAlert::get()->execute());
    }
    finally {
      $permissions->permissions = $saved;
    }
  }

  private function assertDenied(callable $call): void {
    try {
      $call();
      $this->fail('read without "administer CiviCRM"');
    }
    catch (\Civi\API\Exception\UnauthorizedException) {
      $this->addToAssertionCount(1);
    }
  }

  public function testTheEntityOffersNoWritesBesidesReactivate(): void {
    $actions = array_column(
      HeraldBufferedAlert::getActions(checkPermissions: FALSE)->execute()->getArrayCopy(),
      'name',
    );
    $this->assertContains('get', $actions);
    $this->assertContains('reactivate', $actions);
    $this->assertSame([], array_values(array_intersect(['create', 'update', 'save', 'delete', 'replace'], $actions)));
  }

  public function testTheParkedAlertsDisplayListsParkedRowsWithoutBodies(): void {
    $result = SearchDisplay::run(checkPermissions: FALSE)
      ->setSavedSearch('Herald_Parked_Alerts')
      ->setDisplay('Herald_Parked_Alerts_Table')
      ->execute();

    $this->assertCount(1, $result);
    $row = $result->first();
    $this->assertSame('grp-parked', $row['data']['group_hash'] ?? NULL);
    $this->assertArrayNotHasKey('body', $row['data']);
    $this->assertArrayNotHasKey('delivery', $row['data']);
    $this->assertStringNotContainsString(self::BODY, (string) json_encode($result->getArrayCopy()));
  }

  public function testTheMenuOpensTheDisplay(): void {
    $url = (string) \CRM_Core_DAO::singleValueQuery(
      "SELECT url FROM civicrm_navigation WHERE name = 'herald_parked_alerts'",
    );
    $this->assertSame('civicrm/search#/display/Herald_Parked_Alerts/Herald_Parked_Alerts_Table', $url);
  }

}

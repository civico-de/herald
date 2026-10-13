<?php

declare(strict_types = 1);

namespace Civi\Herald\Admin;

use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Shared boot and assertions for the admin-screen actions.
 */
abstract class AdminTestCase extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless() {
    return \Civi\Test::headless()->install('org.civicoop.civirules')->installMe(__DIR__)->apply();
  }

  /**
   * @param array<string, mixed> $params
   * @return list<array<string, mixed>>
   */
  protected static function call(string $action, array $params = [], string $entity = 'Herald'): array {
    return civicrm_api4($entity, $action, ['checkPermissions' => FALSE] + $params)->getArrayCopy();
  }

  /**
   * The call fails with every expected message, shown in full to the admin.
   *
   * @param array<string, mixed> $params
   * @param list<string> $expected Substrings, one per expected error.
   */
  protected function assertRejected(string $action, array $params, array $expected): void {
    try {
      self::call($action, $params);
    }
    catch (InvalidInput $e) {
      $data = $e->getErrorData();
      $this->assertTrue($data['show_detailed_error']);
      $this->assertIsArray($data['errors']);
      $this->assertCount(count($expected), $data['errors'], implode(' | ', $data['errors']));
      foreach ($expected as $i => $needle) {
        $this->assertStringContainsString($needle, (string) $data['errors'][$i]);
      }
      return;
    }
    $this->fail("Herald.{$action} accepted invalid input");
  }

  protected function assertAdminOnly(callable $call): void {
    $permissions = \CRM_Core_Config::singleton()->userPermissionClass;
    $saved = $permissions->permissions;
    $permissions->permissions = ['access CiviCRM'];
    try {
      $call();
      $this->fail('call without "administer CiviCRM" succeeded');
    }
    catch (\Civi\API\Exception\UnauthorizedException) {
      $this->addToAssertionCount(1);
    }
    finally {
      $permissions->permissions = $saved;
    }
  }

}

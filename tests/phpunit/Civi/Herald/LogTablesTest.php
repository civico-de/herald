<?php

declare(strict_types = 1);

namespace Civi\Herald;

use Civi\Herald\Buffer\AlertBuffer;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * herald keeps its own work queue out of CiviCRM's detailed logging.
 *
 * Driven through CRM_Utils_Hook so the registration is under test, not just
 * the function body: a hook that is never called is the failure mode here.
 *
 * @group headless
 */
class LogTablesTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  public function setUpHeadless() {
    return \Civi\Test::headless()->install('org.civicoop.civirules')->installMe(__DIR__)->apply();
  }

  public function testBufferTableIsExcluded(): void {
    $spec = array_fill_keys(['civicrm_contact', AlertBuffer::TABLE], []);
    \CRM_Utils_Hook::alterLogTables($spec);

    $this->assertArrayNotHasKey(AlertBuffer::TABLE, $spec, 'the alert buffer is transient, not a record');
    $this->assertArrayHasKey('civicrm_contact', $spec, 'nothing else is touched');
  }

  /**
   * The spec core hands over does not have to contain the table — a site can
   * have excluded it already, or logging can be off entirely.
   */
  public function testAbsentBufferTableIsNotAnError(): void {
    $spec = ['civicrm_contact' => []];
    \CRM_Utils_Hook::alterLogTables($spec);

    $this->assertSame(['civicrm_contact' => []], $spec);
  }

}

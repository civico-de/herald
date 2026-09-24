<?php

declare(strict_types = 1);

use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Upgrade steps run through core's queue adapter, as `cv upgrade:db` runs them.
 *
 * @group headless
 */
class CRM_Herald_UpgraderTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Test::headless()->install('org.civicoop.civirules')->installMe(__DIR__)->apply();
  }

  public function testUpgrade1006RemovesTheRetiredRuleHealthSetting(): void {
    CRM_Core_DAO::executeQuery(
      "INSERT INTO civicrm_setting (name, value, domain_id, is_domain) VALUES ('herald_rule_health', 'a:0:{}', 1, 1)",
    );
    $ctx = new CRM_Queue_TaskContext();
    $ctx->log = CRM_Core_Error::createDebugLogger();

    self::assertTrue(CRM_Herald_Upgrader::_queueAdapter($ctx, 'herald', 'upgrade_1006'));
    self::assertSame(
      0,
      (int) CRM_Core_DAO::singleValueQuery("SELECT COUNT(*) FROM civicrm_setting WHERE name = 'herald_rule_health'"),
    );
  }

}

<?php

declare(strict_types = 1);

use Civi\Test\HeadlessInterface;
use CRM_Herald_ExtensionUtil as E;
use PHPUnit\Framework\TestCase;

/**
 * A buffer table from the first release, taken through the upgrade steps,
 * ends up identical to one created from schema/. Not transactional: DDL
 * commits implicitly, so the test leaves a freshly created table behind.
 *
 * @group headless
 */
class CRM_Herald_BufferSchemaUpgradeTest extends TestCase implements HeadlessInterface {

  private const TABLE = 'civicrm_herald_buffer';

  private const SCHEMA = 'schema/HeraldBufferedAlert.entityType.php';

  public function setUpHeadless() {
    return \Civi\Test::headless()->install('org.civicoop.civirules')->installMe(__DIR__)->apply();
  }

  private function upgrade(string $step): void {
    $ctx = new CRM_Queue_TaskContext();
    $ctx->log = new class() {

      public function info(string $message): void {
      }

    };
    $this->assertTrue(CRM_Extension_Upgrader_Base::_queueAdapter($ctx, 'herald', $step));
  }

  /**
   * Column definitions and indexes, in order; comments are left out because
   * the first release created its columns without them.
   */
  private function shape(): string {
    $params = [1 => [self::TABLE, 'String']];
    return (
      CRM_Core_DAO::singleValueQuery(
        "SELECT GROUP_CONCAT(CONCAT_WS(' ', COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, IFNULL(COLUMN_DEFAULT, '-'), EXTRA)
         ORDER BY ORDINAL_POSITION SEPARATOR '\n')
       FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %1",
        $params,
      )
      . "\n"
      . CRM_Core_DAO::singleValueQuery(
        "SELECT GROUP_CONCAT(CONCAT_WS(' ', INDEX_NAME, NON_UNIQUE, COLUMN_NAME)
         ORDER BY INDEX_NAME, SEQ_IN_INDEX SEPARATOR '\n')
       FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %1",
        $params,
      )
    );
  }

  public function testUpgradedLegacyTableMatchesTheSchema(): void {
    E::schema()->dropTable(self::TABLE);
    E::schema()->createEntityTable(self::SCHEMA);
    $fresh = $this->shape();
    $this->assertStringContainsString('index_parked_at 1 parked_at', $fresh);

    E::schema()->dropTable(self::TABLE);
    $this->createFirstReleaseTable();
    foreach (['upgrade_1001', 'upgrade_1004', 'upgrade_1005'] as $step) {
      $this->upgrade($step);
    }
    $upgraded = $this->shape();
    foreach (['upgrade_1001', 'upgrade_1004', 'upgrade_1005'] as $step) {
      $this->upgrade($step);
    }

    $this->assertSame($fresh, $upgraded);
    $this->assertSame($upgraded, $this->shape(), 'a second upgrade run changed the table');
  }

  public function testReinstallRepairsATableAnOlderUninstallLeftBehind(): void {
    E::schema()->dropTable(self::TABLE);
    E::schema()->createEntityTable(self::SCHEMA);
    $fresh = $this->shape();
    E::schema()->dropTable(self::TABLE);
    $this->createFirstReleaseTable();

    $this->upgrader()->notify('postInstall');

    $this->assertSame($fresh, $this->shape());
  }

  public function testUninstallDropsTheTable(): void {
    $this->upgrader()->notify('uninstall');
    $dropped = !E::schema()->tableExists(self::TABLE);
    E::schema()->createEntityTable(self::SCHEMA);

    $this->assertTrue($dropped, 'uninstall left civicrm_herald_buffer behind');
  }

  private function upgrader(): CRM_Extension_Upgrader_Interface {
    $upgrader = CRM_Extension_System::singleton()->getMapper()->getUpgrader('herald');
    $this->assertInstanceOf(CRM_Extension_Upgrader_Interface::class, $upgrader);
    return $upgrader;
  }

  /**
   * The buffer table as the first release created it.
   */
  private function createFirstReleaseTable(): void {
    CRM_Core_DAO::executeQuery('
      CREATE TABLE `' . self::TABLE . '` (
        `id` int unsigned NOT NULL AUTO_INCREMENT,
        `group_hash` varchar(64) NOT NULL,
        `window_minutes` int unsigned NOT NULL DEFAULT 5,
        `amount` decimal(20,2) DEFAULT NULL,
        `body` longtext NOT NULL,
        `delivery` longtext NOT NULL,
        `created_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `index_group_hash` (`group_hash`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ');
  }

}

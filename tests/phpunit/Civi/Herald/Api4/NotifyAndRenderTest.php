<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4;

use Civi\Api4\Herald;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Herald.notify resolves the subscription scope before dispatch, so a buffered
 * send still carries it at flush time; Herald.renderTemplate previews a
 * shipped template without sending.
 *
 * @group headless
 */
class NotifyAndRenderTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless() {
    return \Civi\Test::headless()->install('org.civicoop.civirules')->installMe(__DIR__)->apply();
  }

  protected function setUp(): void {
    parent::setUp();
    \CRM_Core_DAO::executeQuery('DELETE FROM civicrm_herald_buffer');
  }

  /**
   * @param array<string, mixed> $triggerContext
   * @return array<string, mixed>
   */
  private function bufferedDelivery(array $triggerContext): array {
    Herald::notify(checkPermissions: FALSE)
      ->setActionParams([
        'channel' => 'telegram',
        'template' => 'herald_event_created_alert',
        'aggregate_minutes' => 15,
        'scope_entity' => 'Event',
        'vars' => ['event' => ['id' => 1, 'title' => 'Scope test', 'start_date' => '2026-09-01', 'is_active' => 1]],
      ])
      ->setTriggerContext($triggerContext)
      ->execute();
    $json = (string) \CRM_Core_DAO::singleValueQuery('SELECT delivery FROM civicrm_herald_buffer');
    $delivery = json_decode($json, associative: TRUE);
    $this->assertIsArray($delivery);
    return $delivery;
  }

  public function testABufferedNotificationKeepsItsScope(): void {
    $delivery = $this->bufferedDelivery(['Participant' => ['id' => 7, 'event_id' => 42, 'contact_id' => 0]]);

    $this->assertSame(['Event', 42], [$delivery['scope_entity_type'] ?? NULL, $delivery['scope_entity_id'] ?? NULL]);
  }

  public function testAnEventTriggerScopesToItsEvent(): void {
    $delivery = $this->bufferedDelivery(['Event' => ['id' => 999_999_999, 'title' => 'Gone summit']]);

    $this->assertSame(['Event', 999_999_999], [
      $delivery['scope_entity_type'] ?? NULL,
      $delivery['scope_entity_id'] ?? NULL,
    ]);
  }

  public function testATriggerWithoutTheScopedEntityStaysUnscoped(): void {
    $this->assertArrayNotHasKey('scope_entity_type', $this->bufferedDelivery([]));
  }

  public function testRenderTemplateReturnsTheBody(): void {
    $result = Herald::renderTemplate(checkPermissions: FALSE)
      ->setWorkflow('herald_contribution_alert_completed')
      ->setVars([
        'contribution' => ['total_amount' => 42.5, 'source' => 'Spring <appeal>'],
        'campaign' => ['title' => '', 'goal' => 0, 'percent' => 0],
      ])
      ->execute()
      ->first();

    $this->assertSame('herald_contribution_alert_completed', $result['workflow'] ?? NULL);
    $this->assertStringContainsString('42.50', (string) ($result['body'] ?? ''));
    $this->assertStringContainsString('Spring &lt;appeal&gt;', (string) ($result['body'] ?? ''));
  }

  public function testRenderTemplateNeedsAWorkflow(): void {
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessage('"workflow" parameter is required');
    Herald::renderTemplate(checkPermissions: FALSE)->execute();
  }

}

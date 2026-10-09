<?php

declare(strict_types = 1);

use Civi\Api4\Job;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Contribution digest jobs become APIv4 jobs of Herald.notifyDigest, sections as blocks.
 *
 * @group headless
 */
class CRM_Herald_Upgrader_DigestJobsTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless() {
    return \Civi\Test::headless()->install('org.civicoop.civirules')->installMe(__DIR__)->apply();
  }

  private function job(string $parameters): int {
    return (int) Job::create(checkPermissions: FALSE)
      ->setValues([
        'name' => 'herald test digest',
        'run_frequency' => 'Hourly',
        'api_entity' => 'Job',
        'api_action' => 'herald_contribution_digest',
        'parameters' => $parameters,
        'is_active' => TRUE,
      ])
      ->execute()
      ->first()['id'];
  }

  /**
   * @return array{api_entity: string, api_action: string, parameters: array<string, mixed>}
   */
  private function stored(int $id): array {
    $row = Job::get(checkPermissions: FALSE)
      ->addSelect('api_entity', 'api_action', 'parameters')
      ->addWhere('id', '=', $id)
      ->execute()
      ->first();
    return [
      'api_entity' => (string) $row['api_entity'],
      'api_action' => (string) $row['api_action'],
      'parameters' => CRM_Core_BAO_Job::parseParameters((string) $row['parameters']),
    ];
  }

  public function testComposedDigestKeepsItsBlocksAndGainsSectionBlocks(): void {
    $id = $this->job(implode("\n", [
      'channel=telegram',
      'chat_id=-100',
      'send_hour=8',
      'blocks=[{"title":"Donations","campaign_id":2,"goal":30000,"campaign_title":"Spring"}]',
      'sections=lima.seats, other.queue',
    ]));

    self::assertSame(1, CRM_Herald_Upgrader_DigestJobs::migrate());

    self::assertSame(
      [
        'api_entity' => 'Herald',
        'api_action' => 'notifyDigest',
        'parameters' => [
          'version' => 4,
          'checkPermissions' => FALSE,
          'sendHour' => 8,
          'actionParams' => [
            'channel' => 'telegram',
            'chat_id' => '-100',
            'blocks' => [
              ['title' => 'Donations', 'campaign_id' => 2],
              ['type' => 'section', 'key' => 'lima.seats'],
              ['type' => 'section', 'key' => 'other.queue'],
            ],
          ],
        ],
      ],
      $this->stored($id),
    );
    self::assertSame(0, CRM_Herald_Upgrader_DigestJobs::migrate());
  }

  public function testSingleDigestDropsTheCampaignText(): void {
    $id = $this->job(
      "version=3\ntemplate=herald_contribution_digest_daily\ncampaign_id=2\ngoal=30000\ncampaign_title=Spring",
    );

    CRM_Herald_Upgrader_DigestJobs::migrate();

    self::assertSame(
      [
        'version' => 4,
        'checkPermissions' => FALSE,
        'actionParams' => ['template' => 'herald_contribution_digest_daily', 'campaign_id' => '2'],
      ],
      $this->stored($id)['parameters'],
    );
  }

  public function testForceDropsTheSendHour(): void {
    $id = $this->job('{"template": "herald_contribution_digest_daily", "send_hour": 8, "force": 1}');

    CRM_Herald_Upgrader_DigestJobs::migrate();

    self::assertSame(
      [
        'version' => 4,
        'checkPermissions' => FALSE,
        'actionParams' => ['template' => 'herald_contribution_digest_daily'],
      ],
      $this->stored($id)['parameters'],
    );
  }

  public function testAJobThatDoesNotParseStaysAndTheOthersMove(): void {
    $broken = $this->job("template=x\n\nchat_id=1");
    $good = $this->job('template=herald_contribution_digest_daily');

    self::assertSame(1, CRM_Herald_Upgrader_DigestJobs::migrate());

    $row = Job::get(checkPermissions: FALSE)->addWhere('id', '=', $broken)->execute()->single();
    self::assertSame('herald_contribution_digest', $row['api_action']);
    self::assertSame('notifyDigest', $this->stored($good)['api_action']);
  }

}

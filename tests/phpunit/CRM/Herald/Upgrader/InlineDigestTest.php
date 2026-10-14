<?php

declare(strict_types = 1);

use Civi\Api4\CiviRulesAction;
use Civi\Api4\CiviRulesRuleAction;
use Civi\Api4\HeraldDigest;
use Civi\Api4\HeraldDigestBlock;
use Civi\Api4\Job;
use Civi\Api4\SavedSearch;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Digests kept inside rule action and job parameters become HeraldDigest
 * records; the parameters keep the delivery settings and name the digest.
 *
 * @group headless
 */
class CRM_Herald_Upgrader_InlineDigestTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless() {
    return \Civi\Test::headless()->install('org.civicoop.civirules')->installMe(__DIR__)->apply();
  }

  /**
   * @return array{digest: array<string, mixed>, blocks: list<array<string, mixed>>}
   */
  private function stored(string $name): array {
    $digest = HeraldDigest::get(checkPermissions: FALSE)
      ->addSelect('id', 'title', 'header', 'period')
      ->addWhere('name', '=', $name)
      ->execute()
      ->single();
    $blocks = HeraldDigestBlock::get(checkPermissions: FALSE)
      ->addWhere('digest_id', '=', $digest['id'])
      ->addOrderBy('id')
      ->execute()
      ->getArrayCopy();
    unset($digest['id']);
    $blocks = array_map(static function (array $block): array {
      unset($block['id'], $block['digest_id']);
      return array_filter($block, static fn ($value): bool => $value !== NULL);
    }, $blocks);
    return ['digest' => $digest, 'blocks' => array_values($blocks)];
  }

  public function testTheParamsWithoutBlocksBecomeOneBlock(): void {
    $delivery = CRM_Herald_Upgrader_InlineDigest::extract(
      [
        'channel' => 'telegram',
        'chat_id' => '-100',
        'header' => '<b>Daily</b>',
        'period' => 'last_7_days',
        'template' => 'herald_contribution_digest_weekly',
        'show_histogram' => '0',
        'bucket_edges' => '[]',
        'group_by' => 'bogus',
        // What the old form saved for an empty blocks field.
        'blocks' => '',
      ],
      'old_daily',
      'Old daily',
    );

    self::assertSame(['digest' => 'old_daily', 'channel' => 'telegram', 'chat_id' => '-100'], $delivery);
    self::assertSame(
      [
        'digest' => ['title' => 'Old daily', 'header' => '<b>Daily</b>', 'period' => 'last_7_days'],
        'blocks' => [[
          'type' => 'contribution',
          'template' => 'herald_contribution_digest_weekly',
          'group_by' => 'source',
          'show_pending' => TRUE,
          'show_histogram' => FALSE,
          'bucket_count' => 8,
        ]],
      ],
      $this->stored('old_daily'),
    );
  }

  public function testBlocksOfEveryTypeKeepTheirOrder(): void {
    $searchId = SavedSearch::create(checkPermissions: FALSE)
      ->setValues([
        'name' => 'herald_test_members',
        'label' => 'Members',
        'api_entity' => 'Contact',
        'api_params' => ['version' => 4, 'select' => ['id']],
      ])
      ->execute()
      ->single()['id'];
    CRM_Herald_Upgrader_InlineDigest::extract(
      [
        'channel' => 'email',
        'blocks' => json_encode([
          ['title' => 'Donations', 'financial_type_id' => 1, 'bucket_edges' => [0, 10, 100], 'period' => 'today'],
          ['type' => 'search', 'search' => 'herald_test_members', 'title' => 'Members'],
          ['type' => 'section', 'key' => 'myext.seats'],
        ]),
      ],
      'old_blocks',
      'Old blocks',
    );

    $blocks = $this->stored('old_blocks')['blocks'];
    self::assertSame('yesterday', $this->stored('old_blocks')['digest']['period']);
    self::assertSame(['contribution', 'search', 'section'], array_column($blocks, 'type'));
    self::assertSame(['Donations', 1, '[0,10,100]', 'today'], [
      $blocks[0]['title'],
      $blocks[0]['financial_type_id'],
      $blocks[0]['bucket_edges'],
      $blocks[0]['period'],
    ]);
    self::assertSame($searchId, $blocks[1]['saved_search_id']);
    self::assertSame('myext.seats', $blocks[2]['section_key']);
  }

  public function testPeriodsAreReadAsOlderReleasesReadThem(): void {
    CRM_Herald_Upgrader_InlineDigest::extract(
      ['period' => 'last_week', 'blocks' => '[{"period": "fortnight"}, {}]'],
      'old_unknown',
      'Old unknown',
    );
    CRM_Herald_Upgrader_InlineDigest::extract(
      ['period' => 'last_7_days', 'blocks' => '[{"period": ""}, {}]'],
      'old_blank',
      'Old blank',
    );

    $unknown = $this->stored('old_unknown');
    self::assertSame('yesterday', $unknown['digest']['period']);
    self::assertSame(['yesterday', NULL], array_map(static fn (array $b) => $b['period'] ?? NULL, $unknown['blocks']));
    // A blank block period meant yesterday, not the digest's period.
    $blank = $this->stored('old_blank')['blocks'];
    self::assertSame(['yesterday', NULL], array_map(static fn (array $b) => $b['period'] ?? NULL, $blank));
  }

  public function testContributionFiltersKeepTheirOldReading(): void {
    CRM_Herald_Upgrader_InlineDigest::extract(
      ['source_pattern' => ' Bank%', 'bucket_edges' => '["0", "10", "100.5"]'],
      'old_filters',
      'Old filters',
    );
    CRM_Herald_Upgrader_InlineDigest::extract(
      ['show_histogram' => '0', 'bucket_count' => '-1'],
      'old_no_histogram',
      'Old no histogram',
    );

    CRM_Herald_Upgrader_InlineDigest::extract(['bucket_count' => '0', 'status' => 0], 'old_zero', 'Old zero');

    $block = $this->stored('old_filters')['blocks'][0];
    self::assertSame([' Bank%', '[0,10,100.5]'], [$block['source_pattern'], $block['bucket_edges']]);
    self::assertFalse($this->stored('old_no_histogram')['blocks'][0]['show_histogram']);
    $zero = $this->stored('old_zero')['blocks'][0];
    self::assertSame([TRUE, 8], [$zero['show_histogram'], $zero['bucket_count']]);
  }

  public function testUnreadableEdgesAreDroppedAsTheyWereIgnored(): void {
    CRM_Herald_Upgrader_InlineDigest::extract(['bucket_edges' => '0, 10, 50'], 'old_edges', 'Old edges');
    self::assertArrayNotHasKey('bucket_edges', $this->stored('old_edges')['blocks'][0]);
  }

  public function testADigestThatCouldNeverRunStaysInline(): void {
    self::assertNull(CRM_Herald_Upgrader_InlineDigest::extract(
      ['blocks' => '[{"type": "search", "search": "herald_test_missing"}]'],
      'old_broken',
      'Old broken',
    ));
    self::assertNull(CRM_Herald_Upgrader_InlineDigest::extract(
      ['blocks' => '{"type": "search"'],
      'old_json',
      'Old JSON',
    ));
    self::assertNull(CRM_Herald_Upgrader_InlineDigest::extract(
      ['campaign_id' => 999_999],
      'old_campaign',
      'Old campaign',
    ));
    self::assertNull(CRM_Herald_Upgrader_InlineDigest::extract(['blocks' => '[{"type": ""}]'], 'old_type', 'Old type'));
    // Records have no status filter; reporting Completed instead would change the numbers unnoticed.
    self::assertNull(CRM_Herald_Upgrader_InlineDigest::extract(['status' => 'Pending'], 'old_status', 'Old status'));
    // A status the old filter kept matched no contribution at all.
    self::assertNull(CRM_Herald_Upgrader_InlineDigest::extract(['status' => FALSE], 'old_false', 'Old false'));
    self::assertSame(
      0,
      HeraldDigest::get(checkPermissions: FALSE)->addWhere('name', 'LIKE', 'old\\_%')->execute()->count(),
    );
  }

  public function testRuleActionsNameTheirNewDigest(): void {
    $actionId = CiviRulesAction::get(checkPermissions: FALSE)
      ->addWhere('name', '=', 'herald_digest')
      ->execute()
      ->single()['id'];
    $ruleId = (int) \Civi\Api4\CiviRulesRule::create(checkPermissions: FALSE)
      ->setValues([
        'name' => 'herald_test_inline',
        'label' => 'Morning numbers',
        'trigger_id' => \Civi\Api4\CiviRulesTrigger::get(checkPermissions: FALSE)
          ->addWhere('name', '=', 'herald_schedule')
          ->execute()
          ->single()['id'],
        'is_active' => TRUE,
      ])
      ->execute()
      ->single()['id'];
    $create = static fn (array $params): int => (int) CiviRulesRuleAction::create(checkPermissions: FALSE)
      ->setValues(['rule_id' => $ruleId, 'action_id' => $actionId, 'action_params' => $params, 'is_active' => TRUE])
      ->execute()
      ->single()['id'];
    $inline = $create(['channel' => 'telegram', 'template' => 'herald_contribution_digest_daily']);
    $stored = $create(['channel' => 'telegram', 'digest' => 'already_stored']);

    self::assertSame(1, CRM_Herald_CivirulesAdapter_InlineDigests::migrate());

    $params = CiviRulesRuleAction::get(checkPermissions: FALSE)
      ->addSelect('id', 'action_params')
      ->addWhere('id', 'IN', [$inline, $stored])
      ->execute()
      ->indexBy('id')
      ->column('action_params');
    self::assertSame(['digest' => "rule_action_{$inline}", 'channel' => 'telegram'], $params[$inline]);
    self::assertSame(['channel' => 'telegram', 'digest' => 'already_stored'], $params[$stored]);
    self::assertSame('Morning numbers', $this->stored("rule_action_{$inline}")['digest']['title']);
  }

  public function testJobsNameTheDigestBesideTheirDelivery(): void {
    $id = (int) Job::create(checkPermissions: FALSE)
      ->setValues([
        'name' => 'Weekly numbers',
        'run_frequency' => 'Daily',
        'api_entity' => 'Herald',
        'api_action' => 'notifyDigest',
        'parameters' => json_encode([
          'version' => 4,
          'sendHour' => 8,
          'actionParams' => [
            'channel' => 'email',
            'email_to' => 'ops@example.org',
            'template' => 'herald_contribution_digest_weekly',
          ],
        ]),
      ])
      ->execute()
      ->single()['id'];

    self::assertSame(1, CRM_Herald_Upgrader_InlineDigestJobs::migrate());
    self::assertSame(0, CRM_Herald_Upgrader_InlineDigestJobs::migrate());

    $parameters = json_decode(
      (string) Job::get(checkPermissions: FALSE)->addWhere('id', '=', $id)->execute()->single()['parameters'],
      TRUE,
    );
    self::assertSame(
      [
        'version' => 4,
        'sendHour' => 8,
        'actionParams' => ['channel' => 'email', 'email_to' => 'ops@example.org'],
        'digest' => "job_{$id}",
      ],
      $parameters,
    );
    self::assertSame('Weekly numbers', $this->stored("job_{$id}")['digest']['title']);
  }

  public function testAJobWithoutActionParamsKeepsSendingTheDefaultDigest(): void {
    $id = (int) Job::create(checkPermissions: FALSE)
      ->setValues([
        'name' => 'Plain digest',
        'run_frequency' => 'Daily',
        'api_entity' => 'Herald',
        'api_action' => 'notifyDigest',
        'parameters' => json_encode(['version' => 4, 'sendHour' => 7]),
      ])
      ->execute()
      ->single()['id'];

    self::assertSame(1, CRM_Herald_Upgrader_InlineDigestJobs::migrate());

    self::assertSame(
      ['version' => 4, 'sendHour' => 7, 'digest' => "job_{$id}", 'actionParams' => []],
      json_decode(
        (string) Job::get(checkPermissions: FALSE)->addWhere('id', '=', $id)->execute()->single()['parameters'],
        TRUE,
      ),
    );
    self::assertSame('contribution', $this->stored("job_{$id}")['blocks'][0]['type']);
  }

}

<?php

declare(strict_types = 1);

use Civi\Api4\Contact;
use Civi\Api4\HeraldDigest;
use Civi\Api4\HeraldDigestSource;
use Civi\Api4\SavedSearch;
use Civi\Herald\Digest\Composer;
use Civi\Herald\Digest\Definition;
use Civi\Herald\Digest\DigestProblem;
use Civi\Herald\Digest\ExtraSections;
use Civi\Herald\Upgrader\InlineDigestFixture;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Digests kept inside rule action and job parameters (0.10.0) become a
 * HeraldDigest with its own template and sources; the parameters keep the
 * delivery settings and name the digest.
 *
 * @group headless
 */
class CRM_Herald_Upgrader_InlineDigestTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  use InlineDigestFixture;

  private const TOTALS = 'Sum: {$block1.sum|crmMoney}, Count: {$block1.count}';

  private const BY_FORM =
    "{foreach from=\$block1_breakdown.rows item=row}\n"
      . "• Form: {\$row.form}, Sum: {\$row.sum|crmMoney}, Count: {\$row.count}\n{/foreach}";

  private const PENDING = '🏦 Pending: Sum: {$block1_pending.sum|crmMoney}, Count: {$block1_pending.count}';

  public function setUpHeadless() {
    // APIv4 hides Contribution.campaign_id while CiviCampaign is off; enabling it commits.
    return \Civi\Test::headless()
      ->install('org.civicoop.civirules')
      ->installMe(__DIR__)
      ->callback(static fn () => \CRM_Core_BAO_ConfigSetting::enableComponent('CiviCampaign'), 'herald-civicampaign')
      ->apply();
  }

  protected function setUp(): void {
    parent::setUp();
    \Civi::dispatcher()->addListener(ExtraSections::HOOK, [self::class, 'seats']);
  }

  protected function tearDown(): void {
    \Civi::dispatcher()->removeListener(ExtraSections::HOOK, [self::class, 'seats']);
    parent::tearDown();
  }

  public static function seats(\Civi\Core\Event\GenericHookEvent $event): void {
    if (in_array('myext.seats', $event->keys, TRUE)) {
      $event->sections['myext.seats'] = ['booked' => 138, 'paid' => 120];
    }
    if (in_array('myext.html', $event->keys, TRUE)) {
      $event->sections['myext.html'] = '<b>138</b> seats';
    }
  }

  /**
   * @param array<string, mixed> $params
   * @return list<string>
   */
  private static function problems(array $params): array {
    try {
      CRM_Core_Transaction::create()->run(
        static fn () => CRM_Herald_Upgrader_InlineDigest::extract(
          $params,
          'old_broken',
          'Old broken',
        ),
      );
    }
    catch (DigestProblem $e) {
      self::assertSame(
        0,
        HeraldDigest::get(checkPermissions: FALSE)->addWhere('name', '=', 'old_broken')->execute()->count(),
      );
      return $e->problems();
    }
    self::fail('The digest migrated.');
  }

  /**
   * @return list<mixed>
   */
  private static function completed(string $field, mixed $period): array {
    return [
      [
        $field === 'receive_date' ? 'contribution_status_id:name' : 'contribution_id.contribution_status_id:name',
        '=',
        'Completed',
      ],
      [str_replace('receive_date', 'is_test', $field), '=', FALSE],
      [str_replace('receive_date', 'is_template', $field), '=', FALSE],
      ...($period === NULL ? [] : [$period]),
    ];
  }

  public function testTheParamsWithoutBlocksBecomeOneBlockOnTheShippedSearches(): void {
    $delivery = CRM_Herald_Upgrader_InlineDigest::extract(
      [
        'channel' => 'telegram',
        'chat_id' => '-100',
        'header' => '<b>Daily</b> {on time}',
        'template' => 'herald_contribution_digest_daily',
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
        'title' => 'Old daily',
        'subject' => '{$block1.count} · {$block1.sum|crmMoney}',
        'html' =>
          "{literal}<b>Daily</b> {on time}{/literal}\n\n" . implode("\n", [self::TOTALS, self::BY_FORM, self::PENDING]),
        'sources' => [
          'block1' => 'Herald_Digest_Contributions_Yesterday_Table',
          'block1_breakdown' => 'Herald_Digest_Contributions_Yesterday_By_Form_Table',
          'block1_pending' => 'Herald_Digest_Contributions_Pending_Table',
        ],
      ],
      $this->stored('old_daily'),
    );
  }

  public function testBlocksOfEveryTypeKeepTheirOrder(): void {
    SavedSearch::create(checkPermissions: FALSE)
      ->setValues([
        'name' => 'herald_test_members',
        'label' => 'Members',
        'api_entity' => 'Contact',
        'api_params' => ['version' => 4, 'select' => ['COUNT(id) AS COUNT_id']],
      ])
      ->execute();
    CRM_Herald_Upgrader_InlineDigest::extract(
      [
        'channel' => 'email',
        'blocks' => json_encode([
          ['title' => 'Donations & gifts', 'show_pending' => FALSE],
          ['type' => 'search', 'search' => 'herald_test_members', 'title' => 'Members'],
          ['type' => 'section', 'key' => 'myext.seats'],
        ]),
      ],
      'old_blocks',
      'Old blocks',
    );

    $stored = $this->stored('old_blocks');
    self::assertSame('Old blocks', $stored['subject']);
    self::assertSame(
      ['block1', 'block1_breakdown', 'block1_amounts', 'block2', 'block3'],
      array_keys($stored['sources']),
    );
    self::assertSame('myext.seats', $stored['sources']['block3']);
    self::assertSame(
      implode("\n\n", [
        implode("\n", [
          '<b>Donations &amp; gifts</b>',
          self::TOTALS,
          self::BY_FORM,
          '<pre>{$block1_amounts.rows|herald_histogram:"amount"}</pre>',
        ]),
        "<b>Members</b>\n(Count) Contact ID: {\$block2.count_contact_id}",
        'booked: {$block3.booked}, paid: {$block3.paid}',
      ]),
      $stored['html'],
    );
  }

  public function testASearchBlockShowsTheRowsTheSearchGave(): void {
    foreach (['Herald Test A', 'Herald Test B', 'Herald Test C'] as $name) {
      Contact::create(checkPermissions: FALSE)
        ->setValues(['contact_type' => 'Organization', 'organization_name' => $name])
        ->execute();
    }
    SavedSearch::create(checkPermissions: FALSE)
      ->setValues([
        'name' => 'herald_test_names',
        'label' => 'Names',
        'api_entity' => 'Contact',
        'api_params' => [
          'version' => 4,
          'select' => ['organization_name'],
          'where' => [['organization_name', 'LIKE', 'Herald Test%']],
          'orderBy' => ['organization_name' => 'DESC'],
          'limit' => 2,
        ],
      ])
      ->execute();
    CRM_Herald_Upgrader_InlineDigest::extract(
      ['blocks' => [['type' => 'search', 'search' => 'herald_test_names', 'title' => 'Names']]],
      'old_names',
      'Old names',
    );

    $stored = $this->stored('old_names');
    self::assertSame('Names: {$block1.rows|count}', $stored['subject']);
    self::assertSame(
      "<b>Names</b>\n{foreach from=\$block1.rows item=row}\n• Organization Name: {\$row.organization_name}\n{/foreach}",
      $stored['html'],
    );
    $message = (new Composer())->compose(Definition::load('old_names'));
    self::assertSame('Names: 2', $message['subject']);
    self::assertStringContainsString('Herald Test C', $message['body']);
    self::assertStringContainsString('Herald Test B', $message['body']);
    self::assertStringNotContainsString('Herald Test A', $message['body']);
  }

  public function testPeriodsBecomeFiltersOfCopiedSearches(): void {
    CRM_Herald_Upgrader_InlineDigest::extract(
      ['period' => 'last_7_days', 'show_pending' => '0', 'show_histogram' => '0', 'group_by' => 'line_item'],
      'old_week',
      'Old week',
    );
    CRM_Herald_Upgrader_InlineDigest::extract(
      ['period' => 'last_week', 'blocks' => '[{"period": "today", "show_pending": false, "show_histogram": false}]'],
      'old_today',
      'Old today',
    );
    $warnings = $this->heraldWarnings(static function (): void {
      CRM_Herald_Upgrader_InlineDigest::extract(
        ['period' => 'fortnight', 'show_pending' => '0', 'show_histogram' => '0'],
        'old_unknown',
        'Old unknown',
      );
    });

    $week = $this->stored('old_week')['sources'];
    $items = [
      ...self::completed('contribution_id.receive_date', NULL),
      ['line_total', '>', 0],
      ['DATEDIFF(CURDATE(), contribution_id.receive_date)', 'BETWEEN', [1, 7]],
    ];
    self::assertSame($items, $week['block1']);
    self::assertSame($items, $week['block1_breakdown']);
    self::assertSame(
      self::completed('receive_date', ['receive_date', '=', 'this.day']),
      $this->stored('old_today')['sources']['block1'],
    );
    // Older releases read every period they did not know as yesterday.
    self::assertSame(['herald: unknown digest period read as yesterday, as before'], $warnings);
    self::assertSame(
      'Herald_Digest_Contributions_Yesterday_Table',
      $this->stored('old_unknown')['sources']['block1'],
    );
  }

  public function testTheLastSevenDaysCountWhatTheOldPeriodCounted(): void {
    \CRM_Utils_Time::setTime('2026-10-14 10:00:00');
    try {
      $contact = Contact::create(checkPermissions: FALSE)
        ->setValues(['contact_type' => 'Individual', 'first_name' => 'Herald'])
        ->execute()
        ->single()['id'];
      foreach ([
        '2026-10-14 08:00:00' => 1,
        '2026-10-13 23:00:00' => 10,
        '2026-10-07 00:30:00' => 100,
        '2026-10-06 23:00:00' => 1000,
      ] as $date => $amount) {
        \Civi\Api4\Contribution::create(checkPermissions: FALSE)
          ->setValues([
            'contact_id' => $contact,
            'financial_type_id:name' => 'Donation',
            'total_amount' => $amount,
            'receive_date' => $date,
            'contribution_status_id:name' => 'Completed',
          ])
          ->execute();
      }
      CRM_Herald_Upgrader_InlineDigest::extract(
        ['period' => 'last_7_days', 'show_pending' => '0', 'show_histogram' => '0'],
        'old_week',
        'Old week',
      );
      $values = (new Composer())->values(Definition::load('old_week'));
    }
    finally {
      \CRM_Utils_Time::resetTime();
    }
    // The SQL clock is not the test clock; only the real day decides.
    $today = new DateTimeImmutable('today');
    $expected = 0;
    foreach (['2026-10-14' => 1, '2026-10-13' => 10, '2026-10-07' => 100, '2026-10-06' => 1000] as $day => $amount) {
      $days = (int) (new DateTimeImmutable($day))
        ->diff($today)
        ->format('%r%a');
      $expected += $days >= 1 && $days <= 7 ? $amount : 0;
    }
    self::assertEquals($expected, $values['block1']['sum'] ?? 0);
  }

  public function testFiltersAndCampaignProgressBecomeCopies(): void {
    $campaign = (int) \Civi\Api4\Campaign::create(checkPermissions: FALSE)
      ->setValues(['title' => 'Spring', 'name' => 'herald_test_spring', 'goal_revenue' => 1000, 'is_active' => TRUE])
      ->execute()
      ->single()['id'];
    CRM_Herald_Upgrader_InlineDigest::extract(
      [
        'campaign_id' => (string) $campaign,
        'financial_type_id' => 1,
        'source_pattern' => ' Bank%',
        'bucket_edges' => '["0", "10", "100.5"]',
      ],
      'old_filters',
      'Old filters',
    );

    $stored = $this->stored('old_filters');
    $filters = [['campaign_id', '=', $campaign], ['financial_type_id', '=', 1], ['source', 'LIKE', ' Bank%']];
    self::assertSame(
      [...self::completed('receive_date', ['receive_date', '=', 'previous.day']), ...$filters],
      $stored['sources']['block1'],
    );
    // The pending pool and campaign progress have no period.
    self::assertSame(
      [
        ['contribution_status_id:name', '=', 'Pending'],
        ['is_test', '=', FALSE],
        ['is_template', '=', FALSE],
        ...$filters,
      ],
      $stored['sources']['block1_pending'],
    );
    self::assertSame(
      [...self::completed('receive_date', NULL), ...$filters],
      $stored['sources']['block1_campaign_total'],
    );
    self::assertSame([['id', '=', $campaign]], $stored['sources']['block1_campaign']);
    self::assertStringContainsString(
      self::TOTALS
      . '{if $block1_campaign.goal gt 0} · '
      . '{($block1_campaign_total.sum * 100 / $block1_campaign.goal)|round:1}% of {$block1_campaign.goal|crmMoney}{/if}',
      $stored['html'],
    );
    self::assertStringContainsString(
      '<pre>{$block1_amounts.rows|herald_histogram:"amount":"0,10,100.5"}</pre>',
      $stored['html'],
    );
    self::assertStringContainsString(
      '% of $1,000.00',
      (new Composer())->compose(Definition::load('old_filters'))['body'],
    );
  }

  public function testALineItemBlockCountsTheMatchingItemsSold(): void {
    $contact = Contact::create(checkPermissions: FALSE)
      ->setValues(['contact_type' => 'Individual', 'first_name' => 'Herald'])
      ->execute()
      ->single()['id'];
    $contribution = \Civi\Api4\Contribution::create(checkPermissions: FALSE)
      ->setValues([
        'contact_id' => $contact,
        'financial_type_id:name' => 'Donation',
        'total_amount' => 35,
        'receive_date' => date('Y-m-d 12:00:00', strtotime('-1 day')),
        'contribution_status_id:name' => 'Completed',
      ])
      ->execute()
      ->single()['id'];
    $item = \Civi\Api4\LineItem::get(checkPermissions: FALSE)
      ->addWhere('contribution_id', '=', $contribution)
      ->execute()
      ->single();
    \Civi\Api4\LineItem::update(checkPermissions: FALSE)
      ->addWhere('id', '=', $item['id'])
      ->setValues([
        'label' => 'Ticket',
        'qty' => 3,
        'unit_price' => 10,
        'line_total' => 30,
        'financial_type_id:name' => 'Event Fee',
      ])
      ->execute();
    \Civi\Api4\LineItem::create(checkPermissions: FALSE)
      ->setValues([
        'entity_table' => 'civicrm_contribution',
        'entity_id' => $contribution,
        'contribution_id' => $contribution,
        'price_field_id' => $item['price_field_id'],
        'label' => 'Gift',
        'qty' => 1,
        'unit_price' => 5,
        'line_total' => 5,
        'financial_type_id:name' => 'Donation',
      ])
      ->execute();
    $eventFee = (int) \Civi\Api4\FinancialType::get(checkPermissions: FALSE)
      ->addWhere('name', '=', 'Event Fee')
      ->execute()
      ->single()['id'];
    CRM_Herald_Upgrader_InlineDigest::extract(
      ['financial_type_id' => $eventFee, 'group_by' => 'line_item', 'show_pending' => '0', 'show_histogram' => '0'],
      'old_tickets',
      'Old tickets',
    );

    $values = (new Composer())->values(Definition::load('old_tickets'));
    self::assertEquals(30, $values['block1']['sum']);
    self::assertEquals(3, $values['block1']['count']);
    self::assertSame(['Ticket'], array_column($values['block1_breakdown']['rows'], 'item'));
    self::assertMatchesRegularExpression(
      '/Sum: \\$30\\.00, Count: 3(?![.\\d])/',
      (new Composer())->compose(Definition::load('old_tickets'))['body'],
    );
  }

  public function testTheHistogramKeepsItsBuckets(): void {
    $warnings = $this->heraldWarnings(static function (): void {
      CRM_Herald_Upgrader_InlineDigest::extract(
        ['bucket_count' => '', 'show_pending' => '0'],
        'old_blank',
        'Old blank',
      );
      CRM_Herald_Upgrader_InlineDigest::extract(['bucket_count' => '5', 'show_pending' => '0'], 'old_five', 'Old five');
      // A count below 0 drew a single bucket.
      CRM_Herald_Upgrader_InlineDigest::extract(
        ['bucket_count' => -3, 'show_pending' => '0'],
        'old_negative',
        'Old negative',
      );
      CRM_Herald_Upgrader_InlineDigest::extract(
        ['bucket_count' => '0', 'show_histogram' => '0', 'show_pending' => '0'],
        'old_hidden',
        'Old hidden',
      );
    });
    self::assertSame([], $warnings);
    $histogram = static fn (string $name): string => (string) preg_replace(
      '/.*<pre>(.*)<\/pre>.*/s',
      '$1',
      Definition::load($name)['template']['msg_html'] ?? '',
    );
    self::assertSame(
      [
        '{$block1_amounts.rows|herald_histogram:"amount"}',
        '{$block1_amounts.rows|herald_histogram:"amount":5}',
        '{$block1_amounts.rows|herald_histogram:"amount":1}',
      ],
      [$histogram('old_blank'), $histogram('old_five'), $histogram('old_negative')],
    );
    self::assertArrayNotHasKey('block1_amounts', $this->stored('old_hidden')['sources']);

    $warnings = $this->heraldWarnings(static function (): void {
      CRM_Herald_Upgrader_InlineDigest::extract(['bucket_count' => '0'], 'old_zero', 'Old zero');
      CRM_Herald_Upgrader_InlineDigest::extract(['bucket_edges' => '0, 10, 50'], 'old_edges', 'Old edges');
      CRM_Herald_Upgrader_InlineDigest::extract(['bucket_edges' => '[10, 0]'], 'old_falling', 'Old falling');
    });
    self::assertSame(
      [
        'herald: histogram bucket count 0 replaced with the default 8',
        'herald: unusable bucket edges dropped, the histogram uses adaptive buckets',
      ],
      $warnings,
    );
    self::assertSame('{$block1_amounts.rows|herald_histogram:"amount"}', $histogram('old_zero'));
    // Old releases read only JSON edges; this text decoded to nothing and drew adaptive buckets.
    self::assertSame('{$block1_amounts.rows|herald_histogram:"amount":"0, 10, 50"}', $histogram('old_edges'));
    self::assertSame('{$block1_amounts.rows|herald_histogram:"amount"}', $histogram('old_falling'));
  }

  public function testAnEmailSubjectMovesIntoTheTemplate(): void {
    $delivery = CRM_Herald_Upgrader_InlineDigest::extract(
      ['channel' => 'email', 'email_subject' => 'Numbers {today}', 'show_pending' => '0'],
      'old_subject',
      'Old subject',
    );
    $config = CRM_Herald_Upgrader_InlineDigest::extract(
      ['channel' => 'email', 'channel_config' => '{"email_subject": "From config", "email_format": "html"}'],
      'old_config',
      'Old config',
    );

    self::assertSame(['digest' => 'old_subject', 'channel' => 'email'], $delivery);
    self::assertSame('{literal}Numbers {today}{/literal}', $this->stored('old_subject')['subject']);
    self::assertSame(
      ['digest' => 'old_config', 'channel' => 'email', 'channel_config' => '{"email_format":"html"}'],
      $config,
    );
    self::assertSame('From config', $this->stored('old_config')['subject']);
  }

  public function testACustomBlockTemplateIsNamedAndReplaced(): void {
    $warnings = $this->heraldWarnings(static function (): void {
      CRM_Herald_Upgrader_InlineDigest::extract(
        ['template' => 'my_digest', 'show_pending' => '0'],
        'old_custom',
        'Old custom',
      );
      CRM_Herald_Upgrader_InlineDigest::extract(
        ['template' => 'herald_contribution_digest_weekly', 'show_pending' => '0'],
        'old_weekly',
        'Old weekly',
      );
    });

    self::assertSame(['herald: a digest block template was replaced with the default text'], $warnings);
    self::assertStringStartsWith(self::TOTALS, $this->stored('old_custom')['html']);
  }

  public function testADigestThatCannotComposeStaysInline(): void {
    self::assertSame(
      ['saved search "herald_test_missing" does not exist'],
      self::problems(['blocks' => '[{"type": "search", "search": "herald_test_missing"}]']),
    );
    self::assertSame(['blocks are not a JSON array of objects'], self::problems(['blocks' => '{"type": "search"']));
    self::assertSame(['campaign_id 999999 does not exist'], self::problems(['campaign_id' => 999_999]));
    self::assertSame(['unknown block type ""'], self::problems(['blocks' => '[{"type": ""}]']));
    // Sources have no status filter; reporting Completed instead would change the numbers unnoticed.
    $status = ['a contribution status filter other than Completed is no longer available'];
    self::assertSame($status, self::problems(['status' => 'Pending']));
    // The pending pool read the status too, so it reported completed contributions.
    self::assertSame($status, self::problems(['status' => 'Completed']));
    // A status the old filter kept matched no contribution at all.
    self::assertSame($status, self::problems(['status' => FALSE]));
    self::assertStringContainsString(
      'answered with text',
      implode(' ', self::problems(['blocks' => '[{"type": "section", "key": "myext.html"}]'])),
    );
    self::assertStringContainsString(
      'No extension answers',
      implode(' ', self::problems(['blocks' => '[{"type": "section", "key": "myext.none"}]'])),
    );
  }

  public function testAStatusFilterRecordsReportTheSameIsDropped(): void {
    CRM_Herald_Upgrader_InlineDigest::extract(
      ['status' => 'Completed', 'show_pending' => '0'],
      'old_completed',
      'Old completed',
    );
    CRM_Herald_Upgrader_InlineDigest::extract(
      ['status' => 'Completed', 'group_by' => 'line_item'],
      'old_items',
      'Old items',
    );
    CRM_Herald_Upgrader_InlineDigest::extract(
      ['blocks' => '[{"type": "section", "key": "myext.seats", "status": "Pending"}]'],
      'old_section',
      'Old section',
    );

    self::assertArrayNotHasKey('block1_pending', $this->stored('old_completed')['sources']);
    self::assertSame(
      'Herald_Digest_Line_Items_Yesterday_Table',
      $this->stored('old_items')['sources']['block1_breakdown'],
    );
    self::assertSame('myext.seats', $this->stored('old_section')['sources']['block1']);
  }

  public function testASearchThatCannotBecomeADisplayIsNamed(): void {
    SavedSearch::create(checkPermissions: FALSE)
      ->setValues([
        'name' => 'herald_test_gone',
        'label' => 'Gone',
        'api_entity' => 'HeraldTestNoSuchEntity',
        'api_params' => ['version' => 4, 'select' => ['id']],
      ])
      ->execute();
    SavedSearch::create(checkPermissions: FALSE)
      ->setValues([
        'name' => 'herald_test_offset',
        'label' => 'Offset',
        'api_entity' => 'Contact',
        'api_params' => ['version' => 4, 'select' => ['id'], 'orderBy' => ['id' => 'ASC'], 'limit' => 2, 'offset' => 2],
      ])
      ->execute();

    self::assertSame(
      ['saved search "herald_test_offset" skips its first 2 rows, which a display cannot'],
      self::problems(['type' => 'search', 'search' => 'herald_test_offset']),
    );
    self::assertStringStartsWith(
      'saved search "herald_test_gone" has no default display: ',
      implode(' ', self::problems(['type' => 'search', 'search' => 'herald_test_gone'])),
    );
  }

  public function testADisplayLabelIsCutToItsColumn(): void {
    SavedSearch::create(checkPermissions: FALSE)
      ->setValues([
        'name' => 'herald_test_members',
        'label' => 'Members',
        'api_entity' => 'Contact',
        'api_params' => ['version' => 4, 'select' => ['id']],
      ])
      ->execute();
    CRM_Herald_Upgrader_InlineDigest::extract(
      ['type' => 'search', 'search' => 'herald_test_members'],
      'old_long',
      str_repeat('ä', 300),
    );

    $display = HeraldDigestSource::get(checkPermissions: FALSE)
      ->addSelect('search_display_id.label')
      ->addWhere('digest_id.name', '=', 'old_long')
      ->execute()
      ->single();
    self::assertSame('Herald: ' . str_repeat('ä', 247), $display['search_display_id.label']);
  }

}

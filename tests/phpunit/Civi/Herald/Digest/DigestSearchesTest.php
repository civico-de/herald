<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Api4\Activity;
use Civi\Api4\Contribution;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use CRM_Herald_ExtensionUtil as E;
use PHPUnit\Framework\TestCase;

/**
 * The searches herald ships count what their names promise, as digest sources.
 *
 * @group headless
 */
class DigestSearchesTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  /**
   * The snippet line and chart of each shipped display, by search name without the
   * period; bars stand in for the rows, a histogram follows them.
   */
  private const SNIPPETS = [
    'Herald_Digest_Contributions' => [TRUE, 'Sum: {$x.sum|crmMoney}, Count: {$x.count}', ''],
    'Herald_Digest_Contributions_By_Form' => [
      FALSE,
      'Form: {$row.form}, Sum: {$row.sum|crmMoney}, Count: {$row.count}',
      'herald_bars:"sum":"form":"money"',
    ],
    'Herald_Digest_Contributions_By_Financial_Type' => [
      FALSE,
      'Financial type: {$row.financial_type}, Sum: {$row.sum|crmMoney}, Count: {$row.count}',
      'herald_bars:"sum":"financial_type":"money"',
    ],
    'Herald_Digest_Line_Items' => [
      FALSE,
      'Item: {$row.item}, Price: {$row.price|crmMoney}, Quantity: {$row.quantity}, Sum: {$row.sum|crmMoney}',
      'herald_bars:"sum":"item":"money"',
    ],
    'Herald_Digest_Contribution_Amounts' => [FALSE, 'Amount: {$row.amount|crmMoney}', 'herald_histogram:"amount"'],
    'Herald_Digest_Activities' => [
      FALSE,
      'Type: {$row.type}, Status: {$row.status}, Count: {$row.count}',
      'herald_bars:"count":"type,status"',
    ],
    'Herald_Digest_Contributions_Pending' => [TRUE, 'Sum: {$x.sum|crmMoney}, Count: {$x.count}', ''],
    'Herald_Digest_Activities_Overdue' => [
      FALSE,
      'Type: {$row.type}, Count: {$row.count}',
      'herald_bars:"count":"type"',
    ],
  ];

  private int $contact;

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  protected function setUp(): void {
    parent::setUp();
    // A Wednesday: yesterday is Tuesday 13 October, last week 4 to 10 October.
    \CRM_Utils_Time::setTime('2026-10-14 10:00:00');
    \Civi::settings()->set('weekBegins', 0);
    $this->contact = (int) \Civi\Api4\Contact::create(checkPermissions: FALSE)
      ->setValues(['contact_type' => 'Individual', 'first_name' => 'Herald', 'last_name' => 'Test'])
      ->execute()
      ->single()['id'];
  }

  protected function tearDown(): void {
    \CRM_Utils_Time::resetTime();
    parent::tearDown();
  }

  /**
   * @return array<string, mixed>
   */
  private static function values(string $search): array {
    $id = \Civi\Api4\SearchDisplay::get(checkPermissions: FALSE)
      ->addWhere('name', '=', "{$search}_Table")
      ->execute()
      ->single()['id'];
    return SearchSource::values((int) $id);
  }

  /**
   * @param array<string, mixed> $values
   */
  private function contribution(string $date, float $amount, array $values = []): void {
    Contribution::create(checkPermissions: FALSE)
      ->setValues(
        $values
        + [
          'contact_id' => $this->contact,
          'financial_type_id:name' => 'Donation',
          'total_amount' => $amount,
          'receive_date' => $date,
          'contribution_status_id:name' => 'Completed',
        ],
      )
      ->execute();
  }

  private function activity(string $date, string $type, string $status, bool $deleted = FALSE): void {
    Activity::create(checkPermissions: FALSE)
      ->setValues([
        'source_contact_id' => $this->contact,
        'activity_type_id:name' => $type,
        'status_id:name' => $status,
        'activity_date_time' => $date,
        'is_deleted' => $deleted,
      ])
      ->execute();
  }

  /**
   * @param list<array<string, mixed>> $rows
   * @return list<array<string, mixed>>
   */
  private static function sorted(array $rows): array {
    usort($rows, static fn (array $a, array $b): int => strcmp(
      json_encode($a, JSON_THROW_ON_ERROR),
      json_encode($b, JSON_THROW_ON_ERROR),
    ));
    return $rows;
  }

  public function testEveryShippedDisplayGivesItsSnippet(): void {
    /** @var list<array{id: int, name: string}> $displays */
    $displays = \Civi\Api4\SearchDisplay::get(checkPermissions: FALSE)
      ->addSelect('id', 'name')
      ->addWhere('name', 'LIKE', 'Herald%')
      ->execute()
      ->getArrayCopy();
    $snippets = [];
    // LIKE reads "_" as any character, which would take in the Herald_Digests list.
    foreach (array_filter($displays, static fn (array $d): bool => str_starts_with(
      $d['name'],
      'Herald_Digest_',
    )) as $display) {
      self::assertNull(SearchSource::problem($display['id']), $display['name']);
      [$single, $line, $chart] = self::SNIPPETS[(string) preg_replace(
        '/_(' . implode('|', array_keys(ShippedSearches::PERIODS)) . ')|_Table$/',
        '',
        $display['name'],
      )];
      $loop = $single ? $line : "{foreach from=\$x.rows item=row}\n• {$line}\n{/foreach}";
      $snippets[$display['name']] = [
        match (TRUE) {
          $chart === '' => $loop,
          str_starts_with($chart, 'herald_bars') => "<pre>{\$x.rows|{$chart}}</pre>",
          default => "{$loop}\n<pre>{\$x.rows|{$chart}}</pre>",
        },
        Snippet::search('x', SearchSource::placeholders($display['id']))['snippet'],
      ];
    }
    self::assertCount(50, $snippets);
    foreach ($snippets as $display => [$expected, $actual]) {
      self::assertSame($expected, $actual, $display);
    }
  }

  public function testTheContributionSearchesCountCompletedContributionsOfTheirPeriod(): void {
    $page = \Civi\Api4\ContributionPage::create(checkPermissions: FALSE)
      ->setValues(['title' => 'Spring appeal', 'financial_type_id:name' => 'Donation', 'is_active' => TRUE])
      ->execute()
      ->single()['id'];
    $this->contribution('2026-10-13 12:00:00', 10, ['contribution_page_id' => $page]);
    $this->contribution('2026-10-13 18:00:00', 30);
    $this->contribution('2026-10-13 12:00:00', 5, ['contribution_status_id:name' => 'Pending']);
    $this->contribution('2026-10-13 12:00:00', 100, ['is_test' => TRUE]);
    $this->contribution('2026-10-07 12:00:00', 50, ['financial_type_id:name' => 'Member Dues']);
    $this->contribution('2026-10-14 08:00:00', 70);

    $yesterday = self::values('Herald_Digest_Contributions_Yesterday');
    self::assertEquals([40, 2], [$yesterday['sum'], $yesterday['count']]);
    $week = self::values('Herald_Digest_Contributions_Last_Week');
    self::assertEquals([50, 1], [$week['sum'], $week['count']]);
    $pending = self::values('Herald_Digest_Contributions_Pending');
    self::assertEquals([5, 1], [$pending['sum'], $pending['count']]);

    self::assertEquals(
      [['form' => NULL, 'sum' => 30, 'count' => 1], ['form' => 'Spring appeal', 'sum' => 10, 'count' => 1]],
      self::values('Herald_Digest_Contributions_Yesterday_By_Form')['rows'],
    );
    self::assertEquals(
      [['financial_type' => 'Member Dues', 'sum' => 50, 'count' => 1]],
      self::values('Herald_Digest_Contributions_Last_Week_By_Financial_Type')['rows'],
    );
    self::assertEquals(
      [
        ['item' => 'Contribution Amount', 'price' => 30, 'quantity' => 1, 'sum' => 30],
        ['item' => 'Contribution Amount', 'price' => 10, 'quantity' => 1, 'sum' => 10],
      ],
      self::values('Herald_Digest_Line_Items_Yesterday')['rows'],
    );
    $amounts = array_column(self::values('Herald_Digest_Contribution_Amounts_Yesterday')['rows'], 'amount');
    sort($amounts);
    self::assertEquals([10, 30], $amounts);
  }

  public function testEachPeriodEndsWhereItsNameSays(): void {
    foreach ([
      '2026-10-14 08:00:00' => 70,
      '2026-10-13 12:00:00' => 40,
      '2026-10-08 12:00:00' => 8,
      '2026-10-07 12:00:00' => 7,
      '2026-10-01 12:00:00' => 1,
      '2026-09-30 12:00:00' => 900,
      '2026-01-02 12:00:00' => 2000,
      '2025-12-31 12:00:00' => 30_000,
      '2026-10-16 12:00:00' => 500_000,
    ] as $date => $amount) {
      $this->contribution($date, $amount);
    }

    $sums = [];
    foreach (array_keys(ShippedSearches::PERIODS) as $period) {
      $sums[$period] = self::values("Herald_Digest_Contributions_{$period}")['sum'];
    }
    self::assertEquals(
      [
        'Today' => 70,
        'Yesterday' => 40,
        'This_Week' => 110,
        'Last_Week' => 15,
        'Last_7_Days' => 118,
        'This_Month' => 126,
        'Last_Month' => 900,
        'This_Year' => 3026,
      ],
      $sums,
    );
  }

  public function testTheActivitySearchesCountWorkThatIsNotDeleted(): void {
    $this->activity('2026-10-13 09:00:00', 'Meeting', 'Completed');
    $this->activity('2026-10-13 15:00:00', 'Meeting', 'Completed');
    $this->activity('2026-10-13 11:00:00', 'Phone Call', 'Scheduled');
    $this->activity('2026-10-13 11:00:00', 'Meeting', 'Completed', deleted: TRUE);
    $this->activity('2026-10-13 11:00:00', 'herald_notification', 'Scheduled');
    $this->activity('2026-10-05 11:00:00', 'Meeting', 'Completed');
    $this->activity('2026-10-01 11:00:00', 'Meeting', 'Scheduled');
    $this->activity('2026-10-01 11:00:00', 'Meeting', 'Completed');
    // Overdue compares with the real clock, so this one lies far ahead.
    $this->activity('2099-01-01 11:00:00', 'Phone Call', 'Scheduled');

    self::assertEquals(
      [
        ['type' => 'Meeting', 'status' => 'Completed', 'count' => 2],
        ['type' => 'Phone Call', 'status' => 'Scheduled', 'count' => 1],
      ],
      self::values('Herald_Digest_Activities_Yesterday')['rows'],
    );
    self::assertEquals(
      [['type' => 'Meeting', 'status' => 'Completed', 'count' => 1]],
      self::values('Herald_Digest_Activities_Last_Week')['rows'],
    );
    self::assertEquals(
      self::sorted([['type' => 'Meeting', 'count' => 1], ['type' => 'Phone Call', 'count' => 1]]),
      self::sorted(self::values('Herald_Digest_Activities_Overdue')['rows']),
    );
  }

  public function testWithoutCiviContributeOnlyTheActivitySearchesAreDeclared(): void {
    $settings = \Civi::settings();
    // A mandatory value wins over the stored one and switches no extension.
    $settings->loadMandatory(['enable_components' => array_values(array_diff(
      $settings->get('enable_components'),
      ['CiviContribute'],
    ))]);
    try {
      $declared = array_column(require E::path('managed/DigestSearches.mgd.php'), 'name');
    }
    finally {
      \Civi::service('settings_manager')->flush();
    }
    $expected = [];
    foreach ([...array_keys(ShippedSearches::PERIODS), 'Overdue'] as $period) {
      $expected[] = "SavedSearch_Herald_Digest_Activities_{$period}";
      $expected[] = "SearchDisplay_Herald_Digest_Activities_{$period}_Table";
    }
    self::assertSame($expected, $declared);
    self::assertContains('CiviContribute', \Civi::settings()->get('enable_components'));
  }

}

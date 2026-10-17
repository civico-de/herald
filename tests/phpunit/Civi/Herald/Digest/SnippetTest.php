<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Test\HeadlessInterface;
use PHPUnit\Framework\TestCase;

/**
 * The snippet beside a source is template text that renders without a warning.
 *
 * @phpstan-import-type Field from Snippet
 *
 * @group headless
 */
class SnippetTest extends TestCase implements HeadlessInterface {

  private const SEATS = ['booked' => 138, 'paid' => 120, 'types' => [['type' => 'Ticket', 'n' => 3]], 'waiting' => []];

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  public function testASectionReadsItsValuesAndLoopsOverItsLists(): void {
    self::assertSame(
      [
        'placeholders' => [
          ['placeholder' => '{$seats.booked}', 'label' => 'booked'],
          ['placeholder' => '{$seats.paid}', 'label' => 'paid'],
          ['placeholder' => '{$seats.types}', 'label' => 'Rows with type, n'],
          ['placeholder' => '{$seats.waiting}', 'label' => 'Rows; there are none now, so their names are unknown'],
        ],
        'snippet' =>
          "booked: {\$seats.booked}, paid: {\$seats.paid}\n"
            . "{foreach from=\$seats.types item=row}\n• type: {\$row.type}, n: {\$row.n}\n{/foreach}",
      ],
      Snippet::section('seats', self::SEATS),
    );
  }

  public function testAGroupedSearchIsDrawnAsBarsAndASingleRowIsReadDirectly(): void {
    $fields = [
      ['name' => 'form', 'label' => 'Form', 'type' => 'Integer'],
      ['name' => 'sum', 'label' => 'Sum {total}', 'type' => 'Money'],
      ['name' => 'last', 'label' => 'Last', 'type' => 'Date'],
    ];

    $one = Snippet::search('donations', ['fields' => $fields, 'single' => TRUE, 'grouped' => FALSE]);
    $many = Snippet::search('donations', ['fields' => $fields, 'single' => FALSE, 'grouped' => TRUE]);

    // A brace in a header would open a Smarty tag.
    $line = 'Form: {$%1.form}, Sum total: {$%1.sum|crmMoney}, Last: {$%1.last|crmDate}';
    self::assertSame(strtr($line, ['%1' => 'donations']), $one['snippet']);
    self::assertSame(
      '<pre>{$donations.rows|herald_bars:"sum":"last":"money"}</pre>',
      $many['snippet'],
    );
    self::assertSame(
      ['{$donations.form}', '{$donations.sum}', '{$donations.last}', '{$donations.rows}'],
      array_column($many['placeholders'], 'placeholder'),
    );
  }

  /**
   * @return array<string, array{list<Field>, bool, bool, string}>
   */
  public static function charts(): array {
    $amount = ['name' => 'amount', 'label' => 'Amount', 'type' => 'Money'];
    $count = ['name' => 'n', 'label' => 'Count', 'type' => 'Integer'];
    $form = ['name' => 'form', 'label' => 'Form', 'type' => 'String'];
    return [
      'one row' => [[$form, $amount], TRUE, FALSE, ''],
      'single values' => [[$form, $amount], FALSE, FALSE, '<pre>{$x.rows|herald_histogram:"amount"}</pre>'],
      'groups by money' => [
        [$form, $count, $amount],
        FALSE,
        TRUE,
        '<pre>{$x.rows|herald_bars:"amount":"form":"money"}</pre>',
      ],
      'groups by count' => [[$form, $count], FALSE, TRUE, '<pre>{$x.rows|herald_bars:"n":"form"}</pre>'],
      'groups without a label' => [[$count], FALSE, TRUE, ''],
      'no number' => [[$form], FALSE, TRUE, ''],
    ];
  }

  /**
   * @param list<Field> $fields
   * @dataProvider charts
   */
  public function testTheChartFitsTheShapeOfTheSearch(array $fields, bool $single, bool $grouped, string $chart): void {
    $snippet = Snippet::search('x', ['fields' => $fields, 'single' => $single, 'grouped' => $grouped])['snippet'];

    self::assertSame(
      $chart,
      str_contains($snippet, '<pre>') ? substr($snippet, (int) strrpos("\n" . $snippet, "\n")) : '',
    );
  }

  public function testEverySnippetRendersWithItsSourcesValues(): void {
    $rows = [['form' => 'Spring appeal', 'sum' => 12.5, 'last' => '2026-10-13']];
    $fields = [
      ['name' => 'form', 'label' => 'Form', 'type' => 'String'],
      ['name' => 'sum', 'label' => 'Sum', 'type' => 'Money'],
      ['name' => 'last', 'label' => 'Last', 'type' => 'Date'],
    ];
    $template =
      Snippet::section('seats', self::SEATS)['snippet']
      . "\n"
      . Snippet::search('donations', ['fields' => $fields, 'single' => TRUE, 'grouped' => FALSE])['snippet']
      . "\n"
      . Snippet::search('donations', ['fields' => $fields, 'single' => FALSE, 'grouped' => TRUE])['snippet'];
    $digest = [
      'name' => 'daily',
      'title' => 'Daily',
      'msg_subject' => '',
      'msg_html' => $template,
      'sources' => [],
    ];

    $body = (new Composer())->render($digest, [
      'seats' => self::SEATS,
      'donations' => $rows[0] + ['rows' => $rows],
    ])['body'];

    self::assertStringContainsString('booked: 138, paid: 120', $body);
    self::assertStringContainsString('• type: Ticket, n: 3', $body);
    self::assertStringContainsString('Form: Spring appeal, Sum: $12.50, Last: October 13th, 2026', $body);
    self::assertStringContainsString('Spring appeal   2026-10-13   $12.50  ████████████', $body);
  }

}

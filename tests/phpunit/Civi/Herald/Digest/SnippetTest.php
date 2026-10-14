<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Test\HeadlessInterface;
use PHPUnit\Framework\TestCase;

/**
 * The snippet beside a source is template text that renders without a warning.
 *
 * @group headless
 */
class SnippetTest extends TestCase implements HeadlessInterface {

  private const SEATS = ['booked' => 138, 'paid' => 120, 'types' => [['type' => 'Ticket', 'n' => 3]], 'waiting' => []];

  public function setUpHeadless() {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
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

  public function testASearchLoopsUnlessItHasOneRow(): void {
    $fields = [
      ['name' => 'form', 'label' => 'Form', 'type' => 'Integer'],
      ['name' => 'sum', 'label' => 'Sum {total}', 'type' => 'Money'],
      ['name' => 'last', 'label' => 'Last', 'type' => 'Date'],
    ];

    $one = Snippet::search('donations', ['fields' => $fields, 'single' => TRUE]);
    $many = Snippet::search('donations', ['fields' => $fields, 'single' => FALSE]);

    // A brace in a header would open a Smarty tag.
    $line = 'Form: {$%1.form}, Sum total: {$%1.sum|crmMoney}, Last: {$%1.last|crmDate}';
    self::assertSame(strtr($line, ['%1' => 'donations']), $one['snippet']);
    self::assertSame(
      "{foreach from=\$donations.rows item=row}\n• " . strtr($line, ['%1' => 'row']) . "\n{/foreach}",
      $many['snippet'],
    );
    self::assertSame(
      ['{$donations.form}', '{$donations.sum}', '{$donations.last}', '{$donations.rows}'],
      array_column($many['placeholders'], 'placeholder'),
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
      . Snippet::search('donations', ['fields' => $fields, 'single' => TRUE])['snippet']
      . "\n"
      . Snippet::search('donations', ['fields' => $fields, 'single' => FALSE])['snippet'];
    $digest = [
      'name' => 'daily',
      'title' => 'Daily',
      'template' => ['msg_subject' => '', 'msg_html' => $template, 'msg_text' => ''],
      'sources' => [],
    ];

    $body = (new Composer())->render($digest, [
      'seats' => self::SEATS,
      'donations' => $rows[0] + ['rows' => $rows],
    ])['body'];

    self::assertStringContainsString('booked: 138, paid: 120', $body);
    self::assertStringContainsString('• type: Ticket, n: 3', $body);
    self::assertStringContainsString('Form: Spring appeal, Sum: $12.50, Last: October 13th, 2026', $body);
    self::assertStringContainsString('• Form: Spring appeal, Sum: $12.50', $body);
  }

}

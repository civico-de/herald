<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Herald\Renderer\TemplateRenderer;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * A digest's template filled with the values of its sources, and every way
 * the template can fail named as a problem.
 *
 * @group headless
 */
class ComposerTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  /**
   * Display ids by the role the fake search gives them: 'filled', 'empty'.
   *
   * @var array<string, int>
   */
  private array $ids = [];

  /**
   * @var list<int>
   */
  private array $displays = [];

  public function setUpHeadless() {
    return \Civi\Test::headless()->install('org.civicoop.civirules')->installMe(__DIR__)->apply();
  }

  protected function setUp(): void {
    parent::setUp();
    $search = \Civi\Api4\SavedSearch::create(checkPermissions: FALSE)
      ->setValues([
        'label' => 'Herald composer test',
        'api_entity' => 'Contribution',
        'api_params' => ['version' => 4, 'select' => ['source', 'total_amount', 'id']],
      ])
      ->execute()
      ->single()['id'];
    $columns = [
      ['key' => 'source', 'label' => 'Form'],
      ['key' => 'total_amount', 'label' => 'Sum'],
      ['key' => 'id', 'label' => 'Count'],
    ];
    foreach (['filled', 'empty'] as $role) {
      $this->ids[$role] = \Civi\Api4\SearchDisplay::create(checkPermissions: FALSE)
        ->setValues([
          'saved_search_id' => $search,
          'name' => 'herald_composer_' . $role,
          'label' => $role,
          'type' => 'table',
          'settings' => ['columns' => array_map(static fn (array $c): array => $c + ['type' => 'field'], $columns)],
        ])
        ->execute()
        ->single()['id'];
    }
  }

  /**
   * @param array<string> $keys
   * @param array<string, mixed> $context
   * @param array<string, mixed> $sections
   */
  public function hook_civicrm_heraldDigestSections(array $keys, array $context, array &$sections): void {
    $sections['test.seats'] = ['booked' => 138, 'paid' => 120, 'digest' => $context['digest']];
  }

  private function composer(): Composer {
    $search = function (int $id): array {
      $this->displays[] = $id;
      $rows = [
        ['form' => 'Spring <appeal>', 'sum' => 120.5, 'count' => 3],
        ['form' => NULL, 'sum' => 20, 'count' => 1],
      ];
      return (
        $id === $this->ids['empty']
          ? ['form' => NULL, 'sum' => NULL, 'count' => NULL, 'rows' => []]
          : $rows[0] + ['rows' => $rows]
      );
    };
    return new Composer(new TemplateRenderer(), $search);
  }

  /**
   * A digest whose search sources name their display by role ('filled', 'empty') or by id.
   *
   * @param list<array<string, mixed>>|null $sources
   * @return array{name: string, title: string, template: ?array{msg_subject: string, msg_html: string, msg_text: string}, sources: list<array{name: string, type: string, search_display_id: ?int, section_key: ?string}>}
   */
  private function digest(
    string $html,
    string $subject = '',
    ?array $sources = NULL,
    string $text = '',
    array $change = [],
  ): array {
    $sources = array_map(
      fn (array $source): array => [
        'search_display_id' => $this->ids[$source['search_display_id']] ?? $source['search_display_id'],
      ] + $source,
      $change['sources']
      ?? $sources
      ?? [
        ['name' => 'donations', 'type' => 'search', 'search_display_id' => 'filled', 'section_key' => NULL],
        ['name' => 'nothing', 'type' => 'search', 'search_display_id' => 'empty', 'section_key' => NULL],
        ['name' => 'seats', 'type' => 'section', 'search_display_id' => NULL, 'section_key' => 'test.seats'],
      ],
    );
    $template = ['msg_subject' => $subject, 'msg_html' => $html, 'msg_text' => $text];
    return [
      'name' => 'daily',
      'title' => 'Daily report',
      'template' => array_key_exists('template', $change) ? $change['template'] : $template,
      'sources' => $sources,
    ];
  }

  public function testSourcesFillTheTemplateByName(): void {
    $html =
      '<b>{$donations.sum}</b> ({$donations.count})'
      . '{foreach from=$donations.rows item=row} · {$row.form|default:"none"}: {$row.sum}{/foreach}'
      . ' | {$seats.booked}/{$seats.paid} for {$seats.digest}';

    $message = $this->composer()->compose($this->digest($html, 'Report {$donations.count}'));

    self::assertSame(
      '<b>120.5</b> (3) · Spring &lt;appeal&gt;: 120.5 · none: 20 | 138/120 for daily',
      $message['body'],
    );
    self::assertSame('Report 3', $message['subject']);
    self::assertSame(3, $message['sources']);
    self::assertSame([$this->ids['filled'], $this->ids['empty']], $this->displays);
  }

  public function testWithoutASubjectTheTitleIsTheSubject(): void {
    self::assertSame('Daily report', $this->composer()->compose($this->digest('x'))['subject']);
    self::assertSame(
      'Daily report',
      $this->composer()->compose($this->digest('x', '{if $nothing.sum}{$nothing.sum}{/if}'))['subject'],
    );
  }

  public function testATextTemplateIsEscapedWhole(): void {
    $message = $this->composer()->compose($this->digest('', '', NULL, "Form: {\$donations.form}\n<b>not markup</b>"));

    self::assertSame("Form: Spring &lt;appeal&gt;\n&lt;b&gt;not markup&lt;/b&gt;", $message['body']);
  }

  public function testADigestWithoutSourcesIsAReminder(): void {
    self::assertSame(
      '<p>Check the queue</p>',
      $this->composer()->compose($this->digest('<p>Check the queue</p>', '', []))['body'],
    );
  }

  public function testTheHistogramModifierCharts(): void {
    $body = $this->composer()->compose($this->digest(
      '<pre>{$donations.rows|herald_histogram:"sum":"0,50"}</pre>',
    ))['body'];

    self::assertStringContainsString('0–50', $body);
    self::assertStringContainsString('≥50', $body);
  }

  /**
   * Healthy templates must not warn: a false alarm stops a digest that is fine.
   *
   * @return array<string, array{string}>
   */
  public static function healthyTemplates(): array {
    return [
      'optional value' => [
        '{if !empty($nothing.sum)}{$nothing.sum}{/if}{if isset($donations.form)}{$donations.form}{/if}',
      ],
      'empty rows' => ['{foreach from=$nothing.rows item=row}{$row.sum}{/foreach}'],
      'money of NULL' => ['{$nothing.sum|crmMoney}'],
      'date of NULL' => ['{$nothing.sum|date_format:"%d.%m.%Y"}'],
      'round of NULL' => ['{$nothing.sum|round:1}'],
      'arithmetic with NULL' => ['{($nothing.sum * 100)}'],
      'yesterday' => ['{"yesterday"|date_format:"%d.%m.%Y"}'],
      'guarded division' => ['{if $nothing.count gt 0}{($donations.sum / $nothing.count)}{/if}'],
      'comparison words' => ['{if $donations.count gt 1 and $donations.count lt 5}few{/if}'],
      'histogram without rows' => ['<pre>{$nothing.rows|herald_histogram:"sum"}</pre>'],
    ];
  }

  /**
   * @dataProvider healthyTemplates
   */
  public function testAHealthyTemplateComposes(string $html): void {
    $this->composer()->compose($this->digest($html));
    $this->addToAssertionCount(1);
  }

  /**
   * @return array<string, array{string, string}>
   */
  public static function brokenTemplates(): array {
    return [
      'typo in a value' => ['{$donations.sume}', '{$donations.sume} is not a placeholder of this digest.'],
      'typo in a row value' => [
        '{foreach from=$donations.rows item=row}{$row.sume}{/foreach}',
        '{$row.sume} is not a placeholder of this digest.',
      ],
      'unknown source' => ['{$donation.sum}', '{$donation} is not a source of this digest.'],
      'bare unknown source' => ['{$plaetze}', '{$plaetze} is not a source of this digest.'],
      'unclosed if' => ['{if $donations.sum}x', 'The template cannot be rendered:'],
      'editor entities' => ['{if $donations.sum &gt; 1}x{/if}', 'write gt, lt and and instead.'],
      'division by zero' => [
        '{($donations.sum / $nothing.count)}',
        'The template cannot be rendered: Division by zero',
      ],
      'histogram of an unknown column' => [
        '{$donations.rows|herald_histogram:"amount"}',
        'herald_histogram: the rows have no column "amount".',
      ],
      'core API call' => ['{crmAPI entity="Domain" action="getcount" var="n"}{$n}', "Tag 'crmAPI' is not allowed"],
    ];
  }

  /**
   * @dataProvider brokenTemplates
   */
  public function testABrokenTemplateIsAProblem(string $html, string $message): void {
    $this->expectException(DigestProblem::class);
    $this->expectExceptionMessage($message);
    $this->composer()->compose($this->digest($html));
  }

  public function testABrokenSubjectIsAProblem(): void {
    $this->expectException(DigestProblem::class);
    $this->expectExceptionMessage('{$donations.sume} is not a placeholder of this digest.');
    $this->composer()->compose($this->digest('fine', 'Report {$donations.sume}'));
  }

  /**
   * @return array<string, array{array<string, mixed>, string}>
   */
  public static function unsendableDigests(): array {
    $search = ['name' => 'donations', 'type' => 'search', 'search_display_id' => 'filled', 'section_key' => NULL];
    return [
      'no template' => [
        ['template' => NULL],
        'Digest "Daily report" has no message template; choose one in the digest form.',
      ],
      'two sources of one name' => [['sources' => [$search, $search]], 'Two sources are named donations.'],
      'a section without key' => [
        ['sources' => [['name' => 'seats', 'type' => 'section', 'search_display_id' => NULL, 'section_key' => NULL]]],
        'Source seats: A section source needs a section key.',
      ],
      'an unknown type' => [
        [
          'sources' => [[
            'name' => 'seats',
            'type' => 'contribution',
            'search_display_id' => NULL,
            'section_key' => NULL,
          ]],
        ],
        'Source seats: Unknown type "contribution".',
      ],
      'a deleted display' => [
        ['sources' => [['search_display_id' => 999_999] + $search]],
        'Source donations: Search display 999999 does not exist.',
      ],
      'a reserved name' => [
        ['sources' => [['name' => 'smarty'] + $search]],
        'Source smarty: "smarty" is a name Smarty reserves',
      ],
      'a name with a capital' => [
        ['sources' => [['name' => 'Spenden'] + $search]],
        'Source Spenden: A source name starts with a lowercase letter',
      ],
    ];
  }

  /**
   * @dataProvider unsendableDigests
   * @param array<string, mixed> $change
   */
  public function testAnUnsendableDigestFailsBeforeItsSourcesRun(array $change, string $message): void {
    try {
      $this->composer()->compose($this->digest('fine', change: $change));
      self::fail('composed');
    }
    catch (DigestProblem $e) {
      self::assertStringContainsString($message, $e->getMessage());
    }
    self::assertSame([], $this->displays);
  }

}

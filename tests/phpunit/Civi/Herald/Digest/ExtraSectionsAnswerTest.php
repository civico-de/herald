<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use PHPUnit\Framework\TestCase;

/**
 * answer() asks listeners through hook_civicrm_heraldDigestSections for one key.
 *
 * @group headless
 */
class ExtraSectionsAnswerTest extends TestCase implements HeadlessInterface, HookInterface {

  /**
   * @var array<string, mixed>
   */
  private array $seenContext = [];

  public function setUpHeadless() {
    return \Civi\Test::headless()->install('org.civicoop.civirules')->installMe(__DIR__)->apply();
  }

  /**
   * @param list<string> $keys
   * @param array<string, mixed> $context
   * @param array<string, mixed> $sections
   */
  public function hook_civicrm_heraldDigestSections(array $keys, array $context, array &$sections): void {
    $this->seenContext = $context;
    $sections['test.unrequested'] = ['never' => 'shown'];
    $sections['test.seats'] = [
      'booked' => 138,
      'paid' => 120.5,
      'note' => NULL,
      'rows' => [['type' => 'Ticket', 'n' => 3]],
    ];
    $sections['test.text'] = "Seats\n138 booked";
    $sections['test.bad_name'] = ['Booked' => 138];
    $sections['test.nested'] = ['seats' => ['booked' => 138]];
    $sections['test.deep_rows'] = ['rows' => [['type' => ['Ticket']]]];
    $sections['test.row_name'] = ['rows' => [['Type' => 'Ticket']]];
  }

  public function testTheListenerAnswersWithValuesForTheDigest(): void {
    $this->assertSame(
      ['booked' => 138, 'paid' => 120.5, 'note' => NULL, 'rows' => [['type' => 'Ticket', 'n' => 3]]],
      ExtraSections::answer('test.seats', ['digest' => 'daily']),
    );
    $this->assertSame(['digest' => 'daily'], $this->seenContext);
  }

  /**
   * @return array<string, array{string, string}>
   */
  public static function unusableAnswers(): array {
    return [
      'no answer' => ['test.typo', 'No extension answers the digest section "test.typo".'],
      'text' => ['test.text', 'The digest section "test.text" answered with text; a section returns values'],
      'a name with capitals' => ['test.bad_name', 'Digest section "test.bad_name": the name "Booked" is not lowercase'],
      'a named list' => [
        'test.nested',
        'Digest section "test.nested": "seats" is neither a value nor a list of rows of values.',
      ],
      'rows of lists' => ['test.deep_rows', 'Digest section "test.deep_rows": "rows" is neither a value'],
      'a row name with capitals' => [
        'test.row_name',
        'Digest section "test.row_name": a row of "rows" names a value "Type", which is not lowercase',
      ],
    ];
  }

  /**
   * @dataProvider unusableAnswers
   */
  public function testAnUnusableAnswerIsAProblem(string $key, string $message): void {
    $this->expectException(DigestProblem::class);
    $this->expectExceptionMessage($message);
    ExtraSections::answer($key, []);
  }

}

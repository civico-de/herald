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
    $sections['test.unrequested'] = 'never shown';
    $sections['test.seats'] = "Seats\n138 booked";
    $sections['test.empty'] = ' ';
  }

  public function testTheListenerAnswersWithItsContext(): void {
    $context = ['params' => ['period' => 'yesterday'], 'period' => 'yesterday'];
    $this->assertSame("Seats\n138 booked", ExtraSections::answer('test.seats', $context));
    $this->assertSame($context, $this->seenContext);
  }

  public function testAnEmptyAnswerIsNothingToReport(): void {
    $this->assertSame('', ExtraSections::answer('test.empty', []));
  }

  public function testAKeyNobodyAnswersFails(): void {
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessage('test.typo');
    ExtraSections::answer('test.typo', []);
  }

}

<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use PHPUnit\Framework\TestCase;

/**
 * collect() asks listeners through hook_civicrm_heraldDigestSections and keeps
 * only the requested sections, in the requested order.
 *
 * @group headless
 */
class ExtraSectionsCollectTest extends TestCase implements HeadlessInterface, HookInterface {

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
    if (in_array('test.queue', $keys, TRUE)) {
      $sections['test.queue'] = 'Queue: 4';
    }
  }

  public function testListenersAnswerInTheRequestedOrder(): void {
    $context = ['params' => ['period' => 'yesterday']];

    $sections = ExtraSections::collect(['test.queue', 'test.missing', 'test.seats'], $context);

    $this->assertSame(['Queue: 4', "Seats\n138 booked"], $sections);
    $this->assertSame($context, $this->seenContext);
  }

}

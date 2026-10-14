<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Test\HeadlessInterface;
use PHPUnit\Framework\TestCase;

/**
 * Headless: rule validation translates its messages and logs left-out rules.
 *
 * @group headless
 */
class SourceNormalizerTest extends TestCase implements HeadlessInterface {

  public function setUpHeadless() {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  public function testEmptyRulesLeaveSourcesUnchanged(): void {
    $normalizer = new SourceNormalizer([]);

    $this->assertSame('Bank TX 123', $normalizer->normalize('Bank TX 123'));
  }

  public function testRegexRuleMatches(): void {
    $normalizer = new SourceNormalizer([
      ['match' => '/^SEPA-[0-9]+$/', 'type' => 'regex', 'label' => 'SEPA'],
    ]);

    $this->assertSame('SEPA', $normalizer->normalize('SEPA-4711'));
    $this->assertSame('SEPA-other', $normalizer->normalize('SEPA-other'));
  }

  public function testFirstMatchingRuleWins(): void {
    $normalizer = new SourceNormalizer([
      ['match' => 'Bank', 'type' => 'contains', 'label' => 'First'],
      ['match' => 'Bank TX', 'type' => 'contains', 'label' => 'Second'],
    ]);

    $this->assertSame('First', $normalizer->normalize('Bank TX 123'));
  }

  public function testARuleTheAdminScreenWouldRefuseIsLeftOut(): void {
    $normalizer = new SourceNormalizer([
      ['match' => '/(unclosed', 'type' => 'regex', 'label' => 'Broken'],
      ['match' => 'Bank', 'type' => 'contains', 'label' => ''],
      'not a rule',
      ['match' => 'Bank', 'type' => 'prefix', 'label' => 'Bank'],
    ]);

    $this->assertSame('Bank', $normalizer->normalize('Bank TX 123'));
    $this->assertSame('(unclosed', $normalizer->normalize('(unclosed'));
  }

}

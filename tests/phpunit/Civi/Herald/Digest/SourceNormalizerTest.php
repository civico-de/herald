<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use PHPUnit\Framework\TestCase;

/**
 * @group unit
 */
class SourceNormalizerTest extends TestCase {

  public function testEmptyRulesLeaveSourcesUnchanged(): void {
    $normalizer = new SourceNormalizer([]);

    $this->assertFalse($normalizer->isEnabled());
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

}

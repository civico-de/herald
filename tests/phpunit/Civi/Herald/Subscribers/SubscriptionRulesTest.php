<?php

declare(strict_types = 1);

namespace Civi\Herald\Subscribers;

use PHPUnit\Framework\TestCase;

/**
 * What a submitted subscription row must satisfy.
 * @group unit
 */
class SubscriptionRulesTest extends TestCase {

  private const TYPES = ['ops_bot' => 'telegram', 'mail' => 'email', 'push' => 'pushover'];

  /**
   * @param array<string, mixed> $values Field name => submitted value
   */
  private static function errors(array $values): array {
    $record = [];
    foreach ($values as $name => $value) {
      $record[$name] = ["custom_{$name}_-1", $value];
    }
    return array_keys(SubscriptionRules::errors($record, self::TYPES));
  }

  public function testAHealthyRowPasses(): void {
    $this->assertSame(
      [],
      self::errors(['channel' => 'ops_bot', 'recipient' => '111', 'topics' => ['news' => 1], 'for_groups' => '']),
    );
    $this->assertSame(
      [],
      self::errors(['channel' => 'mail', 'recipient' => '', 'topics' => ['news'], 'for_groups' => '0']),
    );
    $this->assertSame(
      [],
      self::errors(['channel' => 'mail', 'recipient' => 'team@example.org', 'topics' => [], 'for_groups' => '1']),
    );
  }

  public function testANonEmailChannelNeedsARecipient(): void {
    $this->assertSame(
      ['custom_recipient_-1'],
      self::errors(['channel' => 'push', 'recipient' => ' ', 'topics' => ['news' => 1]]),
    );
  }

  public function testAnEnteredEmailAddressMustBeOne(): void {
    $this->assertSame(
      ['custom_recipient_-1'],
      self::errors(['channel' => 'mail', 'recipient' => 'team', 'topics' => ['news' => 1]]),
    );
  }

  public function testARowWithoutTopicsMustTakeGroupMessages(): void {
    $this->assertSame(
      ['custom_topics_-1'],
      self::errors(['channel' => 'mail', 'topics' => ['news' => ''], 'for_groups' => '0']),
    );
    $this->assertSame(['custom_topics_-1'], self::errors(['channel' => 'mail', 'topics' => '']));
  }

  public function testAnUnknownChannelIsLeftToTheOptionCheck(): void {
    $this->assertSame([], self::errors(['channel' => 'gone', 'recipient' => '', 'topics' => ['news' => 1]]));
  }

}

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
    $this->assertSame([], self::errors(['channel' => 'ops_bot', 'recipient' => '111', 'topics' => ['news' => 1]]));
    $this->assertSame([], self::errors(['channel' => 'mail', 'recipient' => '', 'topics' => ['news']]));
  }

  public function testAHealthyGroupChannelPasses(): void {
    $this->assertSame([], self::errors(['channel' => 'mail', 'recipient' => 'team@example.org']));
    $this->assertSame([], self::errors(['channel' => 'ops_bot', 'recipient' => '111']));
    $this->assertSame([], self::errors(['channel' => '', 'recipient' => '']));
  }

  public function testAGroupChannelOtherThanEmailNeedsARecipient(): void {
    $this->assertSame(['custom_recipient_-1'], self::errors(['channel' => 'ops_bot', 'recipient' => '']));
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

  public function testARowNeedsATopic(): void {
    $this->assertSame(['custom_topics_-1'], self::errors(['channel' => 'mail', 'topics' => ['news' => '']]));
    $this->assertSame(['custom_topics_-1'], self::errors(['channel' => 'mail', 'topics' => '']));
  }

  public function testAnUnknownChannelIsLeftToTheOptionCheck(): void {
    $this->assertSame([], self::errors(['channel' => 'gone', 'recipient' => '', 'topics' => ['news' => 1]]));
  }

  public function testAScopeNeedsTypeAndId(): void {
    $row = ['channel' => 'mail', 'topics' => ['news' => 1]];
    $this->assertSame([], self::errors($row + ['source_entity_type' => 'Event', 'source_entity_id' => '42']));
    $this->assertSame([], self::errors($row + ['source_entity_type' => '', 'source_entity_id' => '']));
    $this->assertSame(
      ['custom_source_entity_id_-1'],
      self::errors($row + ['source_entity_type' => 'Event', 'source_entity_id' => '']),
    );
    $this->assertSame(
      ['custom_source_entity_type_-1'],
      self::errors($row + ['source_entity_type' => '', 'source_entity_id' => '42']),
    );
  }

}

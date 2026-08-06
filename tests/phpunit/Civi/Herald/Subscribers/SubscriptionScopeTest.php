<?php

declare(strict_types = 1);

namespace Civi\Herald\Subscribers;

use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for source-entity subscription scoping.
 * @group unit
 */
class SubscriptionScopeTest extends TestCase {

  public function testNoScopeEntityParamMeansUnscoped(): void {
    $this->assertNull(SubscriptionScope::fromContext([], ['Event' => ['id' => 5]]));
    $this->assertNull(SubscriptionScope::fromContext(['scope_entity' => ''], ['Event' => ['id' => 5]]));
  }

  public function testScopeResolvedFromEntityPayload(): void {
    $this->assertSame(
      ['entity_type' => 'Event', 'entity_id' => 5],
      SubscriptionScope::fromContext(['scope_entity' => 'Event'], ['Event' => ['id' => 5]]),
    );
  }

  public function testEventScopeFallsBackToParticipantEventId(): void {
    $this->assertSame(
      ['entity_type' => 'Event', 'entity_id' => 7],
      SubscriptionScope::fromContext(['scope_entity' => 'Event'], ['Participant' => ['id' => 9, 'event_id' => 7]]),
    );
  }

  public function testScopeWithoutResolvableIdIsUnscoped(): void {
    $this->assertNull(SubscriptionScope::fromContext(['scope_entity' => 'Event'], []));
    $this->assertNull(SubscriptionScope::fromContext(['scope_entity' => 'Event'], ['Event' => []]));
  }

  public function testUnscopedRowMatchesEverything(): void {
    $row = [];
    $this->assertTrue(SubscriptionScope::rowMatches($row, NULL));
    $this->assertTrue(SubscriptionScope::rowMatches($row, ['entity_type' => 'Event', 'entity_id' => 5]));
  }

  public function testScopedRowMatchesOnlyItsEntityInstance(): void {
    $row = [
      'herald_preferences.source_entity_type' => 'Event',
      'herald_preferences.source_entity_id' => 5,
    ];
    $this->assertTrue(SubscriptionScope::rowMatches($row, ['entity_type' => 'Event', 'entity_id' => 5]));
    $this->assertFalse(SubscriptionScope::rowMatches($row, ['entity_type' => 'Event', 'entity_id' => 6]));
    $this->assertFalse(SubscriptionScope::rowMatches($row, ['entity_type' => 'Campaign', 'entity_id' => 5]));
    $this->assertFalse(SubscriptionScope::rowMatches($row, NULL));
  }

}

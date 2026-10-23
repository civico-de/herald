<?php

declare(strict_types = 1);

namespace Civi\Herald\Subscribers;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Source-entity scoping of per-contact subscriptions.
 *
 * A subscription may carry source_entity_type + source_entity_id
 * (e.g. Event 42): it then only fires for notifications that declare they
 * concern that entity instance. An unscoped subscription is global and
 * matches everything.
 */
final class SubscriptionScope {

  /**
   * Scope declared by the notification, from action params + trigger context.
   *
   * The action param `scope_entity` names a trigger-context entity; the id is
   * taken from that entity's payload. "Event" also resolves through a
   * Participant payload's event_id, so participant triggers can scope to
   * their event.
   *
   * @param array<string, mixed> $actionParams
   * @param array<string, mixed> $triggerContext
   * @return array{entity_type: string, entity_id: int}|null NULL = unscoped notification.
   */
  public static function fromContext(array $actionParams, array $triggerContext): ?array {
    $entityType = Cast::toString($actionParams['scope_entity'] ?? '');
    if ($entityType === '') {
      return NULL;
    }
    $id = self::id($triggerContext, $entityType, 'id');
    if ($id <= 0 && $entityType === 'Event') {
      $id = self::id($triggerContext, 'Participant', 'event_id');
    }
    return $id > 0 ? ['entity_type' => $entityType, 'entity_id' => $id] : NULL;
  }

  /**
   * Does a subscription's scope admit a notification with the given scope?
   *
   * Unscoped row → always yes. Scoped row → only when the notification
   * declares the same entity instance; an unscoped notification never reaches
   * scoped subscriptions.
   *
   * @param array{source_entity_type: string, source_entity_id: int, ...} $subscription
   * @param array{entity_type: string, entity_id: int}|null $scope
   */
  public static function rowMatches(array $subscription, ?array $scope): bool {
    $rowType = $subscription['source_entity_type'];
    $rowId = $subscription['source_entity_id'];
    if ($rowType === '' && $rowId <= 0) {
      return TRUE;
    }
    if ($scope === NULL) {
      return FALSE;
    }
    return $rowType === $scope['entity_type'] && $rowId === $scope['entity_id'];
  }

  /**
   * @param array<string, mixed> $triggerContext
   */
  private static function id(array $triggerContext, string $entity, string $field): int {
    return Cast::toInt(((array) ($triggerContext[$entity] ?? []))[$field] ?? 0);
  }

}

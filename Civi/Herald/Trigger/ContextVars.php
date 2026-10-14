<?php

declare(strict_types = 1);

namespace Civi\Herald\Trigger;

use Civi\Api4\Contribution;
use Civi\Api4\Membership;
use Civi\Api4\Participant;
use Civi\Herald\Util\StoredFields;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Template variables derived from a trigger's entity payload.
 *
 * The input is a plain context array — the CiviRules adapter unpacks the
 * trigger object into it, and nothing here knows CiviRules exists:
 *   contact_id     — int, 0 when the trigger carries no contact
 *   GroupContact   — entity payload of the "added to group" triggers
 *   Contribution, Membership, Participant, Event — entity payloads, when carried
 *   original       — pre-edit values keyed by entity name, when the trigger
 *                    recorded them (["Contribution" => ["contribution_status_id" => 2]])
 *
 * A contact or group that is gone leaves its fields empty; a lookup that
 * fails leaves its variables out and logs the error.
 */
final class ContextVars {

  private const CONTRIBUTION_FIELDS = [
    'contact_id',
    'total_amount',
    'currency',
    'contribution_status_id',
    'financial_type_id',
    'campaign_id',
    'source',
    'receive_date',
  ];

  private const MEMBERSHIP_FIELDS = ['contact_id', 'membership_type_id', 'status_id'];

  private const PARTICIPANT_FIELDS = ['contact_id', 'event_id', 'role_id', 'status_id'];

  /**
   * @param array<string, mixed> $context
   * @return array<string, mixed>
   */
  public static function build(array $context): array {
    $contribution = StoredFields::complete(
      Contribution::get(checkPermissions: FALSE),
      self::entity($context, 'Contribution'),
      self::CONTRIBUTION_FIELDS,
    );
    $membership = StoredFields::complete(
      Membership::get(checkPermissions: FALSE),
      self::entity($context, 'Membership'),
      self::MEMBERSHIP_FIELDS,
    );
    $participant = StoredFields::complete(
      Participant::get(checkPermissions: FALSE),
      self::entity($context, 'Participant'),
      self::PARTICIPANT_FIELDS,
    );
    $contactId = Cast::toInt($context['contact_id'] ?? 0);
    if ($contactId <= 0) {
      $contactId = Cast::toInt(
        $participant['contact_id'] ?? $membership['contact_id'] ?? $contribution['contact_id'] ?? 0,
      );
    }
    $vars = ContributionVars::forTrigger(
      $contribution,
      OriginalValues::field($context, 'Contribution', 'contribution_status_id'),
    );
    $groupId = Cast::toInt(self::entity($context, 'GroupContact')['group_id'] ?? 0);
    $vars += self::guarded('contact', ['contact_id' => $contactId], static fn () => ContactVars::contact($contactId));
    $vars += self::guarded('group', ['group_id' => $groupId], static fn () => ContactVars::group($groupId));
    $vars += self::guarded('membership', [], static fn () => ContactVars::membership($membership));
    $vars += self::guarded('participant', [], static fn () => EventVars::participant($participant));
    // A bare Event entity (the "Event is added / changed / deleted" triggers);
    // an event derived from a participant comes first.
    $vars += self::guarded('event', [], static fn () => EventVars::event(self::entity($context, 'Event')));
    return $vars;
  }

  /**
   * @param array<string, mixed> $context
   * @return array<array-key, mixed>
   */
  private static function entity(array $context, string $key): array {
    $value = $context[$key] ?? NULL;
    return is_array($value) ? $value : [];
  }

  /**
   * The variables $lookup returns, or none when it fails: one lookup that
   * fails leaves only its own variables out, and the error is logged.
   *
   * @param array<string, mixed> $logContext
   * @param callable(): array<string, mixed> $lookup
   * @return array<string, mixed>
   */
  private static function guarded(string $what, array $logContext, callable $lookup): array {
    try {
      return $lookup();
    }
    catch (\Throwable $e) {
      \Civi::log()->error("herald: could not resolve trigger {$what}", $logContext + ['error' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * The `vars` parameter: an array, a JSON object, or blank for none.
   *
   * @param array<string, mixed> $params
   * @return array<string, mixed> Template variable names to values.
   */
  public static function decodeVars(array $params): array {
    $raw = $params['vars'] ?? '';
    $decoded = is_string($raw) ? json_decode(trim($raw) === '' ? '{}' : $raw, TRUE) : $raw;
    // A list would lose its entries to the name filter.
    if (!is_array($decoded) || count(Cast::namedOnly($decoded)) !== count($decoded)) {
      throw new \CRM_Core_Exception('herald: vars must be a JSON object of template variables.');
    }
    return Cast::namedOnly($decoded);
  }

}

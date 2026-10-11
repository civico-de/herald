<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Maps channel configuration and a rendered message to Activity.create
 * values. Kept separate from transport orchestration so validation and option
 * resolution have one focused home.
 */
final class ActivityValues {

  /**
   * @param array<string, mixed> $config
   * @param (callable(string, string): int)|null $optionResolver
   * @return array<string, mixed>
   */
  public static function build(string $body, array $config, ?callable $optionResolver = NULL): array {
    $assigneeId = ActivityContactId::optional($config['activity_assignee_contact_id'] ?? NULL);
    if ($assigneeId === NULL) {
      throw new \CRM_Core_Exception(
        'herald: activity channel requires activity_assignee_contact_id.',
      );
    }

    $sourceId = ActivityContactId::optional($config['activity_source_contact_id'] ?? NULL) ?? $assigneeId;
    $targetId = ActivityContactId::optional($config['activity_target_contact_id'] ?? NULL);
    $format = Cast::toString($config['activity_format'] ?? 'html');

    $configuredSubject = trim(Cast::toString($config['activity_subject'] ?? ''));
    $subject = ActivityText::subject($configuredSubject === '' ? $body : $configuredSubject);

    $optionResolver ??= [ActivityOptionResolver::class, 'resolve'];
    $values = [
      'activity_type_id' => $optionResolver(
        'activity_type',
        self::nonEmptyString($config['activity_type'] ?? NULL, 'herald_notification'),
      ),
      'status_id' => $optionResolver(
        'activity_status',
        self::nonEmptyString($config['activity_status'] ?? NULL, 'Scheduled'),
      ),
      'source_contact_id' => $sourceId,
      'assignee_contact_id' => [$assigneeId],
      'subject' => $subject,
      'details' => ActivityText::details($body, $format),
    ];
    if ($targetId !== NULL) {
      $values['target_contact_id'] = [$targetId];
    }
    return $values;
  }

  private static function nonEmptyString(mixed $value, string $default): string {
    $value = trim(Cast::toString($value));
    return $value === '' ? $default : $value;
  }

}

<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

use CRM_Herald_ExtensionUtil as E;

/**
 * Internal CiviCRM activity channel.
 *
 * Unlike the transport channels, this keeps a notification inside CiviCRM as
 * an assigned activity. It is useful for operational work which must remain
 * visible and auditable even when no external notification transport is
 * configured.
 *
 * Per-call config:
 *   activity_assignee_contact_id  int     Assignee (required)
 *   activity_source_contact_id    int     Added-by contact (defaults to assignee)
 *   activity_target_contact_id    int     Optional contact the activity concerns
 *   activity_type                 string  Activity type option name
 *                                          (default herald_notification)
 *   activity_status               string  Activity status option name (default Scheduled)
 *   activity_subject              string  Subject (defaults to a body excerpt)
 *   activity_format               string  html (default) or text
 */
class ActivityChannel implements ChannelInterface {

  /**
   * @var (callable(array<string, mixed>): void)|null
   */
  private $creator;

  /**
   * @var (callable(string, string): int)|null
   */
  private $optionResolver;

  /**
   * @param (callable(array<string, mixed>): void)|null $creator
   *   Test seam replacing the APIv4 Activity.create call.
   * @param (callable(string, string): int)|null $optionResolver
   *   Test seam resolving an option-group name and option name to its value.
   */
  public function __construct(?callable $creator = NULL, ?callable $optionResolver = NULL) {
    $this->creator = $creator;
    $this->optionResolver = $optionResolver;
  }

  public function getKey(): string {
    return 'activity';
  }

  /**
   * @param array<string, mixed> $config
   */
  public function send(string $renderedBody, array $config): void {
    $values = ActivityValues::build($renderedBody, $config, $this->optionResolver);
    $creator = $this->creator ?? static function (array $activityValues): void {
      \Civi\Api4\Activity::create(checkPermissions: FALSE)
        ->setValues($activityValues)
        ->execute();
    };
    $creator($values);
  }

  /**
   * Build the Activity.create value bag. Option names are resolved rather
   * than treating numeric option values as stable across installations.
   *
   * @param array<string, mixed> $config
   * @param (callable(string, string): int)|null $optionResolver
   * @return array<string, mixed>
   */
  public static function buildActivityValues(string $body, array $config, ?callable $optionResolver = NULL): array {
    return ActivityValues::build($body, $config, $optionResolver);
  }

  /**
   * @return array<string, array{label: string, required: bool, type: string, options?: array<int|string, string>, default?: string, help?: string, scope?: 'channel'|'delivery', secret?: bool, recipient?: bool, bound_to?: string}>
   */
  public function getConfigSchema(): array {
    return [
      'activity_assignee_contact_id' => [
        'label' => E::ts('Assignee contact ID'),
        'required' => TRUE,
        'type' => 'int',
        'recipient' => TRUE,
      ],
      'activity_source_contact_id' => [
        'label' => E::ts('Added-by contact ID'),
        'required' => FALSE,
        'type' => 'int',
        'help' => E::ts('Defaults to the assignee contact.'),
      ],
      'activity_target_contact_id' => [
        'label' => E::ts('Related contact ID'),
        'required' => FALSE,
        'type' => 'int',
        'recipient' => TRUE,
      ],
      'activity_type' => [
        'label' => E::ts('Activity type name'),
        'required' => FALSE,
        'type' => 'string',
        'default' => 'herald_notification',
      ],
      'activity_status' => [
        'label' => E::ts('Activity status name'),
        'required' => FALSE,
        'type' => 'string',
        'default' => 'Scheduled',
      ],
      'activity_subject' => [
        'label' => E::ts('Subject'),
        'required' => FALSE,
        'type' => 'string',
      ],
      'activity_format' => [
        'label' => E::ts('Body format'),
        'required' => FALSE,
        'type' => 'enum',
        'options' => ['html', 'text'],
        'default' => 'html',
      ],
    ];
  }

  /**
   * @return array<string, string>
   */
  public function getSemanticFields(): array {
    return [
      'subject' => 'activity_subject',
      'format' => 'activity_format',
    ];
  }

}

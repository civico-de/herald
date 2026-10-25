<?php

declare(strict_types = 1);

namespace Civi\Herald\Subscribers;

use Civi\Afform\Event\AfformValidateEvent;
use Civi\Core\HookInterface;
use Civi\Core\Service\AutoService;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * The "Notifications" forms edit the group channel and the subscription rows
 * together: they replace core's tabs for both groups and check each entry
 * against what its channel needs before anything is saved.
 *
 * @service herald.notifications_form
 */
final class NotificationsForm extends AutoService implements HookInterface {

  public const FORMS = ['afformHeraldNotifications', 'afformHeraldMyNotifications'];

  /**
   * @param array<array-key, mixed> $tabs
   * @param array<array-key, mixed> $context
   */
  public function hook_civicrm_tabset(string $tabsetName, array &$tabs, array $context): void {
    if ($tabsetName !== 'civicrm/contact/view') {
      return;
    }
    $replaced = self::nativeTabs();
    $tabs = array_filter(
      $tabs,
      static fn (mixed $tab, int|string $key): bool => !in_array(
        is_array($tab) ? $tab['id'] ?? $key : $key,
        $replaced,
        TRUE,
      ),
      ARRAY_FILTER_USE_BOTH,
    );
  }

  public function on_civi_afform_validate(AfformValidateEvent $e): void {
    if (!in_array($e->getAfform()['name'] ?? NULL, self::FORMS, TRUE)) {
      return;
    }
    $types = ContactRows::subscribable();
    foreach (self::records($e->getSubmittedValues()) as $record) {
      array_map($e->addError(...), NotificationsRules::problems($record, $types));
    }
  }

  /**
   * @param array<array-key, mixed> $submitted Entity name => list of records
   * @return list<array<string, mixed>>
   */
  private static function records(array $submitted): array {
    $records = [];
    foreach ($submitted as $entityRecords) {
      foreach ((array) $entityRecords as $record) {
        $records[] = self::named($record);
      }
    }
    return $records;
  }

  /**
   * @return list<string>
   */
  private static function nativeTabs(): array {
    $ids = [];
    foreach ([Subscriptions::GROUP, Subscriptions::GROUP_DELIVERY] as $name) {
      $group = \CRM_Core_BAO_CustomGroup::getGroup(['name' => $name]);
      if ($group !== NULL) {
        $ids[] = 'custom_' . Cast::toInt($group['id']);
      }
    }
    return $ids;
  }

  /**
   * @return array<string, mixed>
   */
  private static function named(mixed $values): array {
    return is_array($values) ? Cast::namedOnly($values) : [];
  }

}

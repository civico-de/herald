<?php

declare(strict_types = 1);

namespace Civi\Herald\Admin;

use Civi\Core\HookInterface;
use Civi\Core\Service\AutoService;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * The admin screens beside the settings form: the links to them, read from the
 * Administer » Herald menu, and the SearchKit task that reactivates parked alerts.
 *
 * @service herald.admin_screens
 */
final class AdminScreens extends AutoService implements HookInterface {

  public const BASE_PATH = 'civicrm/admin/herald';

  public const DIGESTS_PATH = 'civicrm/admin/herald/digests';

  public const PARKED_ALERTS_PATH = 'civicrm/search#/display/Herald_Parked_Alerts/Herald_Parked_Alerts_Table';

  /**
   * Route of each screen in the heraldAdmin module => title.
   *
   * @return array<string, string>
   */
  public static function screens(): array {
    return [
      'channels' => E::ts('Channels'),
      'topics' => E::ts('Topics'),
      'cleaning' => E::ts('Cleaned fields'),
      'hosts' => E::ts('Webhook allowed hosts'),
      'monitor' => E::ts('Monitor checks'),
      'test' => E::ts('Test message'),
      'preview' => E::ts('Template preview'),
    ];
  }

  /**
   * The active entries of the Administer » Herald menu, in its order, so every
   * screen the menu offers is linked from the overview and the settings form.
   *
   * @return list<array{name: string, label: string, url: string, description: string}>
   */
  public static function links(): array {
    $descriptions = [
      'herald_settings' => E::ts('Single values such as the monitor topic, its thresholds and the retry limits.'),
      'herald_channels' => E::ts('The bots, accounts and senders herald sends through.'),
      'herald_topics' => E::ts('Where each topic is delivered.'),
      'herald_cleaning' => E::ts('Rules that turn free text into clean groups for searches and notifications.'),
      'herald_hosts' => E::ts('Which hosts the webhook channel may call.'),
      'herald_monitor' => E::ts('Check keys, threshold, snoozes and expected extensions.'),
      'herald_test' => E::ts('Send a message through a channel or a topic.'),
      'herald_preview' => E::ts('Render a herald template with sample values.'),
      'herald_digests' => E::ts('Summaries from searches and sections, sent on a schedule.'),
      'herald_parked_alerts' => E::ts('Alerts the flush job could not deliver, and their reactivation.'),
    ];
    $items = \Civi\Api4\Navigation::get(checkPermissions: FALSE)
      ->addSelect('name', 'label', 'url')
      ->addWhere('parent_id.name', '=', 'herald')
      ->addWhere('is_active', '=', TRUE)
      ->addWhere('domain_id', '=', 'current_domain')
      ->addOrderBy('weight')
      ->execute();
    $links = [];
    /** @var array{name: mixed, label: mixed, url: mixed} $item */
    foreach ($items as $item) {
      $name = Cast::toString($item['name']);
      $links[] = [
        'name' => $name,
        'label' => Cast::toString($item['label']),
        'url' => self::absolute(Cast::toString($item['url'])),
        'description' => $descriptions[$name] ?? '',
      ];
    }
    return $links;
  }

  /**
   * Menu entry names per overview group; the everyday screens come first.
   *
   * @return array<string, array{title: string, names: list<string>}>
   */
  private static function groups(): array {
    return [
      'setup' => [
        'title' => E::ts('Set up'),
        'names' => ['herald_channels', 'herald_topics', 'herald_digests'],
      ],
      'operations' => [
        'title' => E::ts('Test and operate'),
        'names' => ['herald_monitor', 'herald_test', 'herald_preview', 'herald_parked_alerts'],
      ],
      'advanced' => [
        'title' => E::ts('Advanced'),
        'names' => ['herald_settings', 'herald_cleaning', 'herald_hosts'],
      ],
    ];
  }

  /**
   * Settings of the heraldAdmin Angular module: the menu entries in their overview groups, in menu order.
   * An entry no group names, such as one another extension adds, goes under Advanced.
   *
   * @return array{groups: list<array{name: string, title: string, links: list<array{name: string, label: string, url: string, description: string}>}>}
   */
  public static function angularSettings(): array {
    $links = self::links();
    $groups = self::groups();
    $named = array_merge(...array_values(array_column($groups, 'names')));
    $result = [];
    foreach ($groups as $name => $group) {
      $result[] = [
        'name' => $name,
        'title' => $group['title'],
        'links' => array_values(array_filter(
          $links,
          static fn (array $link): bool => (
            in_array($link['name'], $group['names'], TRUE)
            || $name === 'advanced'
            && !in_array($link['name'], $named, TRUE)
          ),
        )),
      ];
    }
    return ['groups' => $result];
  }

  /**
   * The overview lists its screens like core's admin console, which brings this stylesheet itself.
   */
  public function hook_civicrm_pageRun(\CRM_Core_Page $page): void {
    if ($page instanceof \Civi\Angular\Page\Main && \CRM_Utils_System::currentPath() === self::BASE_PATH) {
      \Civi::resources()->addStyleFile('civicrm', 'css/admin.css');
    }
  }

  /**
   * The settings form shows the values most sites set first; thresholds and limits follow under Advanced.
   */
  public function hook_civicrm_preProcess(string $formName, \CRM_Core_Form $form): void {
    if ($form instanceof \CRM_Admin_Form_Generic && $form->getSettingPageFilter() === 'herald') {
      $form->sections['advanced'] = [
        'title' => E::ts('Advanced'),
        'description' => E::ts('Thresholds and limits whose defaults suit most sites.'),
        'weight' => 10,
      ];
    }
  }

  public function hook_civicrm_buildForm(string $formName, \CRM_Core_Form $form): void {
    if (!$form instanceof \CRM_Admin_Form_Generic || $form->getSettingPageFilter() !== 'herald') {
      return;
    }
    $overview = \CRM_Utils_System::url(self::BASE_PATH, 'reset=1', htmlize: FALSE);
    \CRM_Core_Region::instance('crm-setting-form-herald-top')->add([
      'markup' =>
        '<p class="help"><a href="'
          . htmlspecialchars($overview)
          . '">'
          . htmlspecialchars(E::ts('Channels, topics, digests and the other herald screens'))
          . '</a></p>',
    ]);
  }

  /**
   * A menu URL such as civicrm/admin/herald#/topics as a link on this site.
   */
  private static function absolute(string $url): string {
    $parts = parse_url($url);
    return \CRM_Utils_System::url(
      $parts['path'] ?? '',
      $parts['query'] ?? '',
      FALSE,
      $parts['fragment'] ?? NULL,
      FALSE,
    );
  }

  /**
   * @param array<string, array<string, array<string, mixed>>> $tasks
   */
  public function hook_civicrm_searchKitTasks(array &$tasks): void {
    $tasks['HeraldBufferedAlert']['herald_reactivate'] = [
      'title' => E::ts('Reactivate parked alerts'),
      'icon' => 'fa-repeat',
      'apiBatch' => [
        'action' => 'reactivate',
        // NULL, not []: an empty array reaches the browser as a JSON list, which drops the id filter.
        'params' => NULL,
        'confirmMsg' => E::ts('Send %1 %2 back to the flush job with a fresh retry budget?'),
        'runMsg' => E::ts('Reactivating %1 %2...'),
        'successMsg' => E::ts('Reactivated %1 %2.'),
        'errorMsg' => E::ts('An error occurred while reactivating %1 %2.'),
      ],
    ];
  }

}

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
      'topics' => E::ts('Topic routes'),
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
      'herald_monitor' => E::ts('Expected checks and snoozes.'),
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
   * Settings of the heraldAdmin Angular module.
   *
   * @return array{links: list<array{name: string, label: string, url: string, description: string}>}
   */
  public static function angularSettings(): array {
    return ['links' => self::links()];
  }

  /**
   * The overview lists its screens like core's admin console, which brings this stylesheet itself.
   */
  public function hook_civicrm_pageRun(\CRM_Core_Page $page): void {
    if ($page instanceof \Civi\Angular\Page\Main && \CRM_Utils_System::currentPath() === self::BASE_PATH) {
      \Civi::resources()->addStyleFile('civicrm', 'css/admin.css');
    }
  }

  public function hook_civicrm_buildForm(string $formName, \CRM_Core_Form $form): void {
    if (!$form instanceof \CRM_Admin_Form_Generic || $form->getSettingPageFilter() !== 'herald') {
      return;
    }
    $items = '';
    foreach (self::links() as $link) {
      if ($link['name'] === 'herald_settings') {
        continue;
      }
      $items .=
        '<li><a href="' . htmlspecialchars($link['url']) . '">' . htmlspecialchars($link['label']) . '</a></li>';
    }
    \CRM_Core_Region::instance('crm-setting-form-herald-top')->add([
      'markup' =>
        '<nav class="help" aria-labelledby="herald-admin-links"><p id="herald-admin-links">'
          . htmlspecialchars(E::ts('Lists and maps of these settings are edited on their own screens:'))
          . '</p><ul>'
          . $items
          . '</ul></nav>',
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

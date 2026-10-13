<?php

declare(strict_types = 1);

namespace Civi\Herald\Admin;

use Civi\Core\HookInterface;
use Civi\Core\Service\AutoService;
use CRM_Herald_ExtensionUtil as E;

/**
 * The admin screens beside the settings form: their links on that form, and
 * the SearchKit task that reactivates parked alerts.
 *
 * @service herald.admin_screens
 */
final class AdminScreens extends AutoService implements HookInterface {

  public const BASE_PATH = 'civicrm/admin/herald';

  public const PARKED_ALERTS_PATH = 'civicrm/search#/display/Herald_Parked_Alerts/Herald_Parked_Alerts_Table';

  /**
   * Route of each screen in the heraldAdmin module => title.
   *
   * @return array<string, string>
   */
  public static function screens(): array {
    return [
      'topics' => E::ts('Topic routes'),
      'sources' => E::ts('Contribution source names'),
      'hosts' => E::ts('Webhook allowed hosts'),
      'monitor' => E::ts('Monitor checks'),
      'test' => E::ts('Test message'),
      'preview' => E::ts('Template preview'),
    ];
  }

  public function hook_civicrm_buildForm(string $formName, \CRM_Core_Form $form): void {
    if (!$form instanceof \CRM_Admin_Form_Generic || $form->getSettingPageFilter() !== 'herald') {
      return;
    }
    $items = '';
    foreach (self::screens() as $route => $title) {
      $url = \CRM_Utils_System::url(self::BASE_PATH, '', FALSE, '/' . $route);
      $items .= '<li><a href="' . $url . '">' . htmlspecialchars($title) . '</a></li>';
    }
    $parked = \CRM_Utils_System::url(
      'civicrm/search',
      '',
      FALSE,
      '/display/Herald_Parked_Alerts/Herald_Parked_Alerts_Table',
    );
    $items .= '<li><a href="' . $parked . '">' . htmlspecialchars(E::ts('Parked alerts')) . '</a></li>';
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

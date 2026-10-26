<?php

declare(strict_types = 1);

namespace Civi\Herald\Admin;

use Civi\Api4\HeraldBufferedAlert;
use Civi\Api4\Navigation;
use Civi\Api4\SearchDisplay;
use Civi\Herald\Buffer\AlertBuffer;

/**
 * Wiring of the admin screens: route, Angular module, menu, settings-page
 * links, and the parked-alerts reactivate task.
 *
 * @group headless
 */
class AdminScreensTest extends AdminTestCase {

  public function testTheRouteLoadsTheModuleForAdministrators(): void {
    $item = \CRM_Core_Menu::get(AdminScreens::BASE_PATH);
    $this->assertSame('\\Civi\\Angular\\Page\\Main', $item['page_callback']);
    $this->assertSame([['administer CiviCRM'], 'and'], $item['access_arguments']);

    $angular = \Civi::service('angular');
    $this->assertSame(
      ['heraldAdmin'],
      array_values(array_filter(
        $angular->resolveDefaultModules(AdminScreens::BASE_PATH),
        static fn (string $m) => str_starts_with($m, 'herald'),
      )),
    );
    $partials = $angular->getPartials('heraldAdmin');
    foreach (array_merge(array_keys(AdminScreens::screens()), ['index', 'topic', 'fields']) as $page) {
      $this->assertArrayHasKey("~/heraldAdmin/{$page}.html", $partials);
    }
  }

  public function testTheMenuGroupsEveryScreen(): void {
    $parent = (int) \CRM_Core_DAO::singleValueQuery("SELECT id FROM civicrm_navigation WHERE name = 'herald'");
    $this->assertGreaterThan(0, $parent);
    $children = \CRM_Core_DAO::executeQuery(
      'SELECT name, label, url, permission FROM civicrm_navigation WHERE parent_id = %1 ORDER BY weight',
      [1 => [$parent, 'Integer']],
    )->fetchAll();
    $this->assertSame(
      [
        'herald_settings',
        'herald_channels',
        'herald_topics',
        'herald_cleaning',
        'herald_hosts',
        'herald_monitor',
        'herald_test',
        'herald_preview',
        'herald_digests',
        'herald_parked_alerts',
      ],
      array_column($children, 'name'),
    );
    $this->assertSame(['administer CiviCRM'], array_values(array_unique(array_column($children, 'permission'))));
    $screens = array_slice($children, 1, -2);
    $this->assertSame(array_values(AdminScreens::screens()), array_column($screens, 'label'));
    $this->assertSame(
      array_map(
        static fn (string $route) => AdminScreens::BASE_PATH . '#/' . $route,
        array_keys(AdminScreens::screens()),
      ),
      array_column($screens, 'url'),
    );
    $this->assertSame(
      [AdminScreens::DIGESTS_PATH . '?reset=1', AdminScreens::PARKED_ALERTS_PATH],
      array_column(array_slice($children, -2), 'url'),
    );
  }

  public function testTheOverviewGroupsEveryMenuEntry(): void {
    $groups = AdminScreens::angularSettings()['groups'];
    $this->assertSame(
      [
        'setup' => ['herald_channels', 'herald_topics', 'herald_digests'],
        'operations' => ['herald_monitor', 'herald_test', 'herald_preview', 'herald_parked_alerts'],
        'advanced' => ['herald_settings', 'herald_cleaning', 'herald_hosts'],
      ],
      array_map(
        static fn (array $group): array => array_column($group['links'], 'name'),
        array_column($groups, NULL, 'name'),
      ),
    );
    $links = array_column(array_merge(...array_column($groups, 'links')), NULL, 'name');
    $this->assertStringEndsWith('civicrm/admin/herald#/channels', $links['herald_channels']['url']);
    $this->assertMatchesRegularExpression(
      '#civicrm/admin/herald/digests[?&]reset=1$#',
      $links['herald_digests']['url'],
    );
    // Each entry is a dt with its dd; a bare dt breaks the definition list.
    $this->assertNotContains('', array_column($links, 'description'));
  }

  public function testAMenuEntryNoGroupNamesIsListedUnderAdvanced(): void {
    Navigation::create(FALSE)
      ->setValues([
        'name' => 'herald_from_elsewhere',
        'label' => 'From elsewhere',
        'url' => 'civicrm/elsewhere',
        'parent_id.name' => 'herald',
        'domain_id' => 'current_domain',
        'weight' => 99,
      ])
      ->execute();
    $advanced = array_column(AdminScreens::angularSettings()['groups'], NULL, 'name')['advanced'];
    $this->assertSame('herald_from_elsewhere', end($advanced['links'])['name']);
  }

  public function testTheOverviewLoadsTheAdminConsoleStyles(): void {
    $loaded = static function (string $path): bool {
      $_GET['q'] = $path;
      \Civi::service('herald.admin_screens')->hook_civicrm_pageRun(new \Civi\Angular\Page\Main());
      return \CRM_Core_Region::instance(\CRM_Core_Resources::DEFAULT_REGION)->get('civicrm:css/admin.css') !== NULL;
    };
    try {
      $this->assertFalse($loaded('civicrm/admin/other'));
      $this->assertTrue($loaded(AdminScreens::BASE_PATH));
    }
    finally {
      unset($_GET['q']);
    }
  }

  public function testTheSettingsPageLinksTheOverview(): void {
    $form = new \CRM_Admin_Form_Generic();
    $form->urlPath = ['civicrm', 'admin', 'setting', 'herald'];
    \Civi::service('herald.admin_screens')->hook_civicrm_buildForm('CRM_Admin_Form_Generic', $form);
    $html = \CRM_Core_Region::instance('crm-setting-form-herald-top')->render('');
    $this->assertMatchesRegularExpression('#<a href="[^"]*civicrm/admin/herald(\?|&amp;)reset=1">#', $html);

    $other = new \CRM_Admin_Form_Generic();
    $other->urlPath = ['civicrm', 'admin', 'setting', 'smtp'];
    \Civi::service('herald.admin_screens')->hook_civicrm_buildForm('CRM_Admin_Form_Generic', $other);
    $this->assertSame('', \CRM_Core_Region::instance('crm-setting-form-smtp-top')->render(''));
  }

  public function testTheSettingsPagePutsThresholdsAndLimitsUnderAdvanced(): void {
    $form = new \CRM_Admin_Form_Generic();
    $form->urlPath = ['civicrm', 'admin', 'setting', 'herald'];
    \Civi::service('herald.admin_screens')->hook_civicrm_preProcess('CRM_Admin_Form_Generic', $form);
    $this->assertSame('Advanced', $form->sections['advanced']['title']);

    $sections = [];
    $onPage = array_filter(
      \Civi\Core\SettingsMetadata::getMetadata(['group' => 'herald']),
      static fn (array $setting): bool => isset($setting['settings_pages']['herald']),
    );
    foreach ($onPage as $name => $setting) {
      $sections[$setting['settings_pages']['herald']['section'] ?? 'default'][] = $name;
    }
    $this->assertSame(['herald_contact_email_channel', 'herald_monitor_topic'], $sections['default']);
    $this->assertCount(6, $sections['advanced']);
  }

  public function testTheParkedAlertsDisplayOffersOnlyReactivation(): void {
    $tasks = SearchDisplay::getSearchTasks(checkPermissions: FALSE)
      ->setSavedSearch('Herald_Parked_Alerts')
      ->setDisplay('Herald_Parked_Alerts_Table')
      ->execute()
      ->getArrayCopy();
    $this->assertSame(['herald_reactivate'], array_column($tasks, 'name'));
    $this->assertSame('reactivate', $tasks[0]['apiBatch']['action']);
    $this->assertNull($tasks[0]['apiBatch']['params']);
  }

  public function testReactivateSendsOnlySelectedParkedRowsBack(): void {
    \CRM_Core_DAO::executeQuery('DELETE FROM civicrm_herald_buffer');
    $buffer = new AlertBuffer();
    foreach (['a', 'b', 'c'] as $group) {
      $buffer->add("grp-{$group}", 5, 'body', ['channel' => 'email']);
    }
    \CRM_Core_DAO::executeQuery(
      "UPDATE civicrm_herald_buffer SET parked_at = NOW(), park_reason = 'no_recipient', attempts = 3
       WHERE group_hash IN ('grp-a', 'grp-b')",
    );
    $ids = array_column(
      \CRM_Core_DAO::executeQuery('SELECT id, group_hash FROM civicrm_herald_buffer')->fetchAll(),
      'id',
      'group_hash',
    );

    $rows = self::call(
      'reactivate',
      ['where' => [['id', 'IN', [(int) $ids['grp-a'], (int) $ids['grp-c']]]]],
      'HeraldBufferedAlert',
    );
    $reactivated = array_column($rows, 'id');
    sort($reactivated);
    $this->assertSame([(int) $ids['grp-a'], (int) $ids['grp-c']], $reactivated);
    $this->assertSame(['grp-b'], $this->parkedGroups());
    $this->assertSame(0, (int) \CRM_Core_DAO::singleValueQuery(
      "SELECT attempts FROM civicrm_herald_buffer WHERE group_hash = 'grp-a'",
    ));

    // A selection that matches nothing must not fall back to "every parked row".
    $this->assertSame([], self::call('reactivate', ['where' => [['id', '=', 0]]], 'HeraldBufferedAlert'));
    $this->assertSame(['grp-b'], $this->parkedGroups());

    $this->assertAdminOnly(static fn () => HeraldBufferedAlert::reactivate()->addWhere('id', '>', 0)->execute());
  }

  /**
   * @return list<string>
   */
  private function parkedGroups(): array {
    return array_column(
      \CRM_Core_DAO::executeQuery(
        'SELECT group_hash FROM civicrm_herald_buffer WHERE parked_at IS NOT NULL ORDER BY group_hash',
      )->fetchAll(),
      'group_hash',
    );
  }

}

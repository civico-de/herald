<?php

declare(strict_types = 1);

namespace Civi\Herald;

use Civi\Test\HeadlessInterface;
use PHPUnit\Framework\TestCase;

/**
 * civicrm/admin/setting/herald renders every setting placed on the "herald"
 * settings page; array settings have no generic form field.
 *
 * @group headless
 */
class SettingsPageTest extends TestCase implements HeadlessInterface {

  /**
   * html_type of the token settings: a masked input.
   */
  private const MASKED_INPUT = 'password';

  public function setUpHeadless() {
    return \Civi\Test::headless()->install('org.civicoop.civirules')->installMe(__DIR__)->apply();
  }

  public function testEveryScalarSettingIsOnTheAdminPage(): void {
    $settings = array_filter(
      \Civi\Core\SettingsMetadata::getMetadata(),
      static fn (array $spec): bool => ($spec['group'] ?? '') === 'herald',
    );
    $this->assertNotEmpty($settings);
    foreach ($settings as $name => $spec) {
      $onPage = isset($spec['settings_pages']['herald']);
      $this->assertSame($spec['type'] !== 'Array', $onPage, $name);
    }
  }

  public function testTokenFieldsOptOutOfLoginAutofill(): void {
    $passwords = array_filter(
      \Civi\Core\SettingsMetadata::getMetadata(),
      static fn (array $spec): bool => ($spec['group'] ?? '') === 'herald' && ($spec['html_type'] ?? '') === self::MASKED_INPUT,
    );
    $this->assertNotEmpty($passwords);
    foreach ($passwords as $name => $spec) {
      $this->assertSame('new-password', $spec['html_attributes']['autocomplete'] ?? NULL, $name);
    }
  }

  public function testTheAdminRouteUsesTheGenericSettingsForm(): void {
    $this->assertSame(
      'CRM_Admin_Form_Generic',
      \CRM_Core_Menu::get('civicrm/admin/setting/herald')['page_callback'] ?? NULL,
    );
  }

}

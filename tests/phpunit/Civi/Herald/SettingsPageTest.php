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
    return \Civi\Herald\HeadlessSite::standard()->apply();
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

  /**
   * Secrets are entered on the channel screen; a browser must not fill them with a login.
   */
  public function testSecretFieldsOptOutOfLoginAutofill(): void {
    $template = (string) file_get_contents(\CRM_Herald_ExtensionUtil::path('ang/heraldAdmin/fields.html'));
    $this->assertMatchesRegularExpression(
      '/ng-switch-when="secret">.*?type="password"\s+autocomplete="new-password"/s',
      $template,
    );
    $passwords = array_filter(
      \Civi\Core\SettingsMetadata::getMetadata(),
      static fn (array $spec): bool => (
        ($spec['group'] ?? '') === 'herald'
        && ($spec['html_type'] ?? '') === self::MASKED_INPUT
      ),
    );
    $this->assertSame([], $passwords, 'secrets live in herald_channels, not in settings of their own');
  }

  public function testTheAdminRouteUsesTheGenericSettingsForm(): void {
    $this->assertSame(
      'CRM_Admin_Form_Generic',
      \CRM_Core_Menu::get('civicrm/admin/setting/herald')['page_callback'] ?? NULL,
    );
  }

}

<?php

declare(strict_types = 1);

namespace Civi\Herald\Admin;

use Civi\Test\HeadlessInterface;
use PHPUnit\Framework\TestCase;

/**
 * @group headless
 */
class AfformStringsTest extends TestCase implements HeadlessInterface {

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  public function testTheDigestFormSpeaksTheSiteLanguage(): void {
    \CRM_Core_I18n::singleton()->setLocale('de_DE');
    try {
      $html = self::digestForm();
    }
    finally {
      \CRM_Core_I18n::singleton()->setLocale('en_US');
    }
    self::assertStringContainsString('af-title="Quellen"', $html);
    self::assertStringContainsString('af-repeat="Quelle hinzufügen"', $html);
    self::assertStringContainsString('Kleinbuchstaben, Ziffern und Unterstriche.', $html);
  }

  public function testAnEnglishSiteKeepsTheMarkup(): void {
    self::assertStringContainsString('af-repeat="Add source"', self::digestForm());
  }

  private static function digestForm(): string {
    $partials = (new \Civi\Angular\Manager(\CRM_Core_Resources::singleton()))->getPartials('afformHeraldDigest');
    return $partials['~/afformHeraldDigest/afformHeraldDigest.aff.html'];
  }

}

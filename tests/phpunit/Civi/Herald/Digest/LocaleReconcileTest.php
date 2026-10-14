<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Test\HeadlessInterface;
use CRM_Herald_ExtensionUtil as E;
use PHPUnit\Framework\TestCase;

/**
 * A reconcile in another language leaves the placeholders of the shipped
 * displays alone. Not transactional: a reconcile commits.
 *
 * @group headless
 */
class LocaleReconcileTest extends TestCase implements HeadlessInterface {

  public function setUpHeadless() {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  public function testAReconcileInAnotherLanguageKeepsThePlaceholders(): void {
    $display = (int) \Civi\Api4\SearchDisplay::get(checkPermissions: FALSE)
      ->addWhere('name', '=', 'Herald_Digest_Contributions_Yesterday_Table')
      ->execute()
      ->single()['id'];
    $before = SearchSource::placeholders($display);
    try {
      self::reconcileIn('de_DE');
      self::assertSame($before, SearchSource::placeholders($display));
    }
    finally {
      self::reconcileIn('en_US');
    }
  }

  private static function reconcileIn(string $locale): void {
    \CRM_Core_I18n::singleton()->setLocale($locale);
    try {
      \CRM_Core_ManagedEntities::singleton(TRUE)->reconcile([E::LONG_NAME]);
    }
    finally {
      \CRM_Core_I18n::singleton()->setLocale('en_US');
    }
  }

}

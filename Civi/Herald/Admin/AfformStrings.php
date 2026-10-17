<?php

declare(strict_types = 1);

namespace Civi\Herald\Admin;

use Civi\Afform\StringVisitor;
use Civi\Angular\ChangeSet;
use Civi\Angular\Manager;
use Civi\Core\HookInterface;
use Civi\Core\Service\AutoService;
use CRM_Herald_ExtensionUtil as E;

/**
 * Afform translates form markup only in CiviCRM's own domain, so herald's
 * forms translate their titles, buttons and help texts in the herald domain.
 *
 * @service herald.afform_strings
 */
final class AfformStrings extends AutoService implements HookInterface {

  public const FORMS = ';/af(form|search)Herald\w*\.aff\.html$;';

  public function hook_civicrm_alterAngular(Manager $angular): void {
    $angular->add(ChangeSet::create('herald_strings')->alterHtml(
      self::FORMS,
      self::translateForm(...),
    ));
  }

  /**
   * Untyped natively: phpQuery comes with core and is not autoloadable on its own.
   *
   * @param \phpQueryObject $doc
   */
  private static function translateForm($doc): void {
    (new StringVisitor())->visit($doc, self::translate(...));
  }

  /**
   * StringVisitor also passes where the string sits, which ts() would take as params.
   */
  private static function translate(string $text): string {
    return E::ts($text);
  }

}

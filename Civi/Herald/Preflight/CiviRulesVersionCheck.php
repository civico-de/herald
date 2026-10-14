<?php

declare(strict_types = 1);

namespace Civi\Herald\Preflight;

use Civi\Core\HookInterface;
use Civi\Core\Service\AutoService;
use CRM_Herald_CivirulesAdapter_Availability as Availability;
use CRM_Herald_ExtensionUtil as E;

/**
 * System check: herald reads CiviRules through APIv4 and relies on it
 * unserializing rule params, which CiviRules does from MIN_VERSION.
 *
 * @service herald.civirules_version_check
 */
final class CiviRulesVersionCheck extends AutoService implements HookInterface {

  public const NAME = 'herald_civirules_too_old';

  public const MIN_VERSION = '3.36';

  /**
   * @param array<int, \CRM_Utils_Check_Message> $messages
   */
  public function hook_civicrm_check(array &$messages): void {
    if (!Availability::isInstalled()) {
      return;
    }
    $message = self::message(
      (string) \CRM_Extension_System::singleton()->getMapper()->keyToInfo(Availability::EXTENSION)->version,
    );
    if ($message !== NULL) {
      $messages[] = $message;
    }
  }

  public static function message(string $version): ?\CRM_Utils_Check_Message {
    if (version_compare($version, self::MIN_VERSION, '>=')) {
      return NULL;
    }
    return new \CRM_Utils_Check_Message(
      self::NAME,
      E::ts('Herald needs CiviRules %1 or later, this site runs %2. Update CiviRules.', [
        1 => self::MIN_VERSION,
        2 => htmlspecialchars($version),
      ]),
      E::ts('Herald: CiviRules too old'),
      // PSR-3 level; Psr\Log\LogLevel is only a transitive dependency here.
      'error',
      'fa-puzzle-piece',
    );
  }

}

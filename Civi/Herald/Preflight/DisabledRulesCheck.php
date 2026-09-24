<?php

declare(strict_types = 1);

namespace Civi\Herald\Preflight;

use Civi\Core\HookInterface;
use Civi\Core\Service\AutoService;
use CRM_Herald_ExtensionUtil as E;

/**
 * System check: the deploy marker exists, so herald's rules are switched off. Reads
 * only the marker file and loads no rule handler class.
 *
 * @service herald.disabled_rules_check
 */
final class DisabledRulesCheck extends AutoService implements HookInterface {

  public const NAME = 'herald_rules_disabled_by_deploy';

  /**
   * @param array<int, \CRM_Utils_Check_Message> $messages
   */
  public function hook_civicrm_check(array &$messages): void {
    if (!is_file(DeployGate::marker())) {
      return;
    }
    $messages[] = new \CRM_Utils_Check_Message(
      self::NAME,
      E::ts(
        'A deploy switched off the Herald CiviRules rules %1 and did not switch them back on, so they send no alerts. Fix what failed the deploy gate, then run: %2',
        [
          1 => implode(', ', DeployGate::markedRuleIds()),
          2 => '<code>cv scr ' . htmlspecialchars(E::path('bin/rules-reenable.php')) . '</code>',
        ],
      ),
      E::ts('Herald: rules disabled by a deploy'),
      // PSR-3 level; Psr\Log\LogLevel is only a transitive dependency here.
      'warning',
      'fa-bell-slash',
    );
  }

}

<?php

declare(strict_types = 1);

namespace Civi\Herald\Preflight;

use Civi\Herald\Util\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * Deploy gate: herald's registered handlers and the handlers of the rules the deploy
 * disabled must pass {@see HandlerContract}; other active rules are only noted, later.
 */
final class DeployGate {

  /**
   * Holds the ids of the herald rules a deploy disabled, comma-separated; the
   * path bin/rules-disable.php writes. Private files outlive a container restart.
   */
  public static function marker(): string {
    return Cast::toString(\Civi::paths()->getPath('[civicrm.private]/herald_deploy_disabled_rules'));
  }

  /**
   * @return list<int>
   */
  public static function markedRuleIds(): array {
    $marker = self::marker();
    $ids = is_file($marker) ? explode(',', trim((string) file_get_contents($marker))) : [];
    return array_values(array_filter(array_map('intval', $ids)));
  }

  /**
   * Checks the gating handlers, writes herald's rules with a dormant trigger to
   * $notes, and returns how many handlers were checked.
   *
   * @param resource $notes
   * @throws \CRM_Core_Exception naming every gating failure and how to re-enable herald's rules
   */
  public static function run($notes): int {
    $marked = self::markedRuleIds();
    $gating = RuleHandlers::ofRules($marked) + RuleHandlers::registered();
    $dormant = RuleHandlers::dormant($marked);
    if ($dormant !== []) {
      fwrite(
        $notes,
        "[herald preflight] note, not gating: herald's rules "
        . implode(', ', $dormant)
        . " have a missing or inactive trigger; CiviRules never fires them\n",
      );
    }
    $failed = self::broken($gating);
    if ($failed === []) {
      return count($gating);
    }
    $message =
      count($failed) . " handler(s) of herald's rules fail the CiviRules contract:\n" . self::describe($failed);
    if ($marked !== []) {
      $message .=
        "\nherald's rules "
        . implode(', ', $marked)
        . ' stay disabled. After the fix, re-enable them with: cv scr '
        . E::path('bin/rules-reenable.php');
    }
    throw new \CRM_Core_Exception($message);
  }

  /**
   * Writes the broken handlers of active rules outside herald's registered ones to
   * $notes and returns how many were checked. Loads foreign classes: run it only
   * after herald's rules are re-enabled, since a class that cannot load is a fatal.
   *
   * @param resource $notes
   */
  public static function noteOthers($notes): int {
    $others = array_diff_key(RuleHandlers::active(), RuleHandlers::registered());
    $noted = self::broken($others);
    if ($noted !== []) {
      fwrite(
        $notes,
        "[herald preflight] note, not gating: active rules have unusable handlers:\n" . self::describe($noted) . "\n",
      );
    }
    return count($others);
  }

  /**
   * One line per broken handler: rule ids, kind, class and reason.
   *
   * @param list<array{kind: string, class: string, rule_ids: list<int>, problem: string}> $broken
   */
  public static function describe(array $broken): string {
    $lines = [];
    foreach ($broken as $entry) {
      $rules = $entry['rule_ids'] === [] ? 'registered, no rule' : 'rule ' . implode(', ', $entry['rule_ids']);
      $class = $entry['class'] === '' ? '(no class)' : $entry['class'];
      $lines[] = "{$rules}: {$entry['kind']} {$class} — {$entry['problem']}";
    }
    return implode("\n", $lines);
  }

  /**
   * @param array<string, array{kind: string, class: string, rule_ids: list<int>, problem: ?string}> $handlers
   * @return list<array{kind: string, class: string, rule_ids: list<int>, problem: string}>
   */
  private static function broken(array $handlers): array {
    $broken = [];
    foreach ($handlers as $handler) {
      $problem = $handler['problem'] ?? HandlerContract::inspect($handler['kind'], $handler['class']);
      if ($problem !== NULL) {
        $broken[] = ['problem' => $problem] + $handler;
      }
    }
    return $broken;
  }

}

<?php

namespace Civi\Herald;

/**
 * Loadability guard for herald's CiviRules handler classes.
 *
 * Motivation (production, 2026-07): a herald condition class that failed to
 * implement an abstract method of its CiviRules base class produced a PHP
 * load-time FATAL. Because CiviRules evaluates rules synchronously inside the
 * write path, every Event edit 500'd — including the public booking cart —
 * until the rule was disabled by hand. `php -l` does not catch this (it never
 * resolves the class against its parent), and a load-fatal is not catchable
 * with try/catch.
 *
 * This class centralises the check "is every handler class herald ships
 * actually loadable and instantiable?" so three call sites share one source of
 * truth:
 *   - a CI contract test (fails the build before anything ships),
 *   - the deploy preflight (bin/preflight-rules.php, aborts the deploy),
 *   - hook_civicrm_check → surfaced through the pulse health endpoint.
 *
 * NOTE on the fatal: scan()/problem() call class_exists(), which triggers
 * autoload and therefore the very load-fatal we guard against. They are safe to
 * call ONLY where a fatal-means-abort is the desired outcome (deploy, CI, the
 * enable hook — all of which then leave the rules disabled). The runtime hot
 * path (the pulse check) must read the cached stored() result instead, never
 * scan().
 */
class RuleHealth {

  private const SETTING = 'herald_rule_health';

  /**
   * Every condition/action class herald registers with CiviRules, read from
   * the two registration manifests (no class loading here).
   *
   * @return list<string>
   */
  public static function registeredHandlerClasses(): array {
    $root = dirname(__DIR__, 2);
    $classes = [];
    foreach (['civirules_conditions.json', 'civirules_actions.json'] as $manifest) {
      $path = $root . DIRECTORY_SEPARATOR . $manifest;
      if (!is_file($path)) {
        continue;
      }
      $entries = json_decode((string) file_get_contents($path), TRUE);
      foreach (is_array($entries) ? $entries : [] as $entry) {
        $className = is_array($entry) ? ($entry['class_name'] ?? NULL) : NULL;
        if (!empty($className) && is_scalar($className)) {
          $classes[] = (string) $className;
        }
      }
    }
    return array_values(array_unique($classes));
  }

  /**
   * Why a class is unusable as a CiviRules handler, or NULL if it is fine.
   *
   * WARNING: triggers autoload — see the class-level note. Do not call on the
   * pulse hot path.
   */
  public static function problem(string $class): ?string {
    if (!class_exists($class)) {
      return 'class not found (autoload failed)';
    }
    $reflection = new \ReflectionClass($class);
    if (!$reflection->isInstantiable()) {
      return 'not instantiable (abstract, interface, or an abstract method left unimplemented)';
    }
    return NULL;
  }

  /**
   * Check every registered handler class.
   *
   * WARNING: triggers autoload of each class — a load-fatal aborts the process.
   * That is intentional in deploy/CI contexts. Returns the broken ones keyed by
   * class → reason; empty means healthy.
   *
   * @return array<string, string>
   */
  public static function scan(): array {
    $broken = [];
    foreach (self::registeredHandlerClasses() as $class) {
      $problem = self::problem($class);
      if ($problem !== NULL) {
        $broken[$class] = $problem;
      }
    }
    return $broken;
  }

  /**
   * Scan and cache the result into a setting, so the pulse check can read it
   * without ever loading a (possibly broken) class.
   *
   * @return array<string, string> The broken map (empty = healthy).
   */
  public static function refresh(): array {
    $broken = self::scan();
    \Civi::settings()->set(self::SETTING, $broken);
    return $broken;
  }

  /**
   * The last cached scan result. Cheap; loads no handler classes.
   *
   * @return array<string, string>
   */
  public static function stored(): array {
    $value = \Civi::settings()->get(self::SETTING);
    $broken = [];
    // Written by refresh() as class => reason; re-narrowed on read because a
    // setting is untyped storage that an admin can also have edited by hand.
    foreach (is_array($value) ? $value : [] as $class => $reason) {
      if (is_string($class) && is_scalar($reason)) {
        $broken[$class] = (string) $reason;
      }
    }
    return $broken;
  }

}

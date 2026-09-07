<?php

declare(strict_types = 1);

namespace Civi\Herald;

/**
 * Loadability guard for herald's CiviRules handler classes. An unimplemented
 * abstract method is a load-time fatal that `php -l` misses and try/catch
 * cannot catch; CiviRules would then 500 every triggering write.
 *
 * scan()/problem() trigger autoload and thus that fatal: call them only where
 * abort is acceptable (CI, deploy preflight, enable hook). The runtime check
 * path reads stored().
 * @mago-expect lint:cyclomatic-complexity
 */
class RuleHealth {

  private const SETTING = 'herald_rule_health';

  /**
   * Handler classes from both manifests; loads no class.
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
        $className = is_array($entry) ? $entry['class_name'] ?? NULL : NULL;
        if (!empty($className) && is_scalar($className)) {
          $classes[] = (string) $className;
        }
      }
    }
    return array_values(array_unique($classes));
  }

  /**
   * Why a class is unusable as a CiviRules handler, or NULL. Triggers autoload.
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
   * Broken handler classes keyed by class → reason. Triggers autoload.
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
   * @return array<string, string> The broken map (empty = healthy).
   */
  public static function refresh(): array {
    $broken = self::scan();
    \Civi::settings()->set(self::SETTING, $broken);
    return $broken;
  }

  /**
   * The last cached scan result; loads no handler classes.
   *
   * @return array<string, string>
   */
  public static function stored(): array {
    $value = \Civi::settings()->get(self::SETTING);
    $broken = [];
    // Re-narrowed on read: a setting is untyped storage an admin may have edited.
    foreach (is_array($value) ? $value : [] as $class => $reason) {
      if (!(is_string($class) && is_scalar($reason))) {
        continue;
      }

      $broken[$class] = (string) $reason;
    }
    return $broken;
  }

}

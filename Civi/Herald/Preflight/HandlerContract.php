<?php

declare(strict_types = 1);

namespace Civi\Herald\Preflight;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * How CiviRules resolves a handler: a trigger row to its class, then class_exists,
 * `new $class()` without arguments and an instanceof check against its kind's base.
 */
final class HandlerContract {

  private const POST_TRIGGER = 'CRM_Civirules_Trigger_Post';

  private const TRIGGER_FIELDS = [
    'trigger_id',
    'trigger_id.class_name',
    'trigger_id.object_name',
    'trigger_id.op',
    'trigger_id.cron',
  ];

  public const BASES = [
    'condition' => 'CRM_Civirules_Condition',
    'action' => 'CRM_Civirules_Action',
    'trigger' => 'CRM_Civirules_Trigger',
  ];

  /**
   * Why CiviRules could not use $class as a $kind handler, or NULL. Loads the class in
   * this process: a parse error is reported, a compile-time fatal ends the process.
   */
  public static function inspect(string $kind, string $class): ?string {
    $base = self::BASES[$kind] ?? NULL;
    if ($base === NULL) {
      return "unknown handler kind '{$kind}'";
    }
    try {
      if (!class_exists($class)) {
        return 'class not found (autoload failed)';
      }
    }
    catch (\Throwable $e) {
      return 'class failed to load: ' . get_class($e) . ': ' . $e->getMessage();
    }
    return self::violation(new \ReflectionClass($class), $base);
  }

  /**
   * @param \ReflectionClass<object> $reflection
   */
  public static function violation(\ReflectionClass $reflection, string $base): ?string {
    if (!$reflection->isInstantiable()) {
      return 'not instantiable (abstract, interface, or an abstract method left unimplemented)';
    }
    if (!$reflection->isSubclassOf($base)) {
      return "does not extend {$base}";
    }
    if (($reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0) {
      return 'constructor requires arguments; CiviRules instantiates handlers without any';
    }
    try {
      $reflection->newInstance();
    }
    catch (\Throwable $e) {
      return 'constructor threw ' . get_class($e) . ': ' . $e->getMessage();
    }
    return NULL;
  }

  /**
   * The class CiviRulesTrigger::getTriggerObjectByTriggerId() loads for a
   * rule's active trigger, or why it loads none.
   *
   * @param array<array-key, mixed> $rule
   * @return array{string, ?string}
   */
  public static function trigger(array $rule): array {
    $rule += array_fill_keys(self::TRIGGER_FIELDS, NULL);
    $id = Cast::toInt($rule['trigger_id']);
    $class = Cast::toString($rule['trigger_id.class_name']);
    $post =
      Cast::toString($rule['trigger_id.object_name']) !== ''
      && Cast::toString($rule['trigger_id.op']) !== ''
      && $rule['trigger_id.cron'] !== TRUE;
    return match (TRUE) {
      $post && $class === '' => [self::POST_TRIGGER, NULL],
      $class !== '' => [$class, NULL],
      default => ['', "trigger {$id} names no class and is not a post trigger"],
    };
  }

}

<?php

declare(strict_types = 1);

use Civi\Herald\RuleHealth;
use PHPUnit\Framework\TestCase;

/**
 * Contract test for herald's CiviRules handler classes.
 *
 * A handler missing an abstract method of its CiviRules base is a load-time
 * fatal that 500s every write; `php -l` cannot catch it, resolving the class
 * against its parent does.
 *
 * It works as a pure @group unit test because the bootstrap registers the
 * CiviRules base-class autoloader (see tests/phpunit/bootstrap.php).
 *
 * @group unit
 */
class CRM_Herald_RuleContractTest extends TestCase {

  public function testEveryRegisteredHandlerIsInstantiable(): void {
    $classes = RuleHealth::registeredHandlerClasses();
    $this->assertNotEmpty($classes, 'no handler classes registered — manifests missing?');

    foreach ($classes as $class) {
      // problem() loads the class against its parent; a broken class either
      // returns a reason (interface/abstract/unimplemented-abstract that still
      // loads) or, for a true load-fatal, aborts this process with the class
      // named in the fatal — both fail the build, which is the point.
      $this->assertNull(
        RuleHealth::problem($class),
        "Registered CiviRules handler {$class} is not a usable handler: " . (RuleHealth::problem($class) ?? ''),
      );
    }
  }

  public function testProblemFlagsAMissingClass(): void {
    $this->assertNotNull(RuleHealth::problem('CRM_Herald_This_Class_Does_Not_Exist'));
  }

  public function testProblemFlagsANonInstantiableClass(): void {
    // A built-in abstract class loads fine but is not instantiable — the same
    // category as "abstract method left unimplemented", detected without a
    // load-fatal.
    $this->assertNotNull(RuleHealth::problem(\SplHeap::class));
  }

  public function testGoodClassPasses(): void {
    $this->assertNull(RuleHealth::problem(RuleHealth::class));
  }

}

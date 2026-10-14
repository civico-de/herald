<?php

declare(strict_types = 1);

namespace Civi\Herald\Trigger;

use PHPUnit\Framework\TestCase;

/**
 * How ContextVars reads the `vars` parameter.
 * @group unit
 */
class ContextVarsTest extends TestCase {

  public function testEmptyParamsYieldEmptyVars(): void {
    $this->assertSame([], ContextVars::decodeVars([]));
  }

  public function testEmptyStringYieldsEmptyVars(): void {
    $this->assertSame([], ContextVars::decodeVars(['vars' => '']));
    $this->assertSame([], ContextVars::decodeVars(['vars' => '   ']));
  }

  public function testJsonStringIsDecoded(): void {
    $vars = ContextVars::decodeVars([
      'vars' => '{"contact":{"display_name":"Foo Bar"},"amount":42.5}',
    ]);
    $this->assertSame('Foo Bar', $vars['contact']['display_name']);
    $this->assertSame(42.5, $vars['amount']);
  }

  public function testArrayPassthrough(): void {
    $vars = ContextVars::decodeVars([
      'vars' => ['foo' => 'bar', 'n' => 1],
    ]);
    $this->assertSame(['foo' => 'bar', 'n' => 1], $vars);
  }

  /**
   * Dropping broken vars would send the message with blanks where the
   * caller's values belong.
   */
  public function testVarsThatAreNoJsonObjectFail(): void {
    foreach (['not-json', '42', '"text"', 42, '["ops"]'] as $raw) {
      try {
        ContextVars::decodeVars(['vars' => $raw]);
        $this->fail('vars ' . var_export($raw, TRUE) . ' must fail');
      }
      catch (\CRM_Core_Exception $e) {
        $this->assertStringContainsString('vars must be a JSON object', $e->getMessage());
      }
    }
  }

}

<?php

declare(strict_types = 1);

namespace Civi\Herald\Trigger;

use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for ContextVars::decodeVars.
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

  public function testInvalidJsonCollapsesToEmpty(): void {
    $this->assertSame(
      [],
      ContextVars::decodeVars([
        'vars' => 'not-json',
      ]),
    );
  }

  public function testJsonScalarCollapsesToEmpty(): void {
    // json_decode("42", true) returns 42, not an array — must collapse.
    $this->assertSame(
      [],
      ContextVars::decodeVars([
        'vars' => '42',
      ]),
    );
  }

}

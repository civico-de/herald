<?php

declare(strict_types = 1);

use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for SendNotification::decodeVars.
 * @group unit
 */
class CRM_Herald_CivirulesActions_SendNotificationTest extends TestCase {

  public function testEmptyParamsYieldEmptyVars(): void {
    $this->assertSame([], CRM_Herald_CivirulesActions_SendNotification::decodeVars([]));
  }

  public function testEmptyStringYieldsEmptyVars(): void {
    $this->assertSame([], CRM_Herald_CivirulesActions_SendNotification::decodeVars(['vars' => '']));
    $this->assertSame([], CRM_Herald_CivirulesActions_SendNotification::decodeVars(['vars' => '   ']));
  }

  public function testJsonStringIsDecoded(): void {
    $vars = CRM_Herald_CivirulesActions_SendNotification::decodeVars([
      'vars' => '{"contact":{"display_name":"Foo Bar"},"amount":42.5}',
    ]);
    $this->assertSame('Foo Bar', $vars['contact']['display_name']);
    $this->assertSame(42.5, $vars['amount']);
  }

  public function testArrayPassthrough(): void {
    $vars = CRM_Herald_CivirulesActions_SendNotification::decodeVars([
      'vars' => ['foo' => 'bar', 'n' => 1],
    ]);
    $this->assertSame(['foo' => 'bar', 'n' => 1], $vars);
  }

  public function testInvalidJsonCollapsesToEmpty(): void {
    $this->assertSame(
      [],
      CRM_Herald_CivirulesActions_SendNotification::decodeVars([
        'vars' => 'not-json',
      ]),
    );
  }

  public function testJsonScalarCollapsesToEmpty(): void {
    // json_decode("42", true) returns 42, not an array — must collapse.
    $this->assertSame(
      [],
      CRM_Herald_CivirulesActions_SendNotification::decodeVars([
        'vars' => '42',
      ]),
    );
  }

}

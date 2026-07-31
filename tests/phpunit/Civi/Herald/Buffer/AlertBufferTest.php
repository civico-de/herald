<?php

namespace Civi\Herald\Buffer;

use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for AlertBuffer::compose.
 * @group unit
 */
class AlertBufferTest extends TestCase {

  public function testSingleBodyPassesThroughUnchanged(): void {
    $body = "🎟 Ticket verkauft: 60,00 €\nQuelle: Warenkorb";
    $this->assertSame($body, AlertBuffer::compose([$body], 10, [60.0]));
  }

  public function testEmptyBufferComposesToEmptyString(): void {
    $this->assertSame('', AlertBuffer::compose([], 10));
    $this->assertSame('', AlertBuffer::compose(['', '  '], 10));
  }

  public function testMultipleBodiesGetCountAndSumHeader(): void {
    $msg = AlertBuffer::compose(
      ['🎟 Ticket verkauft: 60,00 €', '🎟 Ticket verkauft: 35,00 €', '🎟 Ticket verkauft: 200,00 €'],
      15,
      [60.0, 35.0, 200.0]
    );
    // English source strings, and no assertion on the formatted total: money
    // formatting follows the instance locale/currency, which is en_US in the
    // headless run and de_DE in production.
    $this->assertStringStartsWith('📬 <b>3 messages</b>, total <b>', $msg);
    $this->assertStringContainsString('(collected over 15 min)', $msg);
    $this->assertStringContainsString('35,00 €', $msg);
  }

  public function testSumOmittedWithoutAmounts(): void {
    $msg = AlertBuffer::compose(['a', 'b'], 5, []);
    $this->assertStringStartsWith('📬 <b>2 messages</b> (collected over 5 min)', $msg);
    $this->assertStringNotContainsString('total', $msg);
  }

  public function testBlankBodiesAreDroppedFromCountAndOutput(): void {
    $msg = AlertBuffer::compose(['x', '', 'y'], 5);
    $this->assertStringStartsWith('📬 <b>2 messages</b>', $msg);
  }

}

<?php

namespace Civi\Herald\Buffer;

use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for AlertBuffer::compose and the narrowing helpers.
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
      [60.0, 35.0, 200.0],
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

  // --- narrowing helpers ----------------------------------------------------

  /**
   * str()/int()/flt() are private statics used only on the claimDue() DB path,
   * and every column they touch is declared scalar in createTable() — so the
   * non-scalar branch they exist for cannot be reached from any public call
   * (AlertBufferClaimDueTest covers the reachable half against real rows).
   * Reflection is the proportionate way to pin the documented contract of that
   * unreachable branch; building a fake DAO just to hand it an array would be
   * more machinery than the two lines under test.
   */
  private static function narrow(string $method, mixed $value): mixed {
    return (new \ReflectionMethod(AlertBuffer::class, $method))->invoke(NULL, $value);
  }

  public function testNarrowingHelpersConvertScalarDaoValues(): void {
    // What a DAO actually hands over: everything as a string.
    $this->assertSame('42', self::narrow('str', 42));
    $this->assertSame(42, self::narrow('int', '42'));
    $this->assertSame(60.5, self::narrow('flt', '60.50'));
  }

  /**
   * The point of the helpers: a non-scalar collapses to the DOCUMENTED default
   * rather than to PHP's cast of an array — which would put the literal string
   * "Array" into an outgoing alert body, count a garbage row id as 1, and turn
   * an unknown amount into 1.00 € in the aggregate sum.
   */
  public function testNonScalarsCollapseToTheDocumentedDefault(): void {
    foreach ([[], ['x'], NULL, new \stdClass()] as $value) {
      $this->assertSame('', self::narrow('str', $value));
      $this->assertSame(0, self::narrow('int', $value));
      $this->assertSame(0.0, self::narrow('flt', $value));
    }
  }

}

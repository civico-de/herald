<?php

declare(strict_types = 1);

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

  // --- capping an oversized aggregate ---------------------------------------

  public function testItemCapPrintsTheFirstNAndNamesTheRest(): void {
    $msg = AlertBuffer::compose(array_map(static fn (int $i) => "alert {$i}", range(1, 10)), 5, [], 3);
    $this->assertStringStartsWith('📬 <b>10 messages</b>', $msg, 'the header keeps the true total');
    $this->assertStringContainsString('alert 3', $msg);
    $this->assertStringNotContainsString('alert 4', $msg);
    $this->assertStringEndsWith('… and 7 more', $msg);
  }

  public function testItemCapIsInactiveBelowTheThreshold(): void {
    $msg = AlertBuffer::compose(['a', 'b'], 5, [], 25);
    $this->assertStringEndsWith("a\n\nb", $msg);
    $this->assertStringNotContainsString('more', $msg);
  }

  public function testZeroItemCapPrintsEverything(): void {
    $msg = AlertBuffer::compose(array_map(static fn (int $i) => "alert {$i}", range(1, 40)), 5, [], 0);
    $this->assertStringContainsString('alert 40', $msg);
  }

  /**
   * The production incident this cap exists for: 893 status transitions landed
   * in one five-minute window and composed a 124 KB message that Telegram
   * rejected outright, so the alert retried and failed once a minute.
   */
  public function testBulkOperationComposesIntoAReadableMessage(): void {
    $bodies = array_fill(0, 893, '🎟 <b>Ticket verkauft</b>: 60,00 €');
    $msg = AlertBuffer::compose($bodies, 5, array_fill(0, 893, 60.0), 25);
    $this->assertStringStartsWith('📬 <b>893 messages</b>', $msg);
    $this->assertStringEndsWith('… and 868 more', $msg);
    $this->assertLessThan(4096, \Civi\Herald\Channel\MessageLimit::length($msg));
  }

  // --- delivery params of the composed message ------------------------------

  /**
   * Every buffered row carries the subject its own event rendered, so an
   * aggregate that kept one of them would be titled after a single donation.
   */
  public function testAggregateGetsASubjectDescribingTheAggregate(): void {
    $delivery = AlertBuffer::composeDelivery(
      ['channel' => 'email', 'email_to' => 'ops@example.org', 'email_subject' => '✅ Donation #5'],
      ['🎟 one', '🎟 two', '🎟 three'],
      15,
      [60.0, 35.0, 200.0],
    );

    $this->assertStringStartsWith('📬 3 messages, total ', $delivery['email_subject']);
    $this->assertStringContainsString('(collected over 15 min)', $delivery['email_subject']);
    // A subject is not markup.
    $this->assertStringNotContainsString('<b>', $delivery['email_subject']);
    // Everything that routes the message stays untouched.
    $this->assertSame('ops@example.org', $delivery['email_to']);
    $this->assertSame('email', $delivery['channel']);
  }

  /**
   * A single buffered alert goes out unchanged — including the subject its own
   * event rendered.
   */
  public function testSingleBodyKeepsItsOwnSubject(): void {
    $delivery = AlertBuffer::composeDelivery(
      ['email_subject' => '✅ Donation #5'],
      ['🎟 one', '  '],
      15,
      [60.0],
    );
    $this->assertSame('✅ Donation #5', $delivery['email_subject']);
  }

  public function testAggregateSubjectOmitsTheSumWithoutAmounts(): void {
    $delivery = AlertBuffer::composeDelivery([], ['a', 'b'], 5);
    $this->assertSame('📬 2 messages (collected over 5 min)', $delivery['email_subject']);
  }

  // --- serialisation of the delivery params ---------------------------------

  /**
   * A delivery blob that cannot be serialised must abort the buffering: an
   * empty replacement would flush the alert to the channel defaults — another
   * chat, another inbox — instead of to its configured recipient.
   */
  public function testUnserialisableDeliveryParamsAreRefused(): void {
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/could not serialise/');
    // INF has no JSON representation; the encode fails before any INSERT.
    (new AlertBuffer())->add('herald-test-encode', 5, 'body', ['chat_id' => '-100', 'retry_after' => INF]);
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

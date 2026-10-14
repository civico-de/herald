<?php

declare(strict_types = 1);

namespace Civi\Herald\Buffer;

use Civi\Test\HeadlessInterface;
use PHPUnit\Framework\TestCase;

/**
 * AggregateSummary::compose and AlertBuffer's narrowing helpers. Headless:
 * the header is translated and the sum formatted by CiviCRM.
 *
 * @group headless
 */
class AlertBufferTest extends TestCase implements HeadlessInterface {

  public function setUpHeadless() {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  public function testSingleBodyPassesThroughUnchanged(): void {
    $body = "🎟 Ticket verkauft: 60,00 €\nQuelle: Warenkorb";
    $this->assertSame($body, AggregateSummary::compose([$body], 10, [60.0]));
  }

  public function testEmptyBufferComposesToEmptyString(): void {
    $this->assertSame('', AggregateSummary::compose([], 10));
    $this->assertSame('', AggregateSummary::compose(['', '  '], 10));
  }

  public function testMultipleBodiesGetCountAndSumHeader(): void {
    $msg = AggregateSummary::compose(
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
    $msg = AggregateSummary::compose(['a', 'b'], 5, []);
    $this->assertStringStartsWith('📬 <b>2 messages</b> (collected over 5 min)', $msg);
    $this->assertStringNotContainsString('total', $msg);
  }

  public function testBlankBodiesAreDroppedFromCountAndOutput(): void {
    $msg = AggregateSummary::compose(['x', '', 'y'], 5);
    $this->assertStringStartsWith('📬 <b>2 messages</b>', $msg);
  }

  // --- capping an oversized aggregate ---------------------------------------

  public function testItemCapPrintsTheFirstNAndNamesTheRest(): void {
    $msg = AggregateSummary::compose(array_map(static fn (int $i) => "alert {$i}", range(1, 10)), 5, [], 3);
    $this->assertStringStartsWith('📬 <b>10 messages</b>', $msg, 'the header keeps the true total');
    $this->assertStringContainsString('alert 3', $msg);
    $this->assertStringNotContainsString('alert 4', $msg);
    $this->assertStringEndsWith('… and 7 more', $msg);
  }

  public function testItemCapIsInactiveBelowTheThreshold(): void {
    $msg = AggregateSummary::compose(['a', 'b'], 5, [], 25);
    $this->assertStringEndsWith("a\n\nb", $msg);
    $this->assertStringNotContainsString('more', $msg);
  }

  public function testZeroItemCapPrintsEverything(): void {
    $msg = AggregateSummary::compose(array_map(static fn (int $i) => "alert {$i}", range(1, 40)), 5, [], 0);
    $this->assertStringContainsString('alert 40', $msg);
  }

  /**
   * The production incident this cap exists for: 893 status transitions landed
   * in one five-minute window and composed a 124 KB message that Telegram
   * rejected outright, so the alert retried and failed once a minute.
   */
  public function testBulkOperationComposesIntoAReadableMessage(): void {
    $bodies = array_fill(0, 893, '🎟 <b>Ticket verkauft</b>: 60,00 €');
    $msg = AggregateSummary::compose($bodies, 5, array_fill(0, 893, 60.0), 25);
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
    $delivery = AggregateSummary::composeDelivery(
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
    $delivery = AggregateSummary::composeDelivery(
      ['email_subject' => '✅ Donation #5'],
      ['🎟 one', '  '],
      15,
      [60.0],
    );
    $this->assertSame('✅ Donation #5', $delivery['email_subject']);
  }

  public function testAggregateSubjectOmitsTheSumWithoutAmounts(): void {
    $delivery = AggregateSummary::composeDelivery([], ['a', 'b'], 5);
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

}

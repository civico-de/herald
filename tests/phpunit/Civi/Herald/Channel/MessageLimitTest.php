<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for fitting a body into a transport limit.
 * @group unit
 */
class MessageLimitTest extends TestCase {

  public function testLengthCountsUtf16CodeUnits(): void {
    $this->assertSame(5, MessageLimit::length('hello'));
    // Emoji outside the BMP are a surrogate pair — one character, two units.
    $this->assertSame(2, MessageLimit::length('📬'));
    $this->assertSame(3, MessageLimit::length('ä📬'));
  }

  public function testHtmlTextLengthIgnoresMarkup(): void {
    $this->assertSame(5, MessageLimit::htmlTextLength('<b>hello</b>'));
    $this->assertSame(5, MessageLimit::htmlTextLength('<a href="https://example.org/x">hello</a>'));
  }

  public function testHtmlTextLengthCountsAnEntityAsOneCharacter(): void {
    $this->assertSame(3, MessageLimit::htmlTextLength('a&amp;b'));
  }

  public function testShortBodyIsUntouched(): void {
    $this->assertSame('hello', MessageLimit::fitHtml('hello', 4096, 'cut'));
  }

  public function testResultNeverExceedsTheLimit(): void {
    $text = implode("\n\n", array_fill(0, 500, '🎟 <b>Ticket verkauft</b>: 60,00 €'));
    $fitted = MessageLimit::fitHtml($text, 4096, 'cut');
    // The parsed length is what the transport enforces; the raw source is
    // longer because the markup rides along for free.
    $this->assertLessThanOrEqual(4096, MessageLimit::htmlTextLength($fitted));
    $this->assertStringEndsWith("\n\ncut", $fitted);
  }

  /**
   * Markup must not eat the budget: 5000 rendered characters dressed as
   * "<b>x</b>" have to keep nearly 4096 of them, not the ~500 a raw-measured
   * budget would leave.
   */
  public function testHtmlBudgetIsSpentOnTextNotMarkup(): void {
    $fitted = MessageLimit::fitHtml(str_repeat('<b>x</b>', 5000), 4096, 'cut');
    $this->assertLessThanOrEqual(4096, MessageLimit::htmlTextLength($fitted));
    $this->assertGreaterThan(3900, MessageLimit::htmlTextLength($fitted));
  }

  public function testEntitiesCostOneUnitOfTheHtmlBudget(): void {
    $fitted = MessageLimit::fitHtml(str_repeat('&amp;', 500), 100, 'cut');
    $this->assertLessThanOrEqual(100, MessageLimit::htmlTextLength($fitted));
    // 5 raw characters per unit: a raw budget would have kept a fifth of these.
    $this->assertGreaterThan(80, substr_count($fitted, '&amp;'));
  }

  public function testCutFallsOnAParagraphBoundary(): void {
    $text = implode("\n\n", array_fill(0, 50, str_repeat('x', 40)));
    $fitted = MessageLimit::fitText($text, 200, 'cut');
    $body = substr($fitted, 0, strrpos($fitted, "\n\ncut"));
    // Every retained entry is whole: no partial 40-character block survives.
    foreach (explode("\n\n", $body) as $part) {
      $this->assertSame(str_repeat('x', 40), $part);
    }
  }

  public function testOpenTagsAreClosedAgain(): void {
    $text = "<b>one</b>\n\n<b>two " . str_repeat('long ', 200);
    $fitted = MessageLimit::fitHtml($text, 120, 'cut');
    $this->assertSame(
      substr_count($fitted, '<b>'),
      substr_count($fitted, '</b>'),
      'every opened tag is closed again',
    );
  }

  /**
   * @dataProvider supportedTagProvider
   */
  public function testEverySupportedTagIsClosedAgain(string $open, string $tag): void {
    $fitted = MessageLimit::fitHtml("head\n\n{$open}" . str_repeat('x ', 200), 60, 'cut');
    $this->assertSame(
      1,
      substr_count($fitted, "</{$tag}>"),
      "an open <{$tag}> left by the cut has to be closed",
    );
  }

  /**
   * @return array<string, array{string, string}>
   */
  public static function supportedTagProvider(): array {
    $tags = [
      'b', 'strong', 'i', 'em', 'u', 'ins', 's', 'strike', 'del',
      'code', 'pre', 'a', 'blockquote', 'tg-spoiler', 'tg-emoji',
    ];
    $cases = ['span' => ['<span class="tg-spoiler">', 'span']];
    foreach ($tags as $tag) {
      $cases[$tag] = ["<{$tag}>", $tag];
    }
    return $cases;
  }

  public function testTagFragmentAtTheCutIsDropped(): void {
    $fitted = MessageLimit::fitHtml('hello world <b' . str_repeat('!', 200), 20, 'cut');
    $this->assertStringNotContainsString('<b', $fitted);
  }

  /**
   * A well-formed reference is carried whole by the budget walk, so only a
   * MALFORMED one (no semicolon, therefore indistinguishable from text) can
   * still be split — and half of it must not reach the parser.
   */
  public function testMalformedEntityFragmentAtTheCutIsDropped(): void {
    $fitted = MessageLimit::fitHtml(str_repeat('x', 18) . '&amp' . str_repeat('y', 200), 25, 'cut');
    $this->assertStringStartsWith(str_repeat('x', 18), $fitted);
    $this->assertStringNotContainsString('&', $fitted, 'half an entity is neither markup nor text');
  }

  public function testCompleteEntityIsNeverSplit(): void {
    $fitted = MessageLimit::fitHtml(str_repeat('x', 18) . '&amp;' . str_repeat('y', 200), 25, 'cut');
    $this->assertStringContainsString('&amp;', $fitted, 'a reference is carried whole or not at all');
  }

  /**
   * A query string carries ampersands and no semicolon, but they sit inside a
   * completed tag — mistaking one for a truncated entity would cut the link
   * in half and produce exactly the malformed HTML this guard prevents.
   */
  public function testAmpersandInsideACompletedTagIsNotAFragment(): void {
    $link = '<a href="https://example.org/x?a=1&b=2">report</a>';
    $fitted = MessageLimit::fitHtml($link . "\n\n" . str_repeat('y', 300), 80, 'cut');
    $this->assertStringContainsString('?a=1&b=2', $fitted);
    $this->assertStringContainsString('</a>', $fitted);
  }

  public function testPlainTextIsNotGivenClosingTags(): void {
    $fitted = MessageLimit::fitText("<b>a\n\n" . str_repeat('b', 300), 40, 'cut');
    $this->assertStringNotContainsString('</b>', $fitted);
  }

  public function testBodyWithoutAnyBreakIsStillCut(): void {
    $fitted = MessageLimit::fitText(str_repeat('x', 5000), 100, 'cut');
    $this->assertLessThanOrEqual(100, MessageLimit::length($fitted));
    $this->assertStringEndsWith('cut', $fitted);
  }

  /**
   * A limit too small to hold the marker itself: the text still has to come
   * back inside the limit, it just cannot explain that it was shortened.
   */
  public function testLimitSmallerThanTheMarkerStillFits(): void {
    $fitted = MessageLimit::fitHtml(str_repeat('x', 200), 5, 'a rather long marker');
    $this->assertSame(5, MessageLimit::length($fitted));
  }

  public function testZeroLimitIsTreatedAsNoLimit(): void {
    $this->assertSame('hello', MessageLimit::fitText('hello', 0, 'cut'));
  }

  public function testEmojiOnlyBodyRespectsTheUnitBudget(): void {
    $fitted = MessageLimit::fitText(str_repeat('📬', 500), 100, 'cut');
    $this->assertLessThanOrEqual(100, MessageLimit::length($fitted));
  }

}

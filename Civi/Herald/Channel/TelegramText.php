<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

use CRM_Herald_ExtensionUtil as E;

/**
 * Fitting a rendered body into Telegram's sendMessage limit, in the parse mode
 * it will be sent with. Pure — the channel calls fit() right before the send.
 */
final class TelegramText {

  /**
   * Shorten a body that exceeds Telegram's limit, with the drop stated in the
   * message itself. Splitting into several messages is deliberately not the
   * answer: the buffered alerts that reach this size are aggregates, and the
   * aggregation exists to stop herald from flooding a chat.
   *
   * Only the HTML mode gets its markup repaired at the cut, which is the mode
   * herald's own aggregates are rendered in. A MarkdownV2 body long enough to
   * be shortened can still land the cut inside its own formatting and be
   * rejected — balancing that would mean parsing MarkdownV2, for a body no
   * template here produces.
   */
  public static function fit(string $body, string $parseMode): string {
    // What Telegram counts: markup is not part of the 4096, so an alert dressed
    // in tags must not be shortened while its text still fits.
    $length = $parseMode === 'HTML'
      ? MessageLimit::htmlTextLength($body)
      : MessageLimit::length($body);
    if ($length <= TelegramChannel::MAX_TEXT_LENGTH) {
      return $body;
    }
    \Civi::log()->warning('herald: Telegram body exceeded the message limit and was shortened', [
      'length' => $length,
      'limit' => TelegramChannel::MAX_TEXT_LENGTH,
    ]);
    // The full length, not the dropped count: the marker is part of what has
    // to fit, so a dropped count would have to describe its own size.
    $marker = self::escapeForParseMode(
      E::ts('… shortened — the full message was %1 characters, Telegram allows %2.', [
        1 => $length,
        2 => TelegramChannel::MAX_TEXT_LENGTH,
      ]),
      $parseMode,
    );
    return $parseMode === 'HTML'
      ? MessageLimit::fitHtml($body, TelegramChannel::MAX_TEXT_LENGTH, $marker)
      : MessageLimit::fitText($body, TelegramChannel::MAX_TEXT_LENGTH, $marker);
  }

  /**
   * The rendered body is HTML; any other parse mode gets its visible text,
   * escaped where MarkdownV2 would read it as syntax.
   */
  public static function forParseMode(string $body, string $parseMode): string {
    return $parseMode === 'HTML' ? $body : self::escapeForParseMode(PlainText::fromHtml($body), $parseMode);
  }

  /**
   * Make the truncation marker safe for the parse mode it is appended to.
   *
   * The marker is a translated string, so its punctuation is not ours to
   * predict — a German "…" sentence ends in a full stop, and in MarkdownV2 an
   * unescaped "." is a syntax error that would reject exactly the message this
   * shortening exists to deliver.
   */
  private static function escapeForParseMode(string $marker, string $parseMode): string {
    if ($parseMode === 'HTML') {
      return htmlspecialchars($marker, ENT_NOQUOTES | ENT_HTML5, 'UTF-8');
    }
    if ($parseMode === 'MarkdownV2') {
      // The reserved set from https://core.telegram.org/bots/api#markdownv2-style.
      // Replacement '\\\\$1' is one escaped backslash plus the backreference:
      // '\\$1' would escape the dollar and emit a literal "$1" instead.
      return preg_replace('/([_*\[\]()~`>#+\-=|{}.!\\\\])/u', '\\\\$1', $marker) ?? $marker;
    }
    return $marker;
  }

}

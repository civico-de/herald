<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

/**
 * Converts a rendered notification body to safe Activity text fields.
 */
final class ActivityText {

  public static function subject(string $body): string {
    $subject = trim((string) preg_replace(
      '/\s+/u',
      ' ',
      PlainText::fromHtml($body),
    ));
    if ($subject === '') {
      return 'Herald notification';
    }
    return mb_strlen($subject) > 255 ? mb_substr($subject, 0, 252) . '...' : $subject;
  }

  public static function details(string $body, string $format): string {
    if (!in_array($format, ['html', 'text'], TRUE)) {
      throw new \CRM_Core_Exception(
        'herald: activity_format must be "html" or "text".',
      );
    }
    return $format === 'text'
      ? nl2br(htmlspecialchars(PlainText::fromHtml($body), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
      : HtmlLineBreaks::apply($body);
  }

}

<?php

declare(strict_types = 1);

namespace Civi\Herald\Admin;

/**
 * Compiles an admin-entered PCRE pattern and returns why it fails.
 */
final class RegexCheck {

  /**
   * PCRE reports a broken pattern as a warning, so the handler captures its text.
   */
  public static function error(string $pattern): ?string {
    $warning = NULL;
    set_error_handler(static function (int $level, string $message) use (&$warning): bool {
      $warning = preg_replace('/^preg_match\(\): /', '', $message);
      return TRUE;
    });
    try {
      $valid = preg_match($pattern, '') !== FALSE;
    }
    finally {
      restore_error_handler();
    }
    return $valid ? NULL : $warning ?? preg_last_error_msg();
  }

}

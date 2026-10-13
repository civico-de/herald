<?php

declare(strict_types = 1);

namespace Civi\Herald\Admin;

/**
 * Admin input that failed validation: one message per problem, shown to the
 * admin in full (the AJAX API hides other exception messages from users
 * without "view debug output").
 */
final class InvalidInput extends \CRM_Core_Exception {

  /**
   * @param non-empty-list<string> $errors
   */
  public function __construct(array $errors) {
    parent::__construct(implode("\n", $errors), 0, ['show_detailed_error' => TRUE, 'errors' => $errors]);
  }

  /**
   * @param list<string> $errors
   */
  public static function throwIfAny(array $errors): void {
    if ($errors !== []) {
      throw new self($errors);
    }
  }

}

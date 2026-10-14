<?php

declare(strict_types = 1);

namespace Civi\Herald\Subscribers;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Which address of a contact an email notification goes to.
 */
final class EmailRecipient {

  /**
   * The address to email one contact at, judged across all its rows: a
   * deliverable address may sit behind an on-hold one. Location addresses
   * come before the primary one. do_not_email always blocks; opt-out and
   * on hold count only under respect_suspension.
   *
   * @param non-empty-array<int, array<string, mixed>> $contactRows
   * @param array<string, mixed> $options
   * @return array{address: string, suspended: bool}
   *   An empty address that is not suspended means the contact has none.
   */
  public static function of(array $contactRows, array $options): array {
    $first = $contactRows[0];
    $respect = !empty($options['respect_suspension']);
    if (!empty($first['do_not_email']) || $respect && !empty($first['is_opt_out'])) {
      return ['address' => '', 'suspended' => TRUE];
    }
    $candidates = [];
    foreach ($contactRows as $row) {
      $candidates[] = [Cast::toString($row['herald_loc_email.email'] ?? ''), !empty($row['herald_loc_email.on_hold'])];
    }
    $candidates[] = [Cast::toString($first['email_primary.email'] ?? ''), !empty($first['email_primary.on_hold'])];
    $candidates = array_filter($candidates, static fn (array $candidate): bool => $candidate[0] !== '');
    foreach ($candidates as [$address, $onHold]) {
      if (!$respect || !$onHold) {
        return ['address' => $address, 'suspended' => FALSE];
      }
    }
    return ['address' => '', 'suspended' => $candidates !== []];
  }

}

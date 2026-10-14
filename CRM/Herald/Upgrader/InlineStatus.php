<?php

declare(strict_types = 1);

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * The contribution status filter of older releases' digest blocks.
 */
final class CRM_Herald_Upgrader_InlineStatus {

  /**
   * Whether a record reports what the block's status filter did. Older releases
   * read NULL, "" and 0 as none, and only a contribution block's totals, items,
   * histogram and pending pool read it; line-item pending was always Pending.
   *
   * @param array<mixed> $block
   */
  public static function readable(array $block): bool {
    $status = $block['status'] ?? NULL;
    if (Cast::toString($block['type'] ?? 'contribution') !== 'contribution' || in_array($status, [NULL, '', 0], TRUE)) {
      return TRUE;
    }
    $pendingReadStatus =
      (!isset($block['show_pending']) || !empty($block['show_pending'])) && ($block['group_by'] ?? '') !== 'line_item';
    return $status === 'Completed' && !$pendingReadStatus;
  }

}

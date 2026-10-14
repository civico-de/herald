<?php

declare(strict_types = 1);

namespace Civi\Herald\Buffer;

use CRM_Herald_ExtensionUtil as E;

/**
 * The wording of an aggregated alert: which buffered bodies count, and the
 * summary line that introduces them.
 *
 * Pure — no database, no buffer state. compose() and composeDelivery() both
 * need the same count and the same figures, so the phrasing lives in one place
 * instead of once per output.
 */
final class AggregateSummary {

  /**
   * Buffered bodies that actually carry text, trimmed. This is what "how many
   * alerts" means everywhere: a blank body is not a message.
   *
   * @param list<string> $bodies
   * @return list<string>
   */
  public static function nonBlank(array $bodies): array {
    return array_values(array_filter(array_map('trim', $bodies), static fn (string $b) => $b !== ''));
  }

  /**
   * "📬 3 messages, total 320,00 € (collected over 15 min)" — the aggregate
   * line. $emphasis names an optional markup tag to wrap the figures in.
   *
   * @param list<float> $amounts
   */
  public static function line(int $count, int $windowMinutes, array $amounts, string $emphasis = ''): string {
    $open = $emphasis === '' ? '' : '<' . $emphasis . '>';
    $close = $emphasis === '' ? '' : '</' . $emphasis . '>';
    $summary = '📬 ' . $open . E::ts('%1 messages', [1 => $count]) . $close;
    if ($amounts) {
      $summary .= ', ' . E::ts('total %1', [1 => $open . \Civi::format()->money(array_sum($amounts)) . $close]);
    }
    return $summary . ' (' . E::ts('collected over %1 min', [1 => $windowMinutes]) . ')';
  }

  /**
   * "… and 873 more" — the tail line of an aggregate that was capped. The
   * header still names the true total, so this only has to account for what
   * is not printed.
   */
  public static function more(int $omitted): string {
    return '… ' . E::ts('and %1 more', [1 => $omitted]);
  }

  /**
   * Compose the outgoing message from buffered bodies. Pure — test-seam.
   *
   * A single buffered alert goes out unchanged (aggregation only added
   * latency, not noise). Multiple alerts get a summary header — count and,
   * when amounts were recorded, their sum — followed by the individual
   * bodies.
   *
   * $maxItems caps how many bodies are printed; the header keeps counting all
   * of them and a tail line names the remainder. A bulk operation can write
   * thousands of entries into one window, and nobody reads a message with
   * thousands of positions — nor does every transport accept one.
   *
   * @param list<string> $bodies
   * @param list<float> $amounts
   * @param int $maxItems 0 = print every body.
   */
  public static function compose(
    array $bodies,
    int $windowMinutes,
    array $amounts = [],
    int $maxItems = 0,
  ): string {
    $bodies = self::nonBlank($bodies);
    $total = count($bodies);
    if ($total <= 1) {
      return $bodies[0] ?? '';
    }
    $parts = [self::line($total, $windowMinutes, $amounts, 'b')];
    $shown = $maxItems > 0 && $total > $maxItems ? array_slice($bodies, 0, $maxItems) : $bodies;
    $tail = count($shown) < $total ? [self::more($total - count($shown))] : [];
    return implode("\n\n", [...$parts, ...$shown, ...$tail]);
  }

  /**
   * Delivery params for the composed message. Pure — test-seam.
   *
   * An aggregate gets a subject describing the aggregate: every buffered row
   * carries the subject its own event rendered, so keeping one of them would
   * name a message of N events after a single one.
   *
   * @param array<string, mixed> $delivery
   * @param list<string> $bodies
   * @param list<float> $amounts
   * @return array<string, mixed>
   */
  public static function composeDelivery(
    array $delivery,
    array $bodies,
    int $windowMinutes,
    array $amounts = [],
  ): array {
    $count = count(self::nonBlank($bodies));
    if ($count > 1) {
      $delivery['email_subject'] = self::line($count, $windowMinutes, $amounts);
    }
    return $delivery;
  }

}

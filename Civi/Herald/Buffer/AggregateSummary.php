<?php

declare(strict_types = 1);

namespace Civi\Herald\Buffer;

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
    $summary = '📬 ' . $open . self::t('%1 messages', [1 => $count]) . $close;
    if ($amounts) {
      $summary .= ', ' . self::t('total %1', [1 => $open . self::money(array_sum($amounts)) . $close]);
    }
    return $summary . ' (' . self::t('collected over %1 min', [1 => $windowMinutes]) . ')';
  }

  /**
   * "… and 873 more" — the tail line of an aggregate that was capped. The
   * header still names the true total, so this only has to account for what
   * is not printed.
   */
  public static function more(int $omitted): string {
    return '… ' . self::t('and %1 more', [1 => $omitted]);
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

  /**
   * Locale- and currency-correct money formatting (default currency of the
   * instance) when Civi is booted; a fixed EUR/German rendering in pure unit
   * tests, matching this extension's origin.
   */
  private static function money(float $amount): string {
    if (class_exists('\Civi') && is_callable(['\Civi', 'format'])) {
      try {
        return \Civi::format()->money($amount);
      }
      // @mago-expect lint:no-empty-catch-clause
      // Deliberate: Civi\format() is optional here, the static rendering is the fallback.
      catch (\Throwable $e) {
        // Fall through to the static rendering.
      }
    }
    return number_format($amount, 2, ',', '.') . ' €';
  }

  /**
   * Translate through the civix ExtensionUtil (herald's own domain) when Civi
   * is booted; plain %N substitution otherwise (pure unit tests, no framework).
   *
   * @param array<int, string|int> $params
   */
  private static function t(string $text, array $params = []): string {
    if (function_exists('ts') && class_exists(\CRM_Herald_ExtensionUtil::class)) {
      return \CRM_Herald_ExtensionUtil::ts($text, $params);
    }
    foreach ($params as $key => $value) {
      $text = str_replace('%' . $key, (string) $value, $text);
    }
    return $text;
  }

}

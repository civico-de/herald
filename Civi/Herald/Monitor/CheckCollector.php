<?php

declare(strict_types = 1);

namespace Civi\Herald\Monitor;

use Civi\Herald\Channel\PlainText;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Turns core's status messages into one observation per check key, plus a
 * failing pseudo-check for each expected extension that is not enabled.
 *
 * @phpstan-type Message array{key: string, detail: string, severity: int, title: string, text: string, visible: bool}
 * @phpstan-type ObservationArray array{status: string, severity: int, detail: list<string>, title: string, text: string, visible: bool}
 */
final class CheckCollector {

  /**
   * @var \Closure(): list<array<array-key, mixed>>
   */
  private \Closure $source;

  /**
   * @param (\Closure(): list<array<array-key, mixed>>)|null $source System.check rows; core's by default.
   */
  public function __construct(?\Closure $source = NULL) {
    $this->source = $source ?? SystemCheck::messages(...);
  }

  /**
   * Reads and translates in the site language.
   *
   * @return array<string, ObservationArray> Observations by check key.
   */
  public function observe(Threshold $threshold): array {
    return SystemCheck::inSiteLocale(fn (): array => $this->collect($threshold));
  }

  /**
   * @return array<string, ObservationArray>
   */
  private function collect(Threshold $threshold): array {
    $byKey = [];
    foreach (($this->source)() as $row) {
      $message = self::message($row);
      $byKey[$message['key']][] = $message;
    }
    $observations = [];
    foreach ($byKey as $key => $messages) {
      $observations[(string) $key] = self::merge((string) $key, $messages, $threshold);
    }
    $observations = ExpectedExtensions::observations() + $observations;
    ksort($observations, SORT_STRING);
    return $observations;
  }

  /**
   * A name splits at its first colon into the key and the detail.
   *
   * @return array{0: string, 1: string}
   */
  public static function split(string $name): array {
    $parts = explode(':', $name, 2);
    return [$parts[0], $parts[1] ?? ''];
  }

  /**
   * @param array<array-key, mixed> $row
   * @return Message
   */
  private static function message(array $row): array {
    [$key, $detail] = self::split(Cast::toString($row['name'] ?? NULL));
    return [
      'key' => $key,
      'detail' => $detail,
      'severity' => Cast::toInt($row['severity_id'] ?? NULL),
      'title' => Cast::toString($row['title'] ?? NULL),
      'text' => PlainText::fromHtml(Cast::toString($row['message'] ?? NULL)),
      'visible' => !empty($row['is_visible']),
    ];
  }

  /**
   * Highest severity, sorted details; independent of the order core reports
   * them in. Hidden messages count only when core hides every failing one.
   *
   * @param non-empty-list<Message> $messages
   * @return ObservationArray
   */
  private static function merge(string $key, array $messages, Threshold $threshold): array {
    $failing = array_filter($messages, static fn (array $m): bool => $threshold->fails($key, $m['severity']));
    $visibleFailing = array_filter($failing, static fn (array $m): bool => $m['visible']);
    $basis = $visibleFailing !== [] ? array_filter($messages, static fn (array $m): bool => $m['visible']) : $messages;
    usort(
      $basis,
      static fn (array $a, array $b): int => (
        [$b['severity'], $a['detail'], $a['title'], $a['text']] <=> [
          $a['severity'],
          $b['detail'],
          $b['title'],
          $b['text'],
        ]
      ),
    );
    $details = array_values(array_unique(array_filter(array_column($basis, 'detail'), self::notEmpty(...))));
    sort($details, SORT_STRING);

    return [
      'status' => $failing !== [] ? 'fail' : 'ok',
      'severity' => $basis[0]['severity'],
      'detail' => $details,
      'title' => $basis[0]['title'],
      'text' => implode("\n\n", array_unique(array_filter(array_column($basis, 'text'), self::notEmpty(...)))),
      'visible' => $failing !== [] ? $visibleFailing !== [] : in_array(TRUE, array_column($messages, 'visible'), TRUE),
    ];
  }

  private static function notEmpty(string $value): bool {
    return $value !== '';
  }

}

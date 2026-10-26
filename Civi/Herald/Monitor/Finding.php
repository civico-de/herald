<?php

declare(strict_types = 1);

namespace Civi\Herald\Monitor;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * What a check key's messages say in one run: the part an alert reports and
 * the state compares against.
 */
final class Finding {

  /**
   * @param list<string> $detail Sorted details of the key's messages.
   */
  public function __construct(
    public readonly int $severity,
    public readonly array $detail,
    public readonly string $title,
    public readonly string $text,
  ) {}

  /**
   * @param array<string, mixed> $obs
   */
  public static function fromArray(array $obs): self {
    return new self(
      max(0, Cast::toInt($obs['severity'] ?? 0)),
      self::details($obs['detail'] ?? []),
      Cast::toString($obs['title'] ?? NULL),
      Cast::toString($obs['text'] ?? NULL),
    );
  }

  /**
   * A detail list; a single string is one detail.
   *
   * @return list<string>
   */
  public static function details(mixed $detail): array {
    if (is_array($detail)) {
      return array_values(array_map(Cast::toString(...), $detail));
    }
    $detail = Cast::toString($detail);
    return $detail === '' ? [] : [$detail];
  }

  public function titleOr(string $fallback): string {
    return $this->title !== '' ? $this->title : $fallback;
  }

  /**
   * For an announced fail: 'changed' on a higher severity or another detail
   * than the last alert, 'fail' when muting ended on an unchanged one.
   */
  public function updateOver(CheckState $state): ?string {
    if ($this->severity > $state->severity || $this->detail !== $state->detail) {
      return 'changed';
    }
    return $state->muted ? 'fail' : NULL;
  }

}

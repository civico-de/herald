<?php

declare(strict_types = 1);

namespace Civi\Herald\Monitor;

/**
 * One check's raw result for one run, normalized.
 *
 * Anything that is not an explicit non-failure counts as a fail; the detail is
 * whatever scalar the check offered, or empty.
 */
class Observation {

  public function __construct(
    public string $name,
    public bool $failed,
    public string $detail,
  ) {}

  /**
   * Read a raw observation entry.
   *
   * @param string $name The check name.
   * @param array{status?: string, detail?: mixed} $obs The raw observation.
   * @return self The normalized observation.
   */
  public static function fromArray(string $name, array $obs): self {
    return new self(
      $name,
      ($obs['status'] ?? 'ok') === 'fail',
      Coerce::str($obs['detail'] ?? NULL),
    );
  }

}

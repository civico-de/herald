<?php

declare(strict_types = 1);

namespace Civi\Herald\Monitor;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * One check's normalized result: anything but an explicit 'fail' counts as ok here.
 */
class Observation {

  public function __construct(
    public string $name,
    public bool $failed,
    public string $detail,
  ) {}

  /**
   * @param array{status?: string, detail?: mixed} $obs
   */
  public static function fromArray(string $name, array $obs): self {
    return new self(
      $name,
      ($obs['status'] ?? 'ok') === 'fail',
      Cast::toString($obs['detail'] ?? NULL),
    );
  }

}

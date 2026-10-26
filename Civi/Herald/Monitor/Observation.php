<?php

declare(strict_types = 1);

namespace Civi\Herald\Monitor;

/**
 * One check key's result in this run: anything but an explicit 'fail' counts as ok here.
 * A key without a message this run is an ok observation with empty fields.
 */
class Observation {

  public function __construct(
    public string $key,
    public bool $failed,
    public bool $visible,
    public Finding $finding,
  ) {}

  /**
   * @param array<string, mixed> $obs
   */
  public static function fromArray(string $key, array $obs): self {
    return new self(
      $key,
      ($obs['status'] ?? 'ok') === 'fail',
      !empty($obs['visible'] ?? TRUE),
      Finding::fromArray($obs),
    );
  }

  /**
   * The template vars of an alert about this observation.
   *
   * @return array<string, mixed>
   */
  public function message(string $status, string $title, bool $renotify): array {
    return [
      'check' => $this->key,
      'status' => $status,
      'title' => $title,
      'detail' => implode(', ', $this->finding->detail),
      'text' => $this->finding->text,
      'severity' => $this->finding->severity,
      'renotify' => $renotify,
    ];
  }

}

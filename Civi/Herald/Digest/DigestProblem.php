<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

/**
 * A digest that cannot be composed, for reasons herald names itself. The
 * messages carry no data of a source, so the failure notice may quote them.
 */
final class DigestProblem extends \CRM_Core_Exception {

  /**
   * @param non-empty-list<string> $problems
   * @param \Throwable|null $cause Read back with getCause(): CRM_Core_Exception drops a previous exception
   */
  public function __construct(
    private readonly array $problems,
    ?\Throwable $cause = NULL,
  ) {
    parent::__construct(implode(' ', $problems));
    $this->cause = $cause;
  }

  /**
   * @return non-empty-list<string>
   */
  public function problems(): array {
    return $this->problems;
  }

}

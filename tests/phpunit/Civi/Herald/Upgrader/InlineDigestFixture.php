<?php

declare(strict_types = 1);

namespace Civi\Herald\Upgrader;

use Civi\Api4\SearchDisplay;
use Civi\Herald\Digest\Composer;
use Civi\Herald\Digest\Definition;

/**
 * Reads back what the upgrade of an inline digest stored, and the warnings it logged.
 */
trait InlineDigestFixture {

  /**
   * The warnings the herald log channel receives while $run runs.
   *
   * @return list<string>
   */
  protected function heraldWarnings(callable $run): array {
    $logger = new class extends \Psr\Log\AbstractLogger {

      /**
       * @var list<string>
       */
      public array $warnings = [];

      public function log($level, string|\Stringable $message, array $context = []): void {
        if ($level === \Psr\Log\LogLevel::WARNING) {
          $this->warnings[] = (string) $message;
        }
      }

    };
    // LogManager caches one logger per channel and has no setter.
    $channels = \Closure::bind(
      fn &(): array => $this->channels,
      \Civi::container()->get('psr_log_manager'),
      \Civi\Core\LogManager::class,
    );
    $cache = &$channels();
    $previous = $cache;
    $cache['herald'] = $logger;
    try {
      $run();
    }
    finally {
      $cache = $previous;
    }
    return $logger->warnings;
  }

  /**
   * Template and sources of a migrated digest; a search source by its display's
   * name when herald ships it, else by the where clause of its copy.
   *
   * @return array{title: string, subject: string, html: string, sources: array<string, mixed>}
   */
  protected function stored(string $name): array {
    $digest = Definition::load($name);
    $sources = [];
    foreach ($digest['sources'] as $source) {
      $sources[$source['name']] = $source['type'] === 'section'
        ? $source['section_key']
        : $this->display((int) $source['search_display_id']);
    }
    self::assertSame([], (new Composer())->check($digest)['problems']);
    return [
      'title' => $digest['title'],
      'subject' => (string) $digest['template']['msg_subject'],
      'html' => (string) $digest['template']['msg_html'],
      'sources' => $sources,
    ];
  }

  protected function display(int $id): mixed {
    $display = SearchDisplay::get(checkPermissions: FALSE)
      ->addSelect('name', 'saved_search_id.api_params')
      ->addWhere('id', '=', $id)
      ->execute()
      ->single();
    return str_starts_with((string) $display['name'], 'Herald_Digest_')
      ? $display['name']
      : $display['saved_search_id.api_params']['where'] ?? [];
  }

}

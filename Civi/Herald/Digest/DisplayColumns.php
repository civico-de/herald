<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Api4\Service\Spec\SpecGatherer;
use Civi\Core\Locale;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * A SearchKit display and the columns that carry its values.
 */
final class DisplayColumns {

  // SearchKit translates headers on read in any other language; placeholder names stay those of the stored ones.
  public const NAMES_LANGUAGE = 'en_US';

  /**
   * The display with the name of its search, or NULL.
   *
   * @return array<string, mixed>|null
   */
  public static function display(int $id): ?array {
    $display = \Civi\Api4\SearchDisplay::get(checkPermissions: FALSE)
      ->setLanguage(self::NAMES_LANGUAGE)
      ->addSelect('*', 'saved_search_id.name', 'saved_search_id.api_params')
      ->addWhere('id', '=', $id)
      ->execute()
      ->first();
    return is_array($display) ? Cast::namedOnly($display) : NULL;
  }

  /**
   * @return array<string, mixed>
   * @throws DigestProblem
   */
  public static function existing(int $id): array {
    return self::display($id) ?? throw new DigestProblem([E::ts('search display %1 does not exist.', [1 => $id])]);
  }

  /**
   * Key and header of each value column in display order; links, buttons and menus carry no value.
   * Auto mode takes the columns SearchKit derives from the search, as its run does.
   *
   * @param array<string, mixed> $display
   * @return list<array{key: string, label: string}>
   */
  public static function of(array $display): array {
    $settings = (array) ($display['settings'] ?? []);
    if (($settings['columnMode'] ?? NULL) === 'auto') {
      $default = self::inNamesLanguage(
        static fn () => \Civi\Api4\SearchDisplay::getDefault(checkPermissions: FALSE)
          ->setSavedSearch(Cast::toString($display['saved_search_id.name']))
          ->setType(Cast::toString($display['type']))
          ->execute()
          ->single(),
      );
      $settings = (array) ($default['settings'] ?? []);
    }
    $columns = [];
    foreach ((array) ($settings['columns'] ?? []) as $column) {
      if (!is_array($column) || !in_array($column['type'] ?? NULL, ['field', 'html'], TRUE)) {
        continue;
      }
      $columns[] = ['key' => Cast::toString($column['key'] ?? ''), 'label' => Cast::toString($column['label'] ?? '')];
    }
    return $columns;
  }

  /**
   * getDefault ignores setLanguage(), and core's field spec cache keys on the site language rather than
   * the active one, so the read runs in NAMES_LANGUAGE between two clears of that cache.
   *
   * @template T
   * @param \Closure(): T $read
   * @return T
   */
  private static function inNamesLanguage(\Closure $read): mixed {
    $previous = Locale::detect();
    $specs = \Civi::service('spec_gatherer');
    assert($specs instanceof SpecGatherer, 'spec_gatherer is core\'s field spec service');
    Locale::negotiate(self::NAMES_LANGUAGE)->apply();
    $specs->onClearMetadata();
    try {
      return $read();
    }
    finally {
      $specs->onClearMetadata();
      $previous->apply();
    }
  }

}

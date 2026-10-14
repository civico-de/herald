<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * The digest source types and the HeraldDigestSource field each cannot do without.
 */
final class SourceType {

  public const REQUIRED = ['search' => 'search_display_id', 'section' => 'section_key'];

  /**
   * @return array<string, string>
   */
  public static function options(): array {
    return [
      'search' => E::ts('Search display'),
      'section' => E::ts('Section from an extension'),
    ];
  }

  /**
   * Why the source cannot run, or NULL; the display itself is checked by SearchSource.
   *
   * @param array<string, mixed> $source HeraldDigestSource values
   */
  public static function problem(array $source): ?string {
    $type = Cast::toString($source['type'] ?? '');
    if (!array_key_exists($type, self::REQUIRED)) {
      return E::ts('unknown type "%1".', [1 => $type]);
    }
    if (trim(Cast::toString($source[self::REQUIRED[$type]] ?? '')) !== '') {
      return NULL;
    }
    return $type === 'search'
      ? E::ts('a search source needs a search display.')
      : E::ts('a section source needs a section key.');
  }

}

<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Core\Event\GenericHookEvent;
use CRM_Herald_ExtensionUtil as E;

/**
 * Digest sections contributed by other extensions: numbers no search can
 * answer belong to the extension that owns them. A digest source of type
 * "section" with the key "myext.seats" asks for one; the owning extension
 * answers for its own key with values, which the template formats:
 *
 *   function myext_civicrm_heraldDigestSections(array $keys, array $context, array &$sections): void {
 *     if (in_array('myext.seats', $keys, TRUE)) {
 *       $sections['myext.seats'] = ['booked' => 138, 'paid' => 120];
 *     }
 *   }
 *
 * $context carries the digest's name under 'digest'.
 */
class ExtraSections {

  public const HOOK = 'hook_civicrm_heraldDigestSections';

  /**
   * Ask the extensions for one section: named scalars, and lists of rows of named scalars.
   *
   * @param array<string, mixed> $context
   * @return array<string, mixed>
   * @throws DigestProblem when no extension answers or the answer is not values
   */
  public static function answer(string $key, array $context): array {
    /** @var array<array-key, mixed> $sections */
    $sections = [];
    \Civi::dispatcher()->dispatch(self::HOOK, GenericHookEvent::create([
      'keys' => [$key],
      'context' => $context,
      'sections' => &$sections,
    ]));
    if (!is_array($sections) || !array_key_exists($key, $sections)) {
      throw new DigestProblem([E::ts('No extension answers the digest section "%1".', [1 => $key])]);
    }
    $answer = $sections[$key];
    if (!is_array($answer)) {
      throw new DigestProblem([E::ts(
        'The digest section "%1" answered with text; a section returns values, e.g. [\'booked\' => 138].',
        [1 => $key],
      )]);
    }
    $problems = SectionRules::problems($answer);
    if ($problems !== []) {
      throw new DigestProblem(array_map(
        static fn (string $problem): string => E::ts('Digest section "%1": %2', [1 => $key, 2 => $problem]),
        $problems,
      ));
    }
    /** @var array<string, mixed> $answer */
    return $answer;
  }

}

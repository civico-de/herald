<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Afform\Event\AfformValidateEvent;
use Civi\Api4\Action\Afform\Submit;
use Civi\Core\HookInterface;
use Civi\Core\Service\AutoService;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Afform saves the sources after the digest and only logs their exceptions,
 * so the digest form must fail here, before anything is written.
 *
 * @service herald.digest_form_validator
 */
final class FormValidator extends AutoService implements HookInterface {

  public function on_civi_afform_validate(AfformValidateEvent $e): void {
    $request = $e->getApiRequest();
    assert($request instanceof Submit, 'civi.afform.validate is dispatched by Afform.submit');
    foreach ($e->getFormDataModel()->getEntities() as $afName => $afEntity) {
      if ($afEntity['type'] !== 'HeraldDigest') {
        continue;
      }
      // The record being edited is named in the form args, not in the submitted fields.
      $ids = self::list($request->getArgs()[$afName] ?? NULL);
      foreach (self::list($e->getSubmittedValues()[$afName] ?? NULL) as $i => $record) {
        array_map($e->addError(...), self::problems(self::named($record), self::id($ids[$i] ?? NULL)));
      }
    }
  }

  /**
   * The fields, then the digest composed from the submitted sources with today's data.
   *
   * @param array<string, mixed> $record Submitted `fields` and `joins` of one digest
   * @return list<string>
   */
  private static function problems(array $record, ?int $id): array {
    $fields = self::named($record['fields'] ?? NULL);
    $sources = array_map(
      self::named(...),
      self::list(self::named($record['joins'] ?? NULL)['HeraldDigestSource'] ?? NULL),
    );
    $digest = Definition::of($fields, $sources);
    // A missing template is already the field's problem.
    $composed = array_diff((new Composer())->check($digest)['problems'], [DigestRules::noTemplate($digest)]);
    return array_values(array_unique([...array_values(DigestRules::recordProblems($fields, $id)), ...$composed]));
  }

  /**
   * An arg names the record as an id, or as a list with one record.
   */
  private static function id(mixed $arg): ?int {
    return Definition::idOf(is_array($arg) ? $arg['id'] ?? NULL : $arg);
  }

  /**
   * @return list<mixed>
   */
  private static function list(mixed $values): array {
    return array_values((array) $values);
  }

  /**
   * @return array<string, mixed>
   */
  private static function named(mixed $values): array {
    return is_array($values) ? Cast::namedOnly($values) : [];
  }

}

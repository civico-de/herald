<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * A digest as Composer takes it: name, title, the values of its message
 * template (NULL once the template is deleted) and its sources.
 *
 * @phpstan-type Template array{msg_subject: string, msg_html: string, msg_text: string}
 * @phpstan-type Source array{name: string, type: string, search_display_id: ?int, section_key: ?string}
 * @phpstan-type Digest array{name: string, title: string, template: ?Template, sources: list<Source>}
 */
final class Definition {

  /**
   * @return Digest
   * @throws DigestProblem
   */
  public static function load(string $name): array {
    $digest = \Civi\Api4\HeraldDigest::get(checkPermissions: FALSE)
      ->addSelect('id', 'name', 'title', 'message_template_id')
      ->addWhere('name', '=', $name)
      ->execute()
      ->first();
    if (!is_array($digest)) {
      throw new DigestProblem([E::ts('Digest "%1" does not exist.', [1 => $name])]);
    }
    /** @var list<array<string, mixed>> $sources */
    $sources = \Civi\Api4\HeraldDigestSource::get(checkPermissions: FALSE)
      ->addSelect('name', 'type', 'search_display_id', 'section_key')
      ->addWhere('digest_id', '=', $digest['id'])
      ->addOrderBy('id')
      ->execute()
      ->getArrayCopy();
    return self::of($digest, $sources);
  }

  /**
   * A digest from HeraldDigest and HeraldDigestSource values, stored or submitted.
   *
   * @param array<array-key, mixed> $digest
   * @param list<array<array-key, mixed>> $sources
   * @return Digest
   */
  public static function of(array $digest, array $sources): array {
    $templateId = self::idOf($digest['message_template_id'] ?? NULL);
    return [
      'name' => Cast::toString($digest['name'] ?? ''),
      'title' => Cast::toString($digest['title'] ?? ''),
      'template' => $templateId === NULL ? NULL : self::template($templateId),
      'sources' => array_map(self::source(...), $sources),
    ];
  }

  /**
   * A stored or submitted id; NULL and '' are none.
   */
  public static function idOf(mixed $value): ?int {
    return in_array($value, [NULL, ''], TRUE) ? NULL : Cast::toInt($value);
  }

  /**
   * @return Template|null NULL when the template does not exist
   */
  public static function template(int $id): ?array {
    $template = \Civi\Api4\MessageTemplate::get(checkPermissions: FALSE)
      ->addSelect('msg_subject', 'msg_html', 'msg_text')
      ->addWhere('id', '=', $id)
      ->execute()
      ->first();
    return is_array($template) ? self::templateValues($template) : NULL;
  }

  /**
   * @param array<array-key, mixed> $template
   * @return Template
   */
  public static function templateValues(array $template): array {
    return [
      'msg_subject' => Cast::toString($template['msg_subject'] ?? ''),
      'msg_html' => Cast::toString($template['msg_html'] ?? ''),
      'msg_text' => Cast::toString($template['msg_text'] ?? ''),
    ];
  }

  /**
   * A source from HeraldDigestSource values, stored or submitted.
   *
   * @param array<array-key, mixed> $source
   * @return Source
   */
  public static function source(array $source): array {
    $sectionKey = trim(Cast::toString($source['section_key'] ?? ''));
    return [
      'name' => Cast::toString($source['name'] ?? ''),
      'type' => Cast::toString($source['type'] ?? ''),
      'search_display_id' => self::idOf($source['search_display_id'] ?? NULL),
      'section_key' => $sectionKey === '' ? NULL : $sectionKey,
    ];
  }

}

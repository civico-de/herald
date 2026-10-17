<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

/**
 * A stored digest, for tests.
 */
final class DigestFixture {

  /**
   * @param list<array<string, mixed>> $sources HeraldDigestSource values without digest_id
   * @return array{id: int}
   */
  public static function create(
    string $name,
    string $title,
    string $html = '<p>Static digest</p>',
    array $sources = [],
    string $subject = '',
  ): array {
    $id = \Civi\Api4\HeraldDigest::create(checkPermissions: FALSE)
      ->setValues(['name' => $name, 'title' => $title, 'msg_subject' => $subject, 'msg_html' => $html])
      ->execute()
      ->single()['id'];
    foreach ($sources as $source) {
      \Civi\Api4\HeraldDigestSource::create(checkPermissions: FALSE)
        ->setValues(['digest_id' => $id] + $source)
        ->execute();
    }
    return ['id' => (int) $id];
  }

}

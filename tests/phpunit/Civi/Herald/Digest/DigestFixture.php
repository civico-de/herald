<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

/**
 * A stored digest with a message template of its own, for tests.
 */
final class DigestFixture {

  /**
   * @param list<array<string, mixed>> $sources HeraldDigestSource values without digest_id
   * @return array{id: int, template_id: int}
   */
  public static function create(
    string $name,
    string $title,
    string $html = '<p>Static digest</p>',
    array $sources = [],
    string $subject = '',
  ): array {
    $templateId = \Civi\Api4\MessageTemplate::create(checkPermissions: FALSE)
      ->setValues(['msg_title' => 'Herald test: ' . $name, 'msg_subject' => $subject, 'msg_html' => $html])
      ->execute()
      ->single()['id'];
    $id = \Civi\Api4\HeraldDigest::create(checkPermissions: FALSE)
      ->setValues(['name' => $name, 'title' => $title, 'message_template_id' => $templateId])
      ->execute()
      ->single()['id'];
    foreach ($sources as $source) {
      \Civi\Api4\HeraldDigestSource::create(checkPermissions: FALSE)
        ->setValues(['digest_id' => $id] + $source)
        ->execute();
    }
    return ['id' => (int) $id, 'template_id' => (int) $templateId];
  }

}

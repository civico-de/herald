<?php

declare(strict_types = 1);

use Civi\Herald\Channel\PlainText;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * Digests that point at a message template take over its subject and text;
 * the template stays as it is.
 */
final class CRM_Herald_Upgrader_DigestText {

  private const TABLE = 'civicrm_herald_digest';

  private const OLD_FIELD = 'message_template_id';

  /**
   * Idempotent; a table created without the old field only gets the new ones.
   *
   * @return int The digests whose text came from a template
   */
  public static function move(): int {
    $schema = E::schema();
    /** @var array<string, array<string, mixed>> $fields */
    $fields = Civi::entity('HeraldDigest')->getFields();
    foreach (['msg_subject', 'msg_html'] as $field) {
      $schema->alterSchemaField('HeraldDigest', $field, $fields[$field]);
    }
    if (!$schema->schemaFieldExists('HeraldDigest', self::OLD_FIELD)) {
      return 0;
    }
    /** @var list<array<string, mixed>> $rows */
    $rows = CRM_Utils_SQL_Select::from(self::TABLE . ' d')
      ->join('t', 'INNER JOIN civicrm_msg_template t ON t.id = d.' . self::OLD_FIELD)
      ->select(['d.id', 't.msg_subject', 't.msg_html', 't.msg_text'])
      ->execute()
      ->fetchAll();
    foreach ($rows as $row) {
      $html = Cast::toString($row['msg_html'] ?? '');
      if (trim($html) === '') {
        $html = self::textAsHtml(Cast::toString($row['msg_text'] ?? ''));
      }
      CRM_Core_DAO::executeQuery(
        'UPDATE ' . self::TABLE . ' SET msg_subject = %1, msg_html = %2 WHERE id = %3',
        [
          1 => [Cast::toString($row['msg_subject'] ?? ''), 'String'],
          2 => [$html, 'String'],
          3 => [Cast::toInt($row['id']), 'Positive'],
        ],
      );
    }
    $schema->dropForeignKey(
      self::TABLE,
      'FK_' . CRM_Core_BAO_SchemaHandler::getIndexName(self::TABLE, self::OLD_FIELD),
    );
    $schema->dropSchemaField('HeraldDigest', self::OLD_FIELD);
    return count($rows);
  }

  /**
   * A plain-text template as HTML that renders the same, text and string literals escaped
   * like the values; only modifiers that count characters see `&`, `<`, `>` as entities.
   */
  public static function textAsHtml(string $text): string {
    $parts = preg_split('/(\{[^\s{}][^{}]*\})/', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
    $html = '';
    foreach ($parts as $i => $part) {
      $html .= ($i % 2) === 1
        ? preg_replace_callback('/\'[^\']*\'|"[^"]*"/', static fn (array $m): string => PlainText::toHtml($m[0]), $part)
        : PlainText::toHtml($part);
    }
    return $html;
  }

}

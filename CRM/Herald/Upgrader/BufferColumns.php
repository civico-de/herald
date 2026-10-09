<?php

declare(strict_types = 1);

use CRM_Herald_ExtensionUtil as E;

/**
 * Brings an older civicrm_herald_buffer up to the columns schema/ declares.
 */
final class CRM_Herald_Upgrader_BufferColumns {

  private const BUFFER_ENTITY = 'HeraldBufferedAlert';

  /**
   * Adds the named columns as schema/ declares them, plus the index on
   * $indexed; a table created from the current schema already has both.
   *
   * @param list<string> $fields
   */
  public static function add(array $fields, string $indexed): void {
    $schema = E::schema();
    foreach ($fields as $field) {
      if ($schema->schemaFieldExists(self::BUFFER_ENTITY, $field)) {
        continue;
      }
      $spec = Civi::entity(self::BUFFER_ENTITY)->getField($field) ?? throw new CRM_Core_Exception(
        "herald: schema/ declares no buffer field {$field}",
      );
      $schema->alterSchemaField(self::BUFFER_ENTITY, $field, $spec, NULL);
    }
    CRM_Core_BAO_SchemaHandler::createIndexes([\Civi\Herald\Buffer\AlertBuffer::TABLE => [$indexed]]);
  }

}

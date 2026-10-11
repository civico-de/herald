<?php

declare(strict_types = 1);

use CRM_Herald_ExtensionUtil as E;

/**
 * Brings an older civicrm_herald_buffer up to what schema/ declares.
 */
final class CRM_Herald_Upgrader_BufferTable {

  private const BUFFER_ENTITY = 'HeraldBufferedAlert';

  /**
   * Adds or re-applies every column, index and table option of schema/, so the
   * table matches one a fresh install creates; idempotent.
   */
  public static function sync(): void {
    $schema = E::schema();
    $entity = Civi::entity(self::BUFFER_ENTITY);
    /** @var array<string, array<string, mixed>> $fields */
    $fields = $entity->getFields();
    foreach ($fields as $field => $spec) {
      $schema->alterSchemaField(self::BUFFER_ENTITY, $field, $spec, NULL);
    }
    /** @var array<string, array{fields: array<string, bool>}> $indices */
    $indices = $entity->getMeta('indices');
    $indexed = array_map(static fn (array $index): array => array_keys($index['fields']), array_values($indices));
    $table = \Civi\Herald\Buffer\AlertBuffer::TABLE;
    // Core names each index index_<columns>, as schema/ does.
    CRM_Core_BAO_SchemaHandler::createIndexes([$table => $indexed]);
    // No API sets table options; the SQL generator creates every table with this one.
    CRM_Core_DAO::executeQuery("ALTER TABLE `{$table}` ROW_FORMAT=DYNAMIC", i18nRewrite: FALSE);
  }

}

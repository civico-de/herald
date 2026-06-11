<?php

namespace Civi\Api4;

/**
 * APIv4 entity for herald.
 *
 * Service-style entity (no underlying DAO) that exposes diagnostic actions:
 *   cv api4 Herald.testTelegram   — send a test message via the channel
 *   cv api4 Herald.renderTemplate — render a workflow template with sample vars
 *
 * @searchable none
 * @package Civi\Api4
 */
class Herald extends \Civi\Api4\Generic\AbstractEntity {

  public static function testTelegram(bool $checkPermissions = TRUE): \Civi\Herald\Api4\Action\TestTelegram {
    return (new \Civi\Herald\Api4\Action\TestTelegram(__CLASS__, 'testTelegram'))
      ->setCheckPermissions($checkPermissions);
  }

  public static function renderTemplate(bool $checkPermissions = TRUE): \Civi\Herald\Api4\Action\RenderTemplate {
    return (new \Civi\Herald\Api4\Action\RenderTemplate(__CLASS__, 'renderTemplate'))
      ->setCheckPermissions($checkPermissions);
  }

  public static function permissions(): array {
    return [
      'meta' => ['access CiviCRM'],
      'default' => ['administer CiviCRM'],
    ];
  }

  public static function getInfo(): array {
    return parent::getInfo() + [
      'description' => 'Herald — diagnostic actions',
    ];
  }

  public static function getFields(bool $checkPermissions = TRUE): \Civi\Api4\Generic\BasicGetFieldsAction {
    return (new \Civi\Api4\Generic\BasicGetFieldsAction(__CLASS__, 'getFields', static fn() => []))
      ->setCheckPermissions($checkPermissions);
  }

}

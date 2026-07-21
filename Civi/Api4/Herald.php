<?php

namespace Civi\Api4;

/**
 * APIv4 entity for herald.
 *
 * Service-style entity (no underlying DAO).
 *
 * The supported cross-extension entry point:
 *   cv api4 Herald.send        — deliver a rendered message via a channel
 *   cv api4 Herald.getChannels — the channels and the config keys they accept
 *
 * Diagnostics:
 *   cv api4 Herald.testTelegram   — send a test message via the channel
 *   cv api4 Herald.renderTemplate — render a workflow template with sample vars
 *
 * Herald.send exists so other extensions can use herald's channels WITHOUT
 * referencing a herald class: an APIv4 action is addressed by string, which
 * keeps the dependency optional on both sides while leaving static analysis
 * intact — no class_exists() guards, no variable method names.
 *
 * @searchable none
 * @package Civi\Api4
 */
class Herald extends \Civi\Api4\Generic\AbstractEntity {

  public static function send(bool $checkPermissions = TRUE): \Civi\Herald\Api4\Action\Send {
    return (new \Civi\Herald\Api4\Action\Send(__CLASS__, 'send'))
      ->setCheckPermissions($checkPermissions);
  }

  public static function getChannels(bool $checkPermissions = TRUE): \Civi\Herald\Api4\Action\GetChannels {
    return (new \Civi\Herald\Api4\Action\GetChannels(__CLASS__, 'getChannels'))
      ->setCheckPermissions($checkPermissions);
  }

  public static function testTelegram(bool $checkPermissions = TRUE): \Civi\Herald\Api4\Action\TestTelegram {
    return (new \Civi\Herald\Api4\Action\TestTelegram(__CLASS__, 'testTelegram'))
      ->setCheckPermissions($checkPermissions);
  }

  public static function renderTemplate(bool $checkPermissions = TRUE): \Civi\Herald\Api4\Action\RenderTemplate {
    return (new \Civi\Herald\Api4\Action\RenderTemplate(__CLASS__, 'renderTemplate'))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * @return array<string, array<int, string>>
   */
  public static function permissions(): array {
    return [
      'meta' => ['access CiviCRM'],
      'default' => ['administer CiviCRM'],
    ];
  }

  /**
   * @return array<string, mixed>
   */
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

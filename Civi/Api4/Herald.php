<?php

declare(strict_types = 1);

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
 * The pipeline entry points the CiviRules adapters call — render a herald
 * template and deliver it, or answer a condition question:
 *   cv api4 Herald.notify / notifyDigest
 *   cv api4 Herald.checkFieldTransition / checkSchedule
 *
 * Buffer dead letter — buffered alerts the flush job could not deliver:
 *   cv api4 Herald.getParkedAlerts / Herald.reactivateParkedAlerts
 *
 * Diagnostics:
 *   cv api4 Herald.testTelegram   — send a test message via the channel
 *   cv api4 Herald.renderTemplate — render a workflow template with sample vars
 *
 * Herald.send is string-addressed so other extensions can depend on herald optionally.
 *
 * @searchable none
 * @package Civi\Api4
 * @mago-expect lint:too-many-methods
 */
class Herald extends \Civi\Api4\Generic\AbstractEntity {

  public static function send(bool $checkPermissions = TRUE): \Civi\Herald\Api4\Action\Send {
    return (new \Civi\Herald\Api4\Action\Send(__CLASS__, 'send'))
      ->setCheckPermissions($checkPermissions);
  }

  public static function publish(bool $checkPermissions = TRUE): \Civi\Herald\Api4\Action\Publish {
    return (new \Civi\Herald\Api4\Action\Publish(__CLASS__, 'publish'))
      ->setCheckPermissions($checkPermissions);
  }

  public static function notify(bool $checkPermissions = TRUE): \Civi\Herald\Api4\Action\Notify {
    return (new \Civi\Herald\Api4\Action\Notify(__CLASS__, 'notify'))
      ->setCheckPermissions($checkPermissions);
  }

  public static function notifyDigest(bool $checkPermissions = TRUE): \Civi\Herald\Api4\Action\NotifyDigest {
    return (new \Civi\Herald\Api4\Action\NotifyDigest(__CLASS__, 'notifyDigest'))
      ->setCheckPermissions($checkPermissions);
  }

  public static function checkFieldTransition(
    bool $checkPermissions = TRUE,
  ): \Civi\Herald\Api4\Action\CheckFieldTransition {
    return (new \Civi\Herald\Api4\Action\CheckFieldTransition(__CLASS__, 'checkFieldTransition'))
      ->setCheckPermissions($checkPermissions);
  }

  public static function checkSchedule(bool $checkPermissions = TRUE): \Civi\Herald\Api4\Action\CheckSchedule {
    return (new \Civi\Herald\Api4\Action\CheckSchedule(__CLASS__, 'checkSchedule'))
      ->setCheckPermissions($checkPermissions);
  }

  public static function getParkedAlerts(bool $checkPermissions = TRUE): \Civi\Herald\Api4\Action\GetParkedAlerts {
    return (new \Civi\Herald\Api4\Action\GetParkedAlerts(__CLASS__, 'getParkedAlerts'))
      ->setCheckPermissions($checkPermissions);
  }

  public static function reactivateParkedAlerts(
    bool $checkPermissions = TRUE,
  ): \Civi\Herald\Api4\Action\ReactivateParkedAlerts {
    return (new \Civi\Herald\Api4\Action\ReactivateParkedAlerts(__CLASS__, 'reactivateParkedAlerts'))
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
    return parent::getInfo()
    + [
      'description' => 'Herald — diagnostic actions',
    ];
  }

  public static function getFields(bool $checkPermissions = TRUE): \Civi\Api4\Generic\BasicGetFieldsAction {
    return (new \Civi\Api4\Generic\BasicGetFieldsAction(__CLASS__, 'getFields', static fn () => []))
      ->setCheckPermissions($checkPermissions);
  }

}

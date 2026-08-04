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
 *   cv api4 Herald.notify / notifyContribution / notifyDigest
 *   cv api4 Herald.checkContributionTransition / checkEventDeactivated
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

  public static function notifyContribution(bool $checkPermissions = TRUE): \Civi\Herald\Api4\Action\NotifyContribution {
    return (new \Civi\Herald\Api4\Action\NotifyContribution(__CLASS__, 'notifyContribution'))
      ->setCheckPermissions($checkPermissions);
  }

  public static function notifyDigest(bool $checkPermissions = TRUE): \Civi\Herald\Api4\Action\NotifyDigest {
    return (new \Civi\Herald\Api4\Action\NotifyDigest(__CLASS__, 'notifyDigest'))
      ->setCheckPermissions($checkPermissions);
  }

  public static function checkContributionTransition(
    bool $checkPermissions = TRUE,
  ): \Civi\Herald\Api4\Action\CheckContributionTransition {
    return (new \Civi\Herald\Api4\Action\CheckContributionTransition(__CLASS__, 'checkContributionTransition'))
      ->setCheckPermissions($checkPermissions);
  }

  public static function checkEventDeactivated(bool $checkPermissions = TRUE): \Civi\Herald\Api4\Action\CheckEventDeactivated {
    return (new \Civi\Herald\Api4\Action\CheckEventDeactivated(__CLASS__, 'checkEventDeactivated'))
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

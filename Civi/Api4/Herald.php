<?php

declare(strict_types = 1);

namespace Civi\Api4;

/**
 * APIv4 entity for herald.
 *
 * Service-style entity (no underlying DAO).
 *
 * The supported cross-extension entry points:
 *   cv api4 Herald.send        — deliver a rendered message through a channel
 *   cv api4 Herald.publish     — deliver a message to a topic
 *   cv api4 Herald.getChannelTypes — the channel types and the keys they accept
 *
 * The pipeline entry points the CiviRules adapters call — render a herald
 * template and deliver it, or answer a condition question:
 *   cv api4 Herald.notify / notifyDigest
 *   cv api4 Herald.checkFieldTransition / checkSchedule
 *
 * Buffer dead letter — buffered alerts the flush job could not deliver:
 *   cv api4 Herald.getParkedAlerts / Herald.reactivateParkedAlerts
 *
 * The digest form — placeholders of a source, a preview with today's data,
 * and that preview emailed to the logged-in user:
 *   cv api4 Herald.getDigestPlaceholders / Herald.previewDigest / Herald.sendDigestTest
 *
 * Admin screen saves, validated writes of the array settings (Setting.set
 * stays the unchecked bulk path):
 *   cv api4 Herald.getChannels / saveChannel / deleteChannel
 *   cv api4 Herald.saveTopic / deleteTopic / saveCleanedFields / saveAllowedHosts
 *   cv api4 Herald.getMonitorChecks / saveMonitorChecks
 *
 * Diagnostics:
 *   cv api4 Herald.renderTemplate — render a workflow template with sample vars
 *   cv api4 Herald.getTopicUsage  — the rules, jobs and monitor naming each topic
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

  public static function getDigestPlaceholders(
    bool $checkPermissions = TRUE,
  ): \Civi\Herald\Api4\Action\GetDigestPlaceholders {
    return (new \Civi\Herald\Api4\Action\GetDigestPlaceholders(__CLASS__, 'getDigestPlaceholders'))
      ->setCheckPermissions($checkPermissions);
  }

  public static function previewDigest(bool $checkPermissions = TRUE): \Civi\Herald\Api4\Action\PreviewDigest {
    return (new \Civi\Herald\Api4\Action\PreviewDigest(__CLASS__, 'previewDigest'))
      ->setCheckPermissions($checkPermissions);
  }

  public static function sendDigestTest(bool $checkPermissions = TRUE): \Civi\Herald\Api4\Action\SendDigestTest {
    return (new \Civi\Herald\Api4\Action\SendDigestTest(__CLASS__, 'sendDigestTest'))
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

  public static function getChannelTypes(bool $checkPermissions = TRUE): \Civi\Herald\Api4\Action\GetChannelTypes {
    return (new \Civi\Herald\Api4\Action\GetChannelTypes(__CLASS__, 'getChannelTypes'))
      ->setCheckPermissions($checkPermissions);
  }

  public static function saveChannel(bool $checkPermissions = TRUE): \Civi\Herald\Api4\Action\SaveChannel {
    return (new \Civi\Herald\Api4\Action\SaveChannel(__CLASS__, 'saveChannel'))
      ->setCheckPermissions($checkPermissions);
  }

  public static function deleteChannel(bool $checkPermissions = TRUE): \Civi\Herald\Api4\Action\DeleteChannel {
    return (new \Civi\Herald\Api4\Action\DeleteChannel(__CLASS__, 'deleteChannel'))
      ->setCheckPermissions($checkPermissions);
  }

  public static function getTopicUsage(bool $checkPermissions = TRUE): \Civi\Herald\Api4\Action\GetTopicUsage {
    return (new \Civi\Herald\Api4\Action\GetTopicUsage(__CLASS__, 'getTopicUsage'))
      ->setCheckPermissions($checkPermissions);
  }

  public static function renderTemplate(bool $checkPermissions = TRUE): \Civi\Herald\Api4\Action\RenderTemplate {
    return (new \Civi\Herald\Api4\Action\RenderTemplate(__CLASS__, 'renderTemplate'))
      ->setCheckPermissions($checkPermissions);
  }

  public static function saveTopic(bool $checkPermissions = TRUE): \Civi\Herald\Api4\Action\SaveTopic {
    return (new \Civi\Herald\Api4\Action\SaveTopic(__CLASS__, 'saveTopic'))
      ->setCheckPermissions($checkPermissions);
  }

  public static function deleteTopic(bool $checkPermissions = TRUE): \Civi\Herald\Api4\Action\DeleteTopic {
    return (new \Civi\Herald\Api4\Action\DeleteTopic(__CLASS__, 'deleteTopic'))
      ->setCheckPermissions($checkPermissions);
  }

  public static function saveCleanedFields(bool $checkPermissions = TRUE): \Civi\Herald\Api4\Action\SaveCleanedFields {
    return (new \Civi\Herald\Api4\Action\SaveCleanedFields(__CLASS__, 'saveCleanedFields'))
      ->setCheckPermissions($checkPermissions);
  }

  public static function saveAllowedHosts(bool $checkPermissions = TRUE): \Civi\Herald\Api4\Action\SaveAllowedHosts {
    return (new \Civi\Herald\Api4\Action\SaveAllowedHosts(__CLASS__, 'saveAllowedHosts'))
      ->setCheckPermissions($checkPermissions);
  }

  public static function getMonitorChecks(bool $checkPermissions = TRUE): \Civi\Herald\Api4\Action\GetMonitorChecks {
    return (new \Civi\Herald\Api4\Action\GetMonitorChecks(__CLASS__, 'getMonitorChecks'))
      ->setCheckPermissions($checkPermissions);
  }

  public static function saveMonitorChecks(bool $checkPermissions = TRUE): \Civi\Herald\Api4\Action\SaveMonitorChecks {
    return (new \Civi\Herald\Api4\Action\SaveMonitorChecks(__CLASS__, 'saveMonitorChecks'))
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

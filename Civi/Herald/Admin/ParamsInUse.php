<?php

declare(strict_types = 1);

namespace Civi\Herald\Admin;

use Civi\Herald\Subscribers\ContactRows;
use Civi\Herald\Topic\Router;
use CRM_Herald_ExtensionUtil as E;

/**
 * Who names a channel or a topic: herald rule actions, Herald scheduled jobs,
 * topic routes, settings and, for channels, buffered alerts. Deletes refuse
 * while anything does.
 */
final class ParamsInUse {

  /**
   * @return list<string> One line per user, e.g. Rule "Donations".
   */
  public static function ofChannel(string $name): array {
    $users = [];
    foreach (self::routes() as $topic => $route) {
      if (!is_array($route) || ($route['channel'] ?? NULL) !== $name) {
        continue;
      }
      $users[] = E::ts('Topic "%1"', [1 => (string) $topic]);
    }
    $users = array_merge(
      $users,
      DeliveryParams::labelsWhere('channel', static fn (mixed $named): bool => $named === $name),
      BufferedDeliveries::naming($name),
    );
    if (\Civi::settings()->get(ContactRows::EMAIL_CHANNEL_SETTING) === $name) {
      $users[] = E::ts('The contact email channel setting');
    }
    return $users;
  }

  /**
   * Topics without a route of their own use "default".
   *
   * @return list<string>
   */
  public static function ofTopic(string $topic): array {
    $routes = self::routes();
    $uses = static fn (mixed $named): bool => (
      is_string($named)
      && $named !== ''
      && (isset($routes[$named]) ? $named : 'default') === $topic
    );
    $users = DeliveryParams::labelsWhere('topic', $uses);
    if ($uses(\Civi::settings()->get('herald_monitor_topic'))) {
      $users[] = E::ts('The monitor');
    }
    return $users;
  }

  /**
   * @return array<array-key, mixed>
   */
  private static function routes(): array {
    $map = \Civi::settings()->get(Router::SETTING);
    return is_array($map) ? $map : [];
  }

}

<?php

declare(strict_types = 1);

use Civi\Herald\Dispatch\ChannelConfig;
use Civi\Herald\Topic\RouteValidator;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * The delivery params a send action form saves, and the checks they get first.
 */
final class CRM_Herald_CivirulesAdapter_SendParams {

  /**
   * The checks of a topic route, on the submitted text; a blank field is unset.
   *
   * @param array<string, mixed> $values
   * @return list<string>
   */
  public static function errors(array $values): array {
    $submitted = array_filter(
      array_map(static fn (mixed $value): mixed => is_string($value) ? trim($value) : $value, $values),
      static fn (mixed $value): bool => $value !== '',
    );
    $submitted['channel_config'] = ChannelConfig::decode($values['channel_config'] ?? NULL);
    return (new RouteValidator())->paramErrors($submitted);
  }

  /**
   * @param array<string, mixed> $values Submitted values that passed errors()
   * @return array<string, mixed>
   */
  public static function of(array $values): array {
    $text = static fn (string $key): string => trim(Cast::toString($values[$key] ?? ''));
    return [
      'delivery_mode' => $text('delivery_mode'),
      'subscription_type' => $text('subscription_type'),
      'group_id' => Cast::toInt($text('group_id')),
      'email_addresses' => $text('email_addresses'),
      'respect_suspension' => !empty($values['respect_suspension']),
      'email_location_type' => Cast::toInt($text('email_location_type')),
      'scope_entity' => $text('scope_entity'),
      'channel' => $text('channel'),
      'chat_id' => $text('chat_id'),
      'parse_mode' => $text('parse_mode'),
      'disable_notification' => !empty($values['disable_notification']),
      'disable_web_page_preview' => !empty($values['disable_web_page_preview']),
      'aggregate_minutes' => Cast::toInt($text('aggregate_minutes')),
      'channel_config' => ChannelConfig::decode($values['channel_config'] ?? NULL),
    ];
  }

}

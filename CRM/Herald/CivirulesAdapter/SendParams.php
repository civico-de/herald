<?php

declare(strict_types = 1);

use Civi\Herald\Topic\RouteValidator;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * The params a send action form saves, and the checks they get first: a
 * topic, or a delivery of its own with the keys of the channel types.
 */
final class CRM_Herald_CivirulesAdapter_SendParams {

  /**
   * @param array<string, mixed> $values
   * @return list<string>
   */
  public static function errors(array $values): array {
    $params = self::of($values);
    return (
      Cast::toString($values['send_via'] ?? '') === 'topic' && !isset($params['topic'])
        ? [E::ts('Choose a topic.')]
        : (new RouteValidator())->actionErrors($params)
    );
  }

  /**
   * The params to store; blank and hidden fields are left out, so the
   * channel's defaults apply.
   *
   * @param array<string, mixed> $values Submitted form values
   * @return array<string, mixed>
   */
  public static function of(array $values): array {
    $text = static fn (string $key): string => trim(Cast::toString($values[$key] ?? ''));
    // Text that is no number stays text, so errors() names it instead of saving 0.
    $int = static fn (string $key): int|string => preg_match('/^-?\d+$/', $text($key)) === 1
      ? (int) $text($key)
      : $text($key);
    $params = ['scope_entity' => $text('scope_entity')];
    if ($text('send_via') === 'topic') {
      return array_filter($params + ['topic' => $text('topic')]);
    }
    $params += [
      'channel' => $text('channel'),
      'delivery_mode' => $text('delivery_mode'),
      'subscription_type' => $text('subscription_type'),
      'group_id' => $int('group_id'),
      'email_addresses' => $text('email_addresses'),
      'respect_suspension' => !empty($values['respect_suspension']),
      'email_location_type' => $int('email_location_type'),
      'aggregate_minutes' => $int('aggregate_minutes'),
    ];
    $params = array_filter(
      $params,
      static fn (mixed $value): bool => $value !== '' && $value !== FALSE && $value !== 0,
    );
    // Blank keeps the channel's value; a chosen 0 or "no" overrides it. Hidden fields are not saved.
    foreach (CRM_Herald_CivirulesAdapter_ChannelFieldSpecs::shown(
      $text('delivery_mode'),
      $text('channel'),
    ) as $key => $spec) {
      $params[$key] = match (TRUE) {
        $text($key) === '' => '',
        $spec['type'] === 'bool' => $text($key) === '1',
        $spec['type'] === 'int' => $int($key),
        default => $text($key),
      };
    }
    return array_filter($params, static fn (mixed $value): bool => $value !== '');
  }

  /**
   * The delivery keys of every channel type a rule may set: no channel keys,
   * no subject (the template brings it).
   *
   * @return array<string, array{label: string, type: string, types: list<string>, recipient: bool, options?: array<int|string, string>}>
   */
  public static function channelFields(): array {
    return CRM_Herald_CivirulesAdapter_ChannelFieldSpecs::all();
  }

}

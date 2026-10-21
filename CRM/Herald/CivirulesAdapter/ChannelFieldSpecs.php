<?php

declare(strict_types = 1);

use Civi\Herald\Channel\ChannelRegistry;

/**
 * The delivery keys of every channel type a send action rule may set,
 * merged across the types that share a key.
 */
final class CRM_Herald_CivirulesAdapter_ChannelFieldSpecs {

  /**
   * No channel keys, no subject (the template brings it).
   *
   * @return array<string, array{label: string, type: string, types: list<string>, recipient: bool, options?: array<int|string, string>}>
   */
  public static function all(): array {
    $registry = ChannelRegistry::default();
    $fields = [];
    foreach ($registry->listKeys() as $type) {
      $channel = $registry->get($type);
      $subject = $channel->getSemanticFields()['subject'] ?? NULL;
      foreach ($channel->getConfigSchema() as $key => $spec) {
        if ($key === $subject || ($spec['scope'] ?? 'delivery') === 'channel') {
          continue;
        }
        $fields[$key] ??=
          ['label' => $spec['label'], 'type' => $spec['type'], 'types' => [], 'recipient' => FALSE]
          + (isset($spec['options']) ? ['options' => $spec['options']] : []);
        $fields[$key]['types'][] = $type;
        $fields[$key]['recipient'] = $fields[$key]['recipient'] || !empty($spec['recipient']);
      }
    }
    return $fields;
  }

  /**
   * The keys the form shows for a delivery: the chosen channel's type, or in a
   * fan-out, where each contact has their own channel, every non-recipient key.
   *
   * @return array<string, array{label: string, type: string, types: list<string>, recipient: bool, options?: array<int|string, string>}>
   */
  public static function shown(string $mode, string $channel): array {
    $type = CRM_Herald_CivirulesAdapter_SendOptions::channelTypes()[$channel] ?? '';
    $fanOut = in_array($mode, ['subscribers', 'group'], TRUE);
    return array_filter(
      self::all(),
      static fn (array $spec): bool => $fanOut ? !$spec['recipient'] : in_array($type, $spec['types'], TRUE),
    );
  }

}

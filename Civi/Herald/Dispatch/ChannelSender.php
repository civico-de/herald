<?php

declare(strict_types = 1);

namespace Civi\Herald\Dispatch;

use Civi\Herald\Channel\ChannelRegistry;
use Civi\Herald\Channel\ChannelStore;

/**
 * Sends one body through a configured channel by its name.
 */
final class ChannelSender {

  private ChannelRegistry $registry;

  private ChannelStore $store;

  public function __construct(?ChannelRegistry $registry = NULL, ?ChannelStore $store = NULL) {
    $this->registry = $registry ?? ChannelRegistry::default();
    $this->store = $store ?? new ChannelStore($this->registry);
  }

  /**
   * @param array<string, mixed> $delivery
   */
  public function send(string $name, string $body, array $delivery): void {
    $record = $this->store->get($name);
    $channel = $this->registry->get($record['type']);
    $channel->send($body, ChannelConfig::merged($channel, $record['config'], $delivery));
  }

  /**
   * Send to a recipient of its own: the channel's stored recipient keys (a
   * default chat, a default cc) stay out, so only the delivery addresses it.
   *
   * @param array<string, mixed> $delivery
   */
  public function sendToRecipient(string $name, string $body, array $delivery): void {
    $record = $this->store->get($name);
    $channel = $this->registry->get($record['type']);
    $schema = $channel->getConfigSchema();
    $stored = array_filter(
      $record['config'],
      static fn (string $key): bool => empty($schema[$key]['recipient']),
      ARRAY_FILTER_USE_KEY,
    );
    $channel->send($body, ChannelConfig::merged($channel, $stored, $delivery));
  }

  public function typeOf(string $name): string {
    return $this->store->typeOf($name);
  }

}

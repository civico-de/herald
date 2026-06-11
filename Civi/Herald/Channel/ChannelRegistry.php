<?php

namespace Civi\Herald\Channel;

class ChannelRegistry {

  /** @var array<string, ChannelInterface> */
  private array $channels = [];

  public function register(ChannelInterface $channel): void {
    $this->channels[$channel->getKey()] = $channel;
  }

  public function get(string $key): ChannelInterface {
    if (!isset($this->channels[$key])) {
      throw new \CRM_Core_Exception(sprintf('herald: Channel "%s" is not registered.', $key));
    }
    return $this->channels[$key];
  }

  public function has(string $key): bool {
    return isset($this->channels[$key]);
  }

  /** @return string[] */
  public function listKeys(): array {
    return array_keys($this->channels);
  }

  public static function default(): self {
    static $instance = NULL;
    if ($instance === NULL) {
      $instance = new self();
      $instance->register(new TelegramChannel());
      $instance->register(new WebhookChannel());
      $instance->register(new NtfyChannel());
      $instance->register(new PushoverChannel());
    }
    return $instance;
  }

}

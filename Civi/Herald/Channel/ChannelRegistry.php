<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

use CRM_Herald_ExtensionUtil as E;

class ChannelRegistry {

  private const BUILT_IN = [
    TelegramChannel::class,
    WebhookChannel::class,
    NtfyChannel::class,
    PushoverChannel::class,
    EmailChannel::class,
    ActivityChannel::class,
  ];

  /**
   * @var array<string, ChannelInterface> */
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

  /**
   * @return string[] */
  public function listKeys(): array {
    return array_keys($this->channels);
  }

  /**
   * The keys only a configured channel may set (tokens, servers, sender).
   *
   * @return list<string>
   */
  public function channelKeys(): array {
    $keys = [];
    foreach ($this->channels as $channel) {
      foreach ($channel->getConfigSchema() as $key => $spec) {
        if (($spec['scope'] ?? 'delivery') !== 'channel') {
          continue;
        }
        $keys[] = $key;
      }
    }
    return array_values(array_unique($keys));
  }

  /**
   * Human-readable channel name; a channel without one is shown by its key.
   */
  public static function label(string $key): string {
    return match ($key) {
      'telegram' => 'Telegram',
      'webhook' => E::ts('Generic webhook (Slack/Discord/Mattermost/custom)'),
      'ntfy' => 'ntfy.sh',
      'pushover' => 'Pushover',
      'email' => E::ts('Email'),
      'activity' => E::ts('CiviCRM activity'),
      default => $key,
    };
  }

  public static function default(): self {
    /** @var self|null $instance */
    static $instance = NULL;
    if ($instance !== NULL) {
      return $instance;
    }
    $instance = new self();
    foreach (self::BUILT_IN as $class) {
      $instance->register(new $class());
    }
    return $instance;
  }

}

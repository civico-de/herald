<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * The X-* metadata headers of an ntfy publish, read from the delivery's
 * config: title, priority, tags, click URL and the markdown switch.
 */
final class NtfyHeaders {

  /**
   * @param array<string, mixed> $config
   * @return array<string, string>
   */
  public static function of(array $config): array {
    $h = ['Content-Type' => 'text/plain; charset=utf-8'];
    if (!empty($config['ntfy_title'])) {
      $h['X-Title'] = Cast::toString($config['ntfy_title']);
    }
    if (isset($config['ntfy_priority']) && $config['ntfy_priority'] !== '') {
      $h['X-Priority'] = Cast::toString($config['ntfy_priority']);
    }
    if (!empty($config['ntfy_tags'])) {
      $h['X-Tags'] = Cast::toString($config['ntfy_tags']);
    }
    if (!empty($config['ntfy_click'])) {
      $h['X-Click'] = Cast::toString($config['ntfy_click']);
    }
    if (!empty($config['ntfy_markdown'])) {
      $h['X-Markdown'] = 'yes';
    }
    return $h;
  }

}

<?php

declare(strict_types = 1);

use Civi\Herald\Dispatch\ChannelConfig;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Subject and delivery settings of an old inline digest: the subject moves into its template.
 */
final class CRM_Herald_Upgrader_InlineDelivery {

  private const CONTENT_KEYS = [
    'header',
    'period',
    'blocks',
    'template',
    'type',
    'title',
    'search',
    'key',
    'campaign_id',
    'financial_type_id',
    'source_pattern',
    'group_by',
    'show_pending',
    'show_histogram',
    'bucket_count',
    'bucket_edges',
    'status',
    'email_subject',
  ];

  /**
   * An explicit email_subject named the message before, else the only block, else the title.
   *
   * @param array<string, mixed> $params
   * @param list<array<string, mixed>> $blocks
   * @param list<array<string, mixed>> $sources
   */
  public static function subject(array $params, array $blocks, array $sources, string $title): string {
    $subject = trim(Cast::toString(ChannelConfig::of($params)['email_subject'] ?? ''));
    if ($subject !== '') {
      return CRM_Herald_Upgrader_InlineTemplate::literal($subject);
    }
    if (count($blocks) === 1) {
      $subject = CRM_Herald_Upgrader_InlineTemplate::subject(
        Cast::toString($blocks[0]['type']),
        Cast::toString($blocks[0]['title'] ?? ''),
        'block1',
        isset($sources[0]['search_display_id']) ? Cast::toInt($sources[0]['search_display_id']) : NULL,
      );
    }
    return $subject !== '' ? $subject : CRM_Herald_Upgrader_InlineTemplate::literal($title);
  }

  /**
   * The delivery settings; the subject moved into the template.
   *
   * @param array<string, mixed> $params
   * @return array<string, mixed>
   */
  public static function of(array $params): array {
    $delivery = array_diff_key($params, array_flip(self::CONTENT_KEYS));
    $config = $delivery['channel_config'] ?? NULL;
    $decoded = is_string($config) ? json_decode($config, TRUE) : $config;
    if (is_array($decoded) && array_key_exists('email_subject', $decoded)) {
      unset($decoded['email_subject']);
      $delivery['channel_config'] = is_string($config) ? json_encode($decoded, JSON_THROW_ON_ERROR) : $decoded;
    }
    return $delivery;
  }

}

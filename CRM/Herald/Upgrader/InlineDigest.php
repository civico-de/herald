<?php

declare(strict_types = 1);

use Civi\Herald\Digest\BlockType;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Digests that older releases kept inside rule action and job parameters
 * become HeraldDigest records; the parameters keep only delivery settings and `digest`.
 */
final class CRM_Herald_Upgrader_InlineDigest {

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
  ];

  /**
   * @param array<array-key, mixed> $params
   */
  public static function isInline(array $params): bool {
    return !array_key_exists('digest', $params);
  }

  /**
   * Store the digest of $params as $name; NULL, with a warning, when it could never have run.
   *
   * @param array<string, mixed> $params
   * @return array<string, mixed>|null The params with the digest content replaced by `digest`
   */
  public static function extract(array $params, string $name, string $title): ?array {
    $blocks = CRM_Herald_Upgrader_InlineDigestBlocks::parse($params, $name);
    $problems = is_string($blocks) ? [$blocks] : self::problems($blocks);
    if (is_string($blocks) || $problems !== []) {
      \Civi::log('herald')->warning('herald: digest left inline, it cannot run until fixed', [
        'digest' => $name,
        'problems' => $problems,
      ]);
      return NULL;
    }
    CRM_Core_Transaction::create()->run(static function () use ($params, $name, $title, $blocks): void {
      $digestId = \Civi\Api4\HeraldDigest::create(checkPermissions: FALSE)
        ->addValue('name', $name)
        ->addValue('title', $title)
        ->addValue('header', Cast::toString($params['header'] ?? ''))
        ->addValue('period', CRM_Herald_Upgrader_InlinePeriod::read($params['period'] ?? NULL, $name))
        ->execute()
        ->single()['id'];
      $label = "Herald: {$title}";
      \Civi\Api4\HeraldDigestBlock::save(checkPermissions: FALSE)
        ->setRecords(array_map(static fn (array $block): array => CRM_Herald_Upgrader_InlineSearch::record(
          $block,
          $label,
        ), $blocks))
        ->setDefaults(['digest_id' => $digestId])
        ->execute();
    });
    return ['digest' => $name] + array_diff_key($params, array_flip(self::CONTENT_KEYS));
  }

  /**
   * @param list<array<string, mixed>> $blocks
   * @return list<string>
   */
  private static function problems(array $blocks): array {
    $problems = array_map(
      static fn (array $block): ?string => (
        CRM_Herald_Upgrader_InlineSearch::problem($block)
        ?? BlockType::problem(CRM_Herald_Upgrader_InlineSearch::forCheck($block))
        ?? self::missingReference($block)
      ),
      $blocks,
    );
    return array_values(array_filter($problems));
  }

  /**
   * A campaign or financial type deleted since: its filter matched nothing, dropping it would match everything.
   *
   * @param array<string, mixed> $block
   */
  private static function missingReference(array $block): ?string {
    // The tables, not the API: Campaign has no table name while CiviCampaign is disabled.
    foreach ([
      'campaign_id' => 'civicrm_campaign',
      'financial_type_id' => 'civicrm_financial_type',
    ] as $field => $table) {
      $id = Cast::toInt($block[$field] ?? 0);
      if (
        $id > 0
        && !CRM_Utils_SQL_Select::from($table)->select('id')->where('id = #id', ['id' => $id])->execute()->fetchValue()
      ) {
        return "{$field} {$id} does not exist";
      }
    }
    return NULL;
  }

}

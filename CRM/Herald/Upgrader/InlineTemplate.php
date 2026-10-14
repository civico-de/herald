<?php

declare(strict_types = 1);

use Civi\Herald\Digest\ExtraSections;
use Civi\Herald\Digest\SearchSource;
use Civi\Herald\Digest\Snippet;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * The template text an old digest block becomes: the form's snippets of its
 * sources, under the block title.
 */
final class CRM_Herald_Upgrader_InlineTemplate {

  /**
   * @param array<string, mixed> $block
   * @param array<string, int> $displays From CRM_Herald_Upgrader_InlineSources::contribution()
   */
  public static function contribution(array $block, string $prefix, array $displays): string {
    $totals = self::search($prefix, $displays[$prefix]);
    if (isset($displays["{$prefix}_campaign"])) {
      $goal = '$' . "{$prefix}_campaign." . self::fields($displays["{$prefix}_campaign"])[0];
      $sum = '$' . "{$prefix}_campaign_total." . self::fields($displays["{$prefix}_campaign_total"])[0];
      $totals .= "{if {$goal} gt 0} · {({$sum} * 100 / {$goal})|round:1}% " . E::ts('of') . " {{$goal}|crmMoney}{/if}";
    }
    $lines = [$totals, self::search("{$prefix}_breakdown", $displays["{$prefix}_breakdown"])];
    if (isset($displays["{$prefix}_pending"])) {
      $lines[] = '🏦 ' . E::ts('Pending') . ': ' . self::search("{$prefix}_pending", $displays["{$prefix}_pending"]);
    }
    if (isset($displays["{$prefix}_amounts"])) {
      $column = self::fields($displays["{$prefix}_amounts"])[0];
      $buckets = match (TRUE) {
        isset($block['bucket_edges']) => ':"' . Cast::toString($block['bucket_edges']) . '"',
        $block['bucket_count'] !== 8 => ':' . Cast::toInt($block['bucket_count']),
        default => '',
      };
      $lines[] = "<pre>{\${$prefix}_amounts.rows|herald_histogram:\"{$column}\"{$buckets}}</pre>";
    }
    return implode("\n", $lines);
  }

  public static function search(string $name, int $displayId): string {
    return Snippet::search($name, SearchSource::placeholders($displayId))['snippet'];
  }

  /**
   * @throws \Civi\Herald\Digest\DigestProblem when the extension still answers with text
   */
  public static function section(string $name, string $key, string $digestName): string {
    return Snippet::section($name, ExtraSections::answer($key, ['digest' => $digestName]))['snippet'];
  }

  /**
   * The subject a single old block gave the message.
   */
  public static function subject(string $type, string $title, string $prefix, ?int $displayId): string {
    $title = self::literal($title);
    $values = match ($type) {
      'contribution' => (static function () use ($prefix, $displayId): string {
        [$sum, $count] = self::fields(Cast::toInt($displayId));
        return "{\${$prefix}.{$count}} · {\${$prefix}.{$sum}|crmMoney}";
      })(),
      'search' => "{\${$prefix}.rows|count}",
      default => '',
    };
    return implode(': ', array_filter([$title, $values], static fn (string $part): bool => $part !== ''));
  }

  /**
   * Text as it stands in a template: HTML-escaped, braces kept from Smarty.
   */
  public static function text(string $text): string {
    return self::literal(htmlspecialchars($text, ENT_NOQUOTES));
  }

  /**
   * Older releases put header and titles into the message without Smarty.
   */
  public static function literal(string $text): string {
    return str_contains($text, '{') || str_contains($text, '}') ? "{literal}{$text}{/literal}" : $text;
  }

  /**
   * @return list<string>
   */
  private static function fields(int $displayId): array {
    return array_column(SearchSource::placeholders($displayId)['fields'], 'name');
  }

}

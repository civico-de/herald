<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

/**
 * A text bar chart for a <pre> block: each line is its label columns, its value
 * right-aligned and a bar in eighth blocks, so a small value still shows.
 */
final class TextBars {

  private const EIGHTHS = ['', '▏', '▎', '▍', '▌', '▋', '▊', '▉'];

  private const GAP = '   ';

  /**
   * @param list<array{labels: list<string>, value: string, size: float}> $lines
   *   Labels and values may hold HTML entities; sizes below zero count as zero.
   */
  public static function render(array $lines, int $maxBlocks = 12): string {
    $max = max([0.0, ...array_column($lines, 'size')]);
    $labelWidths = [];
    foreach ($lines as $line) {
      foreach ($line['labels'] as $column => $label) {
        $labelWidths[$column] = max($labelWidths[$column] ?? 0, self::width($label));
      }
    }
    $valueWidth = max([0, ...array_map(static fn (array $line): int => self::width($line['value']), $lines)]);
    return implode("\n", array_map(static fn (array $line): string => rtrim(
      implode('', array_map(
        static fn (int $column, string $label): string => self::pad($label, $labelWidths[$column]) . self::GAP,
        array_keys($line['labels']),
        $line['labels'],
      ))
        . str_repeat(' ', $valueWidth - self::width($line['value']))
        . $line['value']
        . '  '
        . self::bar($line['size'], $max, $maxBlocks),
    ), $lines));
  }

  private static function pad(string $text, int $width): string {
    return $text . str_repeat(' ', $width - self::width($text));
  }

  private static function bar(float $size, float $max, int $maxBlocks): string {
    if ($size <= 0 || $max <= 0) {
      return '';
    }
    $eighths = max(1, (int) round(($size / $max) * $maxBlocks * 8));
    return str_repeat('█', intdiv($eighths, 8)) . self::EIGHTHS[$eighths % 8];
  }

  /**
   * Columns a text takes in a <pre> block, counting an entity as one character.
   */
  private static function width(string $text): int {
    return mb_strlen(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
  }

}

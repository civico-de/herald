<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Test\HeadlessInterface;
use PHPUnit\Framework\TestCase;

/**
 * Headless: problems are translated.
 *
 * @group headless
 */
class PlaceholdersTest extends TestCase implements HeadlessInterface {

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  /**
   * @return array<string, array{string, string}>
   */
  public static function headers(): array {
    return [
      'plain' => ['Summe', 'summe'],
      'umlaut and space' => ['Plätze gesamt', 'plaetze_gesamt'],
      'sharp s' => ['Größe', 'groesse'],
      'other accents' => ['Montant reçu é', 'montant_recu_e'],
      'punctuation' => ['  Anzahl (gesamt) — 2026!  ', 'anzahl_gesamt_2026'],
      'capitals' => ['ÄRGER', 'aerger'],
      'no letters' => ['–', ''],
    ];
  }

  /**
   * @dataProvider headers
   */
  public function testAHeaderBecomesAName(string $header, string $name): void {
    self::assertSame($name, Placeholders::slug($header));
  }

  public function testInvalidUtf8KeepsWhatItCan(): void {
    self::assertSame('summe', Placeholders::slug("Summe\xff"));
  }

  public function testNamesAreKeyedByColumnAndBadHeadersAreProblems(): void {
    self::assertSame(
      [
        'names' => [0 => 'summe', 3 => 'anzahl'],
        'problems' => [
          'two columns are named summe; give their headers different words.',
          'the column "Rows" is named rows, which holds the list of rows; rename its header.',
          'the column "2. Quartal" starts with a digit; start its header with a letter.',
        ],
      ],
      Placeholders::fromLabels([0 => 'Summe', 1 => '', 2 => 'summe', 3 => 'Anzahl', 4 => 'Rows', 5 => '2. Quartal']),
    );
  }

}

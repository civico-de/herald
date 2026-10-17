<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Api4\Afform;
use Civi\Api4\HeraldDigest;
use Civi\Api4\HeraldDigestSource;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Digests and sources that could not be sent are refused on save, through
 * the API and the digest form.
 *
 * @group headless
 */
class ValidatorTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  private const SEATS = ['name' => 'seats', 'type' => 'section', 'section_key' => 'test.seats'];

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  /**
   * @param array<string> $keys
   * @param array<string, mixed> $context
   * @param array<string, mixed> $sections
   */
  public function hook_civicrm_heraldDigestSections(array $keys, array $context, array &$sections): void {
    $sections['test.seats'] = ['booked' => 138];
  }

  private function assertRefused(string $message, callable $save): void {
    try {
      $save();
    }
    catch (\CRM_Core_Exception $e) {
      self::assertStringContainsString($message, $e->getMessage());
      return;
    }
    self::fail("Saved although: {$message}");
  }

  private function display(): int {
    $search = \Civi\Api4\SavedSearch::create(checkPermissions: FALSE)
      ->setValues([
        'name' => 'herald_test_members',
        'label' => 'Members',
        'api_entity' => 'Contact',
        'api_params' => ['version' => 4, 'select' => ['id']],
      ])
      ->execute()
      ->single()['id'];
    return (int) \Civi\Api4\SearchDisplay::create(checkPermissions: FALSE)
      ->setValues([
        'saved_search_id' => $search,
        'label' => 'Members',
        'type' => 'table',
        'settings' => ['columns' => [['type' => 'field', 'key' => 'id', 'label' => 'Count']]],
      ])
      ->execute()
      ->single()['id'];
  }

  public function testAHealthyDigestAndItsSourcesAreSaved(): void {
    $digest = DigestFixture::create('weekly', 'Weekly');
    HeraldDigest::update(checkPermissions: FALSE)
      ->addWhere('id', '=', $digest['id'])
      ->addValue('title', 'Weekly report')
      ->execute();
    $source = (int) HeraldDigestSource::create(checkPermissions: FALSE)
      ->setValues([
        'digest_id' => $digest['id'],
        'name' => 'members',
        'type' => 'search',
        'search_display_id' => $this->display(),
      ])
      ->execute()
      ->single()['id'];
    HeraldDigestSource::update(checkPermissions: FALSE)
      ->addWhere('id', '=', $source)
      ->addValue('name', 'people')
      ->execute();
    self::assertSame(
      ['people'],
      HeraldDigestSource::get(checkPermissions: FALSE)
        ->addWhere('digest_id', '=', $digest['id'])
        ->execute()
        ->column('name'),
    );
  }

  /**
   * @return array<string, array{array<string, mixed>, string}>
   */
  public static function brokenDigests(): array {
    return [
      'name with spaces' => [['name' => 'Weekly report'], 'Use lowercase letters'],
      'no text' => [['name' => 'weekly'], 'Write the text of the digest.'],
      'a blank text' => [['name' => 'weekly', 'msg_html' => " \n"], 'Write the text of the digest.'],
    ];
  }

  /**
   * @dataProvider brokenDigests
   * @param array<string, mixed> $values
   */
  public function testABrokenDigestIsRefused(array $values, string $message): void {
    $this->assertRefused($message, static fn () => HeraldDigest::create(checkPermissions: FALSE)
      ->setValues($values + ['title' => 'Weekly'])
      ->execute());
  }

  public function testAnUpdateKeepsTheTextItDoesNotWrite(): void {
    $id = DigestFixture::create('weekly', 'Weekly')['id'];
    HeraldDigest::update(checkPermissions: FALSE)->addWhere('id', '=', $id)->addValue('title', 'Renamed')->execute();
    $this->assertRefused(
      'Write the text of the digest.',
      static fn () => HeraldDigest::update(checkPermissions: FALSE)
        ->addWhere('id', '=', $id)
        ->addValue('msg_html', '')
        ->execute(),
    );
  }

  public function testSubjectAndTextAreStoredAsWritten(): void {
    $text = "<b>{\$seats.booked}</b> &lt;3 {if \$seats.booked > 2}full{/if}";
    $subject = '{if $seats.booked > 2}Full{/if} <seats>';
    $id = DigestFixture::create('weekly', 'Weekly', $text, subject: $subject)['id'];
    $stored = HeraldDigest::get(checkPermissions: FALSE)->addWhere('id', '=', $id)->execute()->single();
    self::assertSame([$subject, $text], [$stored['msg_subject'], $stored['msg_html']]);
  }

  public function testANameIsUsedByOneDigestOnly(): void {
    DigestFixture::create('weekly', 'Weekly');
    $other = DigestFixture::create('daily', 'Daily')['id'];
    $this->assertRefused(
      'Another digest is already named "weekly"',
      static fn () => HeraldDigest::update(checkPermissions: FALSE)
        ->addWhere('id', '=', $other)
        ->addValue('name', 'weekly')
        ->execute(),
    );
  }

  public function testABatchUpdateIsCheckedForEveryDigest(): void {
    DigestFixture::create('weekly', 'Weekly');
    DigestFixture::create('daily', 'Daily');
    $this->assertRefused(
      'Another digest is already named "weekly"',
      static fn () => HeraldDigest::update(checkPermissions: FALSE)
        ->addWhere('name', 'IN', ['weekly', 'daily'])
        ->addValue('name', 'weekly')
        ->execute(),
    );
  }

  /**
   * @return array<string, array{array<string, mixed>, string}>
   */
  public static function brokenSources(): array {
    return [
      'a reserved name' => [['name' => 'smarty'] + self::SEATS, '"smarty" is a name Smarty reserves'],
      'a name with a capital' => [['name' => 'Seats'] + self::SEATS, 'A source name starts with a lowercase letter'],
      'an unknown type' => [['type' => 'contribution'] + self::SEATS, 'Unknown type "contribution".'],
      'a section without key' => [['section_key' => ''] + self::SEATS, 'A section source needs a section key.'],
      'a search without display' => [['type' => 'search'] + self::SEATS, 'A search source needs a search display.'],
    ];
  }

  /**
   * @dataProvider brokenSources
   * @param array<string, mixed> $values
   */
  public function testABrokenSourceIsRefused(array $values, string $message): void {
    $digest = DigestFixture::create('weekly', 'Weekly')['id'];
    $this->assertRefused($message, static fn () => HeraldDigestSource::create(checkPermissions: FALSE)
      ->setValues(['digest_id' => $digest] + $values)
      ->execute());
  }

  public function testAnUpdateIsCheckedTogetherWithTheStoredSource(): void {
    $digest = DigestFixture::create('weekly', 'Weekly', sources: [self::SEATS]);
    $this->assertRefused(
      'A search source needs a search display',
      static fn () => HeraldDigestSource::update(checkPermissions: FALSE)
        ->addWhere('digest_id', '=', $digest['id'])
        ->addValue('type', 'search')
        ->execute(),
    );
  }

  public function testASourceNameIsUsedOnceInADigest(): void {
    $weekly = DigestFixture::create('weekly', 'Weekly', sources: [self::SEATS]);
    DigestFixture::create('daily', 'Daily', sources: [self::SEATS]);
    $this->assertRefused(
      'The digest has another source named seats.',
      static fn () => HeraldDigestSource::create(checkPermissions: FALSE)
        ->setValues(['digest_id' => $weekly['id']] + self::SEATS)
        ->execute(),
    );
  }

  public function testATextEditIsCheckedAgainstTheStoredSources(): void {
    $id = DigestFixture::create('weekly', 'Weekly', '{$seats.booked} seats', [self::SEATS])['id'];
    HeraldDigest::update(checkPermissions: FALSE)
      ->addWhere('id', '=', $id)
      ->addValue('msg_html', 'Booked: {$seats.booked}')
      ->execute();
    $this->assertRefused(
      'Digest "Weekly": {$seats.bookd} is not a placeholder of this digest.',
      static fn () => HeraldDigest::update(checkPermissions: FALSE)
        ->addWhere('id', '=', $id)
        ->addValue('msg_subject', '{$seats.bookd} seats')
        ->execute(),
    );
  }

  public function testATextStaysEditableWhileASourceIsBroken(): void {
    $id = DigestFixture::create('weekly', 'Weekly', '{$members.count}', [
      ['name' => 'members', 'type' => 'search', 'search_display_id' => $this->display()],
    ])['id'];
    \Civi\Api4\SearchDisplay::delete(checkPermissions: FALSE)->addWhere('label', '=', 'Members')->execute();

    HeraldDigest::update(checkPermissions: FALSE)
      ->addWhere('id', '=', $id)
      ->addValue('msg_html', '{$members.cuont}')
      ->execute();
    self::assertSame('{$members.cuont}', Definition::load('weekly')['msg_html']);
  }

  public function testOnlyTheTextTheFormComposedSkipsTheApiCheck(): void {
    $id = DigestFixture::create('weekly', 'Weekly', '{$seats.booked} seats', [self::SEATS])['id'];
    $this->submit(
      ['name' => 'weekly', 'title' => 'Weekly', 'msg_html' => '{$places.booked} places'],
      [['name' => 'places'] + self::SEATS],
      ['HeraldDigest1' => $id],
    );
    $this->assertRefused(
      '{$places.bookd} is not a placeholder of this digest.',
      static fn () => HeraldDigest::update(checkPermissions: FALSE)
        ->addWhere('id', '=', $id)
        ->addValue('msg_html', '{$places.bookd} places')
        ->execute(),
    );
  }

  /**
   * @param array<string, mixed> $fields
   * @param list<array<string, mixed>> $sources
   * @param array<string, mixed> $args
   */
  private function submit(array $fields, array $sources, array $args = []): void {
    // The form fills the schema defaults into every new source, shown or not.
    $withDefaults = static fn (array $source): array => $source + FormSettings::angular()['sourceDefaults'];
    Afform::submit(checkPermissions: FALSE)
      ->setName('afformHeraldDigest')
      ->setArgs($args)
      ->setValues([
        'HeraldDigest1' => [[
          'fields' => $fields,
          'joins' => ['HeraldDigestSource' => array_map($withDefaults, $sources)],
        ]],
      ])
      ->execute();
  }

  private static function digestCount(string $name): int {
    return HeraldDigest::get(checkPermissions: FALSE)->addWhere('name', '=', $name)->execute()->count();
  }

  public function testTheFormSavesADigestWithItsSources(): void {
    $this->submit([
      'name' => 'weekly',
      'title' => 'Weekly',
      'msg_html' => '{$seats.booked}',
    ], [
      ['name' => 'seats', 'type' => 'section', 'section_key' => 'test.seats'],
    ]);
    $id = HeraldDigest::get(checkPermissions: FALSE)->addWhere('name', '=', 'weekly')->execute()->single()['id'];
    self::assertSame(
      ['test.seats'],
      HeraldDigestSource::get(checkPermissions: FALSE)
        ->addWhere('digest_id', '=', $id)
        ->execute()
        ->column('section_key'),
    );
  }

  public function testTheFormRefusesABrokenSourceBeforeWritingTheDigest(): void {
    $this->assertRefused('Source 2: A search source needs a search display.', fn () => $this->submit(
      ['name' => 'weekly', 'title' => 'Weekly', 'msg_html' => '{$seats.booked}'],
      [self::SEATS, ['name' => '', 'type' => 'search']],
    ));
    self::assertSame(0, self::digestCount('weekly'));
  }

  public function testTheFormChecksTheTextAgainstTheSubmittedSources(): void {
    $this->assertRefused('{$seats.bookd} is not a placeholder of this digest.', fn () => $this->submit(
      ['name' => 'weekly', 'title' => 'Weekly', 'msg_html' => '{$seats.bookd}'],
      [self::SEATS],
    ));
    self::assertSame(0, self::digestCount('weekly'));
  }

  public function testTheFormNamesAMissingTextOnce(): void {
    try {
      $this->submit(['name' => 'weekly', 'title' => 'Weekly'], []);
      self::fail('saved without a text');
    }
    catch (\CRM_Core_Exception $e) {
      self::assertStringContainsString('Write the text of the digest.', $e->getMessage());
      self::assertStringNotContainsString('has no text', $e->getMessage());
    }
  }

  public function testTheFormChangesASourceAndTheTextTogether(): void {
    $digest = DigestFixture::create('weekly', 'Weekly', '{$seats.booked} seats', [self::SEATS]);
    $this->submit(
      ['name' => 'weekly', 'title' => 'Weekly', 'msg_html' => '{$places.booked} places'],
      [['name' => 'places'] + self::SEATS],
      ['HeraldDigest1' => $digest['id']],
    );
    self::assertSame('{$places.booked} places', Definition::load('weekly')['msg_html']);
  }

  public function testTheFormKeepsTheNameOfTheDigestItEdits(): void {
    $digest = DigestFixture::create('weekly', 'Weekly');
    $this->submit(
      ['name' => 'weekly', 'title' => 'Renamed', 'msg_html' => '<p>Static digest</p>'],
      [],
      ['HeraldDigest1' => $digest['id']],
    );
    self::assertSame(
      'Renamed',
      HeraldDigest::get(checkPermissions: FALSE)->addWhere('id', '=', $digest['id'])->execute()->single()['title'],
    );
    $this->assertRefused(
      'Another digest is already named "weekly"',
      fn () => $this->submit([
        'name' => 'weekly',
        'title' => 'Copy',
        'msg_html' => '<p>Static digest</p>',
      ], []),
    );
  }

}

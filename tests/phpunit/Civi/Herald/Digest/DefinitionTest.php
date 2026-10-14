<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * A digest as Composer takes it, stored or submitted by the form.
 *
 * @group headless
 */
class DefinitionTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless() {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  private function display(): int {
    $search = \Civi\Api4\SavedSearch::create(checkPermissions: FALSE)
      ->setValues([
        'label' => 'Herald definition test',
        'api_entity' => 'Contact',
        'api_params' => ['version' => 4, 'select' => ['id']],
      ])
      ->execute()
      ->single()['id'];
    return (int) \Civi\Api4\SearchDisplay::create(checkPermissions: FALSE)
      ->setValues(['saved_search_id' => $search, 'label' => 'Members', 'type' => 'table', 'settings' => []])
      ->execute()
      ->single()['id'];
  }

  public function testADigestLoadsWithItsTemplateAndSourcesInSaveOrder(): void {
    $display = $this->display();
    DigestFixture::create(
      'weekly',
      'Weekly',
      '<b>{$seats.booked}</b>',
      [
        ['name' => 'seats', 'type' => 'section', 'section_key' => 'myext.seats'],
        ['name' => 'members', 'type' => 'search', 'search_display_id' => $display],
      ],
      'Week {$seats.booked}',
    );

    self::assertSame(
      [
        'name' => 'weekly',
        'title' => 'Weekly',
        'template' => [
          'msg_subject' => 'Week {$seats.booked}',
          'msg_html' => '<b>{$seats.booked}</b>',
          'msg_text' => '',
        ],
        'sources' => [
          ['name' => 'seats', 'type' => 'section', 'search_display_id' => NULL, 'section_key' => 'myext.seats'],
          ['name' => 'members', 'type' => 'search', 'search_display_id' => $display, 'section_key' => NULL],
        ],
      ],
      Definition::load('weekly'),
    );
  }

  public function testWhatWasDeletedSinceLoadsAsMissing(): void {
    $digest = DigestFixture::create('weekly', 'Weekly', sources: [
      ['name' => 'members', 'type' => 'search', 'search_display_id' => $this->display()],
    ]);
    \Civi\Api4\MessageTemplate::delete(checkPermissions: FALSE)->addWhere('id', '=', $digest['template_id'])->execute();
    \Civi\Api4\SearchDisplay::delete(checkPermissions: FALSE)->addWhere('label', '=', 'Members')->execute();

    $definition = Definition::load('weekly');

    self::assertNull($definition['template']);
    self::assertNull($definition['sources'][0]['search_display_id']);
  }

  public function testSubmittedValuesAreNormalised(): void {
    self::assertSame([
      'name' => 'weekly',
      'title' => 'Weekly',
      'template' => NULL,
      'sources' => [['name' => 'members', 'type' => 'search', 'search_display_id' => 12, 'section_key' => NULL]],
    ], Definition::of(
      ['name' => 'weekly', 'title' => 'Weekly', 'message_template_id' => ''],
      [['name' => 'members', 'type' => 'search', 'search_display_id' => '12', 'section_key' => ' ']],
    ));
  }

  public function testAnUnknownDigestIsAProblem(): void {
    $this->expectException(DigestProblem::class);
    $this->expectExceptionMessage('Digest "missing" does not exist.');
    Definition::load('missing');
  }

  public function testTheFormGetsTheSchemaDefaultsOfASource(): void {
    self::assertSame(['type' => 'search'], FormSettings::angular()['sourceDefaults']);
  }

}

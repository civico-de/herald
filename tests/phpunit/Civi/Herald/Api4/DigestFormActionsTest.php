<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4;

use Civi\Api4\Herald;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * The digest form asks for the placeholders of each source and for a preview
 * of the digest as it holds it.
 *
 * @group headless
 */
class DigestFormActionsTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  private const SEATS = ['name' => 'seats', 'type' => 'section', 'section_key' => 'test.seats'];

  /**
   * @var array<string, mixed>
   */
  private array $context = [];

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  /**
   * @param list<string> $keys
   * @param array<string, mixed> $context
   * @param array<string, mixed> $sections
   */
  public function hook_civicrm_heraldDigestSections(array $keys, array $context, array &$sections): void {
    $this->context = $context;
    $sections['test.seats'] = ['booked' => 138, 'paid' => 120];
  }

  /**
   * @param array<string, mixed> $source
   * @return array<string, mixed>
   */
  private static function placeholders(array $source): array {
    return Herald::getDigestPlaceholders(checkPermissions: FALSE)
      ->setSource($source)
      ->setDigest('daily')
      ->execute()
      ->single();
  }

  public function testASearchSourceGetsItsPlaceholdersAndSnippet(): void {
    $display = \Civi\Api4\SearchDisplay::get(checkPermissions: FALSE)
      ->addWhere('name', '=', 'Herald_Digest_Contributions_Yesterday_Table')
      ->execute()
      ->single()['id'];

    self::assertSame(
      [
        'placeholders' => [
          ['placeholder' => '{$donations.sum}', 'label' => 'Sum'],
          ['placeholder' => '{$donations.count}', 'label' => 'Count'],
          ['placeholder' => '{$donations.rows}', 'label' => 'Every row, with the names above'],
        ],
        'snippet' => 'Sum: {$donations.sum|crmMoney}, Count: {$donations.count}',
        'problems' => [],
      ],
      self::placeholders(['name' => 'donations', 'type' => 'search', 'search_display_id' => $display]),
    );
  }

  public function testASectionIsAskedWithTheDigestsName(): void {
    self::assertSame('booked: {$seats.booked}, paid: {$seats.paid}', self::placeholders(self::SEATS)['snippet']);
    self::assertSame(['digest' => 'daily'], $this->context);
  }

  public function testASourceThatCannotRunNamesItsProblems(): void {
    self::assertSame(
      ['placeholders' => [], 'snippet' => '', 'problems' => ['Search display 999999 does not exist.']],
      self::placeholders(['name' => 'donations', 'type' => 'search', 'search_display_id' => 999_999]),
    );
    self::assertSame(
      ['No extension answers the digest section "test.typo".'],
      self::placeholders(['section_key' => 'test.typo'] + self::SEATS)['problems'],
    );
    self::assertSame(
      ['A source name starts with a lowercase letter, followed by lowercase letters, digits and underscores.'],
      self::placeholders(['name' => 'Seats'] + self::SEATS)['problems'],
    );
  }

  public function testThePreviewComposesTheDigestAsEmailShowsIt(): void {
    self::assertSame(
      ['body' => "Booked: 138<br>\nPaid: 120", 'subject' => 'Seats 138', 'problems' => []],
      Herald::previewDigest(checkPermissions: FALSE)
        ->setDigest([
          'name' => 'daily',
          'title' => 'Daily',
          'msg_subject' => 'Seats {$seats.booked}',
          'msg_html' => "Booked: {\$seats.booked}\nPaid: {\$seats.paid}",
        ])
        ->setSources([self::SEATS])
        ->execute()
        ->single(),
    );
  }

  public function testAPreviewThatCannotBeComposedNamesItsProblems(): void {
    $preview = Herald::previewDigest(checkPermissions: FALSE)
      ->setDigest(['name' => 'daily', 'title' => 'Daily', 'msg_html' => '{$seats.bookd}'])
      ->setSources([self::SEATS])
      ->execute()
      ->single();

    self::assertSame(
      ['body' => NULL, 'subject' => NULL, 'problems' => ['{$seats.bookd} is not a placeholder of this digest.']],
      $preview,
    );
  }

  public function testEachSourceOfAPreviewIsAnObject(): void {
    $this->expectExceptionMessage('Herald.previewDigest: each source is an object of HeraldDigestSource values.');
    Herald::previewDigest(checkPermissions: FALSE)->setDigest(['name' => 'daily'])->setSources(['seats'])->execute();
  }

  /**
   * Both run searches past ACLs, as a digest does.
   */
  public function testOnlyAdministratorsMayAsk(): void {
    \CRM_Core_Config::singleton()->userPermissionClass->permissions = ['access CiviCRM'];
    foreach ([Herald::getDigestPlaceholders()->setSource(self::SEATS), Herald::previewDigest()] as $action) {
      try {
        $action->execute();
        self::fail($action->getActionName() . ' ran without administer CiviCRM.');
      }
      catch (\Civi\API\Exception\UnauthorizedException) {
        self::addToAssertionCount(1);
      }
    }
  }

}

<?php

declare(strict_types = 1);

namespace Civi\Herald\Monitor;

use Civi\Herald\Buffer\AlertBuffer;
use Civi\Herald\Buffer\BufferChecks;
use Civi\Herald\Buffer\DeadLetter;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * The monitor on the real System.check: a hook_civicrm_check message of this
 * test reaches it by key, and the run produces messages in the site language.
 *
 * @group headless
 */
class MonitorSystemCheckTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  private bool $probe = FALSE;

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  protected function setUp(): void {
    parent::setUp();
    \CRM_Core_DAO::executeQuery('DELETE FROM `' . AlertBuffer::TABLE . '`');
    \Civi::settings()->set(Monitor::STATE_SETTING, []);
    \Civi::settings()->set(ExpectedExtensions::SETTING, []);
  }

  protected function tearDown(): void {
    \Civi::settings()->revert('lcMessages');
    \CRM_Core_I18n::singleton()->setLocale('en_US');
    parent::tearDown();
  }

  /**
   * @param array<int, \CRM_Utils_Check_Message> $messages
   */
  public function hook_civicrm_check(array &$messages): void {
    if ($this->probe) {
      $messages[] = new \CRM_Utils_Check_Message('herald_test_probe:one', '<p>probe</p>', 'Probe', 'error');
    }
  }

  public function testAHookMessageIsObservedByItsKey(): void {
    $observations = (new CheckCollector())->observe(new Threshold(3));
    $this->assertArrayNotHasKey('herald_test_probe', $observations);

    $this->probe = TRUE;
    $observations = (new CheckCollector())->observe(new Threshold(3));
    $this->assertSame(
      [
        'status' => 'fail',
        'severity' => 4,
        'detail' => ['one'],
        'title' => 'Probe',
        'text' => 'probe',
        'visible' => TRUE,
      ],
      $observations['herald_test_probe'],
    );
  }

  public function testMessagesFollowTheSiteLanguageAndTheProcessKeepsItsOwn(): void {
    $buffer = new AlertBuffer();
    $buffer->add('herald-test-locale', 5, 'alert body', ['channel' => 'telegram']);
    $id = (int) \CRM_Core_DAO::singleValueQuery('SELECT MAX(id) FROM civicrm_herald_buffer');
    (new DeadLetter($buffer))->park([$id], 'max_attempts', 'channel down');

    \Civi::settings()->set('lcMessages', 'de_DE');
    \CRM_Core_I18n::singleton()->setLocale('en_US');
    $this->assertSame('Herald: geparkte Meldungen', self::deadLetterTitle());
    $this->assertSame('en_US', \CRM_Core_I18n::getLocale());

    // Without core's German catalogue, de_DE negotiates only while it is the site language.
    \CRM_Core_I18n::singleton()->setLocale('de_DE');
    \Civi::settings()->set('lcMessages', 'en_US');
    $this->assertSame('de_DE', \CRM_Core_I18n::getLocale());
    $this->assertSame('Herald: parked alerts', self::deadLetterTitle());
    $this->assertSame('de_DE', \CRM_Core_I18n::getLocale());
  }

  public function testExpectedExtensionMessagesFollowTheSiteLanguage(): void {
    \Civi::settings()->set(ExpectedExtensions::SETTING, ['org.example.absent']);
    \Civi::settings()->set('lcMessages', 'de_DE');
    \CRM_Core_I18n::singleton()->setLocale('en_US');

    $observation = (new CheckCollector(static fn (): array => []))->observe(
      new Threshold(3),
    )['herald_expected_org.example.absent'];

    $this->assertSame('Herald: erwartete Extension fehlt', $observation['title']);
    $this->assertSame('Die Extension org.example.absent ist nicht installiert und aktiviert.', $observation['text']);
    $this->assertSame('en_US', \CRM_Core_I18n::getLocale());
  }

  private static function deadLetterTitle(): string {
    $observations = (new CheckCollector())->observe(new Threshold(3));
    return (string) ($observations[BufferChecks::DEAD_LETTER]['title'] ?? '');
  }

}

<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * A notifyDigest job without a usable digest fails before it can notify its
 * recipients; the check is the only place that shows it.
 *
 * @group headless
 */
class DigestJobsCheckTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  protected function setUp(): void {
    parent::setUp();
    \Civi\Api4\Job::delete(checkPermissions: FALSE)
      ->addWhere('api_entity', '=', 'Herald')
      ->addWhere('api_action', '=', 'notifyDigest')
      ->execute();
  }

  protected function tearDown(): void {
    // Settings outlive the transaction rollback.
    \Civi::settings()->revert('herald_topics');
    parent::tearDown();
  }

  /**
   * @param array<string, mixed> $parameters
   */
  private function job(string $name, array $parameters, bool $active = TRUE): void {
    \Civi\Api4\Job::create(checkPermissions: FALSE)
      ->setValues([
        'name' => $name,
        'run_frequency' => 'Hourly',
        'api_entity' => 'Herald',
        'api_action' => 'notifyDigest',
        'parameters' => json_encode(['version' => 4] + $parameters, JSON_THROW_ON_ERROR),
        'is_active' => $active,
      ])
      ->execute();
  }

  /**
   * @return array<string, array<string, mixed>>
   */
  private function messages(): array {
    $messages = [];
    \Civi::service('herald.digest_jobs_check')->hook_civicrm_check($messages);
    $out = [];
    foreach ($messages as $message) {
      $out[$message->getName()] = $message->toArray();
    }
    return $out;
  }

  public function testAHealthyDigestJobProducesNoMessage(): void {
    \Civi::settings()->set('herald_topics', ['ops' => ['channel' => 'email']]);
    DigestFixture::create('check_daily', 'Daily');
    $this->job('Daily report', ['digest' => 'check_daily', 'sendHour' => 8, 'actionParams' => ['topic' => 'ops']]);
    $this->assertSame([], $this->messages());
  }

  public function testAJobWithoutDigestIsReported(): void {
    $this->job('Inline report', ['sendHour' => 8, 'actionParams' => ['channel' => 'telegram', 'blocks' => []]]);
    $this->assertSame(['Inline report (no digest)'], DigestJobsCheck::broken());
    $message = $this->messages()[DigestJobsCheck::NAME];
    $this->assertSame('error', $message['severity']);
    $this->assertStringContainsString('Inline report (no digest)', $message['message']);
  }

  public function testAJobNamingAMissingDigestIsReported(): void {
    $this->job('Gone report', ['digest' => 'deleted_digest']);
    $this->assertSame(['Gone report (digest "deleted_digest" does not exist)'], DigestJobsCheck::broken());
  }

  public function testADigestNamedInAnotherCaseIsFound(): void {
    DigestFixture::create('check_daily', 'Daily');
    $this->job('Daily report', ['digest' => 'CHECK_DAILY']);
    $this->assertSame([], $this->messages());
  }

  public function testATopicWithoutRouteIsReported(): void {
    \Civi::settings()->set('herald_topics', []);
    DigestFixture::create('check_daily', 'Daily');
    $this->job('Daily report', ['digest' => 'check_daily', 'actionParams' => ['topic' => 'nowhere']]);
    $this->assertStringContainsString('there is no topic "nowhere"', implode(' ', DigestJobsCheck::broken()));
  }

  public function testATopicWithoutChannelIsReported(): void {
    \Civi::settings()->set('herald_topics', ['ops' => ['label' => 'Ops']]);
    DigestFixture::create('check_daily', 'Daily');
    $this->job('Daily report', ['digest' => 'check_daily', 'actionParams' => ['topic' => 'ops']]);
    $this->assertStringContainsString('the topic "ops" names no channel', implode(' ', DigestJobsCheck::broken()));
  }

  public function testADisabledJobIsNotReported(): void {
    $this->job('Parked report', [], FALSE);
    $this->assertSame([], $this->messages());
  }

}

<?php

declare(strict_types = 1);

use Civi\Api4\CiviRulesAction;
use Civi\Api4\CiviRulesRuleAction;
use Civi\Api4\HeraldDigest;
use Civi\Api4\Job;
use Civi\Herald\Upgrader\InlineDigestFixture;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Rule actions and jobs that kept a digest inline name the record the upgrade
 * made of it; those it cannot make stay as they were.
 *
 * @group headless
 */
class CRM_Herald_Upgrader_InlineDigestOwnersTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  use InlineDigestFixture;

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  public function testRuleActionsNameTheirNewDigest(): void {
    $actionId = CiviRulesAction::get(checkPermissions: FALSE)
      ->addWhere('name', '=', 'herald_digest')
      ->execute()
      ->single()['id'];
    $ruleId = (int) \Civi\Api4\CiviRulesRule::create(checkPermissions: FALSE)
      ->setValues([
        'name' => 'herald_test_inline',
        'label' => 'Morning numbers',
        'trigger_id' => \Civi\Api4\CiviRulesTrigger::get(checkPermissions: FALSE)
          ->addWhere('name', '=', 'herald_schedule')
          ->execute()
          ->single()['id'],
        'is_active' => TRUE,
      ])
      ->execute()
      ->single()['id'];
    // A handler retired while CiviRules was disabled keeps its actions until CiviRules returns.
    $retiredId = (int) CiviRulesAction::create(checkPermissions: FALSE)
      ->setValues([
        'name' => 'herald_test_retired_digest',
        'label' => 'Retired digest',
        'class_name' => 'CRM_Herald_CivirulesAdapter_Action_ContributionDigest',
        'is_active' => TRUE,
      ])
      ->execute()
      ->single()['id'];
    $create = static fn (array $params, int $handler = 0): int => (int) CiviRulesRuleAction::create(
      checkPermissions: FALSE,
    )
      ->setValues([
        'rule_id' => $ruleId,
        'action_id' => $handler ?: $actionId,
        'action_params' => $params,
        'is_active' => TRUE,
      ])
      ->execute()
      ->single()['id'];
    $inline = $create(['channel' => 'telegram', 'template' => 'herald_contribution_digest_daily']);
    $stored = $create(['channel' => 'telegram', 'digest' => 'already_stored']);
    $retired = $create(['channel' => 'email'], $retiredId);

    self::assertSame(2, CRM_Herald_Upgrader_InlineDigestActions::migrate());

    $params = CiviRulesRuleAction::get(checkPermissions: FALSE)
      ->addSelect('id', 'action_params')
      ->addWhere('id', 'IN', [$inline, $stored])
      ->execute()
      ->indexBy('id')
      ->column('action_params');
    self::assertSame(['digest' => "rule_action_{$inline}", 'channel' => 'telegram'], $params[$inline]);
    self::assertSame(['channel' => 'telegram', 'digest' => 'already_stored'], $params[$stored]);
    self::assertSame('Morning numbers', $this->stored("rule_action_{$inline}")['title']);
    self::assertSame(
      ['digest' => "rule_action_{$retired}", 'channel' => 'email'],
      CiviRulesRuleAction::get(checkPermissions: FALSE)
        ->addWhere('id', '=', $retired)
        ->execute()
        ->single()['action_params'],
    );
  }

  public function testRuleActionsThatCannotMoveStayAsTheyWere(): void {
    // A rule without a label, which only older forms could save.
    \CRM_Core_DAO::executeQuery(
      "INSERT INTO civirule_rule (name, label, trigger_id, is_active, created_date)
       VALUES ('herald_test_stuck', '', (SELECT MIN(id) FROM civirule_trigger), 1, NOW())",
    );
    $ruleId = (int) \CRM_Core_DAO::singleValueQuery('SELECT LAST_INSERT_ID()');
    $actionId = CiviRulesAction::get(checkPermissions: FALSE)
      ->addWhere('name', '=', 'herald_digest')
      ->execute()
      ->single()['id'];
    $create = static function (string $serialized) use ($ruleId, $actionId): int {
      \CRM_Core_DAO::executeQuery(
        'INSERT INTO civirule_rule_action (rule_id, action_id, action_params, is_active) VALUES (%1, %2, %3, 1)',
        [1 => [$ruleId, 'Integer'], 2 => [$actionId, 'Integer'], 3 => [$serialized, 'String']],
      );
      return (int) \CRM_Core_DAO::singleValueQuery('SELECT LAST_INSERT_ID()');
    };
    $empty = $create('');
    $unreadable = $create('not serialized');
    $broken = serialize(['blocks' => '{"type": "search"']);
    $neverRan = $create($broken);
    $text = serialize(['blocks' => '[{"type": "section", "key": "myext.html"}]']);
    $html = $create($text);

    $migrated = 0;
    $warnings = $this->heraldWarnings(static function () use (&$migrated): void {
      $migrated = CRM_Herald_Upgrader_InlineDigestActions::migrate();
    });

    self::assertSame(1, $migrated);
    self::assertSame(
      [
        'herald: rule action params unreadable, digest left inline',
        'herald: digest left inline, it cannot run until fixed',
        'herald: digest left inline, it cannot run until fixed',
      ],
      $warnings,
    );
    $stored = static fn (int $id): string => (string) \CRM_Core_DAO::singleValueQuery(
      'SELECT action_params FROM civirule_rule_action WHERE id = %1',
      [1 => [$id, 'Integer']],
    );
    // CiviRules reads empty params as none, which sent the default digest.
    self::assertSame(serialize(['digest' => "rule_action_{$empty}"]), $stored($empty));
    self::assertSame("Rule action {$empty}", $this->stored("rule_action_{$empty}")['title']);
    self::assertSame('not serialized', $stored($unreadable));
    self::assertSame($broken, $stored($neverRan));
    self::assertSame($text, $stored($html));
    self::assertSame(
      0,
      HeraldDigest::get(checkPermissions: FALSE)->addWhere('name', '=', "rule_action_{$html}")->execute()->count(),
    );
  }

  public function testAJobWithUnreadableParametersIsLeftAlone(): void {
    $id = (int) Job::create(checkPermissions: FALSE)
      ->setValues([
        'name' => 'Broken digest',
        'run_frequency' => 'Daily',
        'api_entity' => 'Herald',
        'api_action' => 'notifyDigest',
        'parameters' => '{"version": 4,',
      ])
      ->execute()
      ->single()['id'];
    $neverRan = (int) Job::create(checkPermissions: FALSE)
      ->setValues([
        'name' => 'Broken blocks',
        'run_frequency' => 'Daily',
        'api_entity' => 'Herald',
        'api_action' => 'notifyDigest',
        'parameters' => json_encode(['version' => 4, 'actionParams' => ['blocks' => '{"type": "search"']]),
      ])
      ->execute()
      ->single()['id'];

    self::assertSame(0, CRM_Herald_Upgrader_InlineDigestJobs::migrate());

    $parameters = Job::get(checkPermissions: FALSE)
      ->addWhere('id', 'IN', [$id, $neverRan])
      ->execute()
      ->indexBy('id')
      ->column('parameters');
    self::assertSame('{"version": 4,', $parameters[$id]);
    self::assertSame(
      ['version' => 4, 'actionParams' => ['blocks' => '{"type": "search"']],
      json_decode((string) $parameters[$neverRan], TRUE),
    );
  }

  public function testJobsNameTheDigestBesideTheirDelivery(): void {
    $id = (int) Job::create(checkPermissions: FALSE)
      ->setValues([
        'name' => 'Weekly numbers',
        'run_frequency' => 'Daily',
        'api_entity' => 'Herald',
        'api_action' => 'notifyDigest',
        'parameters' => json_encode([
          'version' => 4,
          'sendHour' => 8,
          'actionParams' => [
            'channel' => 'email',
            'email_to' => 'ops@example.org',
            'template' => 'herald_contribution_digest_weekly',
          ],
        ]),
      ])
      ->execute()
      ->single()['id'];

    self::assertSame(1, CRM_Herald_Upgrader_InlineDigestJobs::migrate());
    self::assertSame(0, CRM_Herald_Upgrader_InlineDigestJobs::migrate());

    $parameters = json_decode(
      (string) Job::get(checkPermissions: FALSE)->addWhere('id', '=', $id)->execute()->single()['parameters'],
      TRUE,
    );
    self::assertSame(
      [
        'version' => 4,
        'sendHour' => 8,
        'actionParams' => ['channel' => 'email', 'email_to' => 'ops@example.org'],
        'digest' => "job_{$id}",
      ],
      $parameters,
    );
    self::assertSame('Weekly numbers', $this->stored("job_{$id}")['title']);
  }

  public function testAJobWithoutActionParamsKeepsSendingTheDefaultDigest(): void {
    $id = (int) Job::create(checkPermissions: FALSE)
      ->setValues([
        'name' => 'Plain digest',
        'run_frequency' => 'Daily',
        'api_entity' => 'Herald',
        'api_action' => 'notifyDigest',
        'parameters' => json_encode(['version' => 4, 'sendHour' => 7]),
      ])
      ->execute()
      ->single()['id'];

    self::assertSame(1, CRM_Herald_Upgrader_InlineDigestJobs::migrate());

    self::assertSame(
      ['version' => 4, 'sendHour' => 7, 'digest' => "job_{$id}", 'actionParams' => []],
      json_decode(
        (string) Job::get(checkPermissions: FALSE)->addWhere('id', '=', $id)->execute()->single()['parameters'],
        TRUE,
      ),
    );
    self::assertSame('Herald_Digest_Contributions_Yesterday_Table', $this->stored("job_{$id}")['sources']['block1']);
  }

  public function testAJobWithKeyValueParametersKeepsThemAndAJobThatNeverRanStays(): void {
    $job = static fn (string $parameters): int => (int) Job::create(checkPermissions: FALSE)
      ->setValues([
        'name' => 'Plain digest',
        'run_frequency' => 'Daily',
        'api_entity' => 'Herald',
        'api_action' => 'notifyDigest',
        'parameters' => $parameters,
      ])
      ->execute()
      ->single()['id'];
    $lines = $job("version=4\nsendHour=7");
    $v3 = $job('');

    self::assertSame(1, CRM_Herald_Upgrader_InlineDigestJobs::migrate());

    self::assertSame(
      ['version' => '4', 'sendHour' => '7', 'digest' => "job_{$lines}", 'actionParams' => []],
      CRM_Core_BAO_Job::parseParameters(
        (string) Job::get(checkPermissions: FALSE)->addWhere('id', '=', $lines)->execute()->single()['parameters'],
      ),
    );
    self::assertSame(
      '',
      (string) Job::get(checkPermissions: FALSE)->addWhere('id', '=', $v3)->execute()->single()['parameters'],
    );
  }

  public function testAMigratedOldJobRunsOutsideProduction(): void {
    \Civi::settings()->set('environment', 'Development');
    // Switching to Development disables outbound mail; the spool stands in for it.
    \Civi::settings()->set('mailing_backend', [
      'outBound_option' => \CRM_Mailing_Config::OUTBOUND_OPTION_REDIRECT_TO_DB,
    ]);
    \Civi::settings()->set('herald_email_from', '"Herald" <noreply@example.org>');
    CRM_Core_DAO::executeQuery('DELETE FROM civicrm_mailing_spool');
    $id = Job::create(checkPermissions: FALSE)
      ->setValues([
        'name' => 'herald test old digest',
        'run_frequency' => 'Hourly',
        'api_entity' => 'Job',
        'api_action' => 'herald_contribution_digest',
        'parameters' => implode("\n", [
          'template=herald_contribution_digest_daily',
          'channel=email',
          'email_to=ops@example.org',
          'show_pending=0',
          'goal=500',
          'runInNonProductionEnvironment=1',
        ]),
      ])
      ->execute()
      ->single()['id'];
    CRM_Herald_Upgrader_DigestJobs::migrate();
    CRM_Herald_Upgrader_InlineDigestJobs::migrate();

    (new CRM_Core_JobManager())->executeJobById($id);

    // The subject the old block gave the message, with no contribution yesterday.
    $headers = (string) CRM_Core_DAO::singleValueQuery('SELECT headers FROM civicrm_mailing_spool');
    self::assertSame(1, preg_match('/^Subject: (.*)$/m', $headers, $subject));
    self::assertSame('0 · $0.00', mb_decode_mimeheader($subject[1]));
  }

}

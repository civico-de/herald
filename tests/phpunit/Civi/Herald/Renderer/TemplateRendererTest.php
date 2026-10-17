<?php

declare(strict_types = 1);

namespace Civi\Herald\Renderer;

use Civi\Herald\Channel\EmailChannel;
use Civi\Herald\Channel\PlainText;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * @group headless
 */
class TemplateRendererTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  private TemplateRenderer $renderer;

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  public function setUp(): void {
    parent::setUp();
    $this->renderer = new TemplateRenderer();
  }

  public function testRendersAlertTemplate(): void {
    $body = $this->renderer->render('herald_contribution_alert_completed', [
      'contribution' => ['total_amount' => 42.5, 'source' => 'Stripe'],
      'campaign' => ['title' => '', 'goal' => 0, 'percent' => 0],
    ]);
    $this->assertStringContainsString('Donation received', $body);
    $this->assertStringContainsString('Stripe', $body);
  }

  public function testRendersStatusChangedTemplate(): void {
    $body = $this->renderer->render('herald_contribution_status_changed', [
      'contribution' => ['total_amount' => 100, 'source' => 'Bank'],
      'from_status' => 'Pending',
      'to_status' => 'Completed',
    ]);
    $this->assertStringContainsString('Pending', $body);
    $this->assertStringContainsString('Completed', $body);
    $this->assertStringContainsString('Pending → Completed', $body);
  }

  public function testRenderPartsReturnsRenderedSubject(): void {
    $parts = $this->renderer->renderParts('herald_contribution_alert_completed', [
      'contribution' => ['total_amount' => 42.5, 'source' => 'Stripe'],
      'campaign' => ['title' => '', 'goal' => 0, 'percent' => 0],
    ]);
    $this->assertStringContainsString('Stripe', $parts['subject']);
    $this->assertStringNotContainsString('{$', $parts['subject']);
    $this->assertStringContainsString('Donation received', $parts['body']);
  }

  /**
   * Check names and details are plain text; Telegram (parse_mode=HTML) rejects
   * the whole message on a stray "<" or "&".
   */
  public function testHtmlBodyEscapesPlainTextVarsAndKeepsTemplateMarkup(): void {
    $parts = $this->renderer->renderParts('herald_monitor_alert', [
      'check' => 'a&b<c>',
      'status' => 'fail',
      'detail' => 'backup failed: <lock/1311aa4feb> & 1 > 0',
      'renotify' => FALSE,
    ]);

    $this->assertStringContainsString('Check <b>a&amp;b&lt;c&gt;</b> failed', $parts['body']);
    $this->assertStringContainsString(': backup failed: &lt;lock/1311aa4feb&gt; &amp; 1 &gt; 0', $parts['body']);
    preg_match_all('#</?([a-zA-Z][^\s>]*)#', $parts['body'], $tags);
    $this->assertSame(['b', 'b'], $tags[1], 'only template markup remains as tags');
    // The subject is plain text (email header), not HTML.
    $this->assertStringContainsString('a&b<c>', (string) $parts['subject']);
  }

  public function testNestedVarsAreEscapedAndNonStringsKept(): void {
    $this->assertSame(
      ['contribution' => ['source' => 'Tom &amp; Jerry', 'total_amount' => 42.5], 'n' => 3, 'x' => NULL],
      TemplateRenderer::escapeHtml([
        'contribution' => ['source' => 'Tom & Jerry', 'total_amount' => 42.5],
        'n' => 3,
        'x' => NULL,
      ]),
    );
  }

  /**
   * Values written through APIv3 or a form arrive as `&lt;`/`&gt;`; they are
   * shown as typed. Only that encoding is undone: a literal "&amp;" typed by a
   * user is not HTMLInputCoder output and stays visible as typed.
   */
  public function testCoreEncodedValuesAreNotEscapedTwice(): void {
    $this->assertSame(
      ['title' => 'Workshop &lt;Einsteiger&gt; &amp; mehr', 'typed' => 'R&amp;amp;D'],
      TemplateRenderer::escapeHtml(['title' => 'Workshop &lt;Einsteiger&gt; & mehr', 'typed' => 'R&amp;D']),
    );
  }

  /**
   * Channels take HTML: a msg_text body is escaped whole, and its vars are
   * shown as typed.
   */
  public function testTextOnlyTemplateIsEscapedWhole(): void {
    \Civi\Api4\MessageTemplate::create(checkPermissions: FALSE)
      ->setValues([
        'workflow_name' => 'herald_test_text_only',
        'msg_title' => 'text only',
        'msg_text' => 'Detail: {$detail} <i>',
        'is_default' => TRUE,
      ])
      ->execute();

    $body = $this->renderer->render('herald_test_text_only', ['detail' => 'a < b & c &lt;d&gt;']);
    $this->assertSame('Detail: a &lt; b &amp; c &lt;d&gt; &lt;i&gt;', $body);
    $this->assertSame('Detail: a < b & c <d> <i>', PlainText::fromHtml($body));
  }

  /**
   * Titles saved through a form arrive as `&lt;`/`&gt;`; the subject is an
   * email header and shows them as typed, the body escapes them once.
   */
  public function testSubjectShowsCoreEncodedVarsAsTyped(): void {
    $parts = $this->renderer->renderParts('herald_event_created_alert', [
      'event' => ['id' => 3, 'title' => 'Workshop &lt;Einsteiger&gt; & mehr'],
    ]);
    $this->assertStringEndsWith('Workshop <Einsteiger> & mehr', (string) $parts['subject']);
    $this->assertStringContainsString('Workshop &lt;Einsteiger&gt; &amp; mehr', $parts['body']);
  }

  /**
   * Line breaks of a shipped template survive the email HTML part.
   */
  public function testShippedTemplateKeepsLineBreaksInEmailHtml(): void {
    $body = $this->renderer->render('herald_event_registration_alert', [
      'event' => ['id' => 3, 'title' => 'Summer camp'],
      'contact' => ['masked_name' => 'Jo***'],
      'participant' => ['role' => 'Attendee'],
    ]);
    $this->assertStringNotContainsString('<br', $body);
    $html = EmailChannel::buildMailParams($body, ['email_to' => 'a@example.org'], 'f@example.org')['html'];
    $this->assertMatchesRegularExpression(
      '#registration</b><br>\nSummer camp<br>\nParticipant: Jo\*\*\*<br>\nRole: Attendee$#',
      $html,
    );
  }

  /**
   * @return array<string, array{string}>
   */
  public static function contributionTemplates(): array {
    $names = [
      'herald_contribution_alert_completed',
      'herald_contribution_alert_pending',
      'herald_contribution_status_changed',
      'herald_contribution_alert_ticket',
      'herald_contribution_alert_ticket_pending',
      'herald_contribution_alert_ticket_paid',
    ];
    return array_combine($names, array_map(static fn (string $n): array => [$n], $names));
  }

  /**
   * A trigger payload carries only the fields that are set; contributions
   * without a source (e.g. membership fees) must render cleanly.
   *
   * @dataProvider contributionTemplates
   */
  public function testContributionWithoutSourceRendersWithoutWarningOrDanglingSeparator(string $workflow): void {
    $warnings = [];
    set_error_handler(
      static function (int $errno, string $errstr) use (&$warnings): bool {
        $warnings[] = $errstr;
        return TRUE;
      },
      E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE,
    );
    try {
      $parts = $this->renderer->renderParts($workflow, [
        'contribution' => ['id' => 7, 'total_amount' => 42.5, 'contribution_status_id' => 1],
        'campaign' => ['title' => '', 'goal' => 0, 'total' => 0, 'count' => 0, 'percent' => 0],
        'from_status' => 'Pending',
        'to_status' => 'Completed',
      ]);
    }
    finally {
      restore_error_handler();
    }

    $this->assertSame([], $warnings);
    $this->assertStringNotContainsString('Source', $parts['body']);
    $this->assertDoesNotMatchRegularExpression('/—\s*$/u', (string) $parts['subject']);
  }

  /**
   * @dataProvider contributionTemplates
   */
  public function testContributionSourceIsShownWhenSet(string $workflow): void {
    $parts = $this->renderer->renderParts($workflow, [
      'contribution' => ['id' => 7, 'total_amount' => 42.5, 'source' => 'Online shop'],
      'campaign' => ['title' => '', 'goal' => 0, 'total' => 0, 'count' => 0, 'percent' => 0],
      'from_status' => 'Pending',
      'to_status' => 'Completed',
    ]);
    $this->assertStringContainsString('Online shop', $parts['subject'] . $parts['body']);
  }

  /**
   * An event derived from a participant carries no start_date, and a
   * participant may carry no role.
   */
  public function testEventTemplatesRenderWithoutOptionalFields(): void {
    $body = $this->renderer->render('herald_event_created_alert', ['event' => ['id' => 3, 'title' => 'Summer camp']]);
    $this->assertStringContainsString('Summer camp', $body);
    $this->assertStringNotContainsString(' — ', $body);

    $body = $this->renderer->render('herald_event_registration_alert', [
      'event' => ['id' => 3, 'title' => 'Summer camp'],
      'contact' => ['masked_name' => 'Jo***'],
      'participant' => [],
    ]);
    $this->assertStringNotContainsString('Role', $body);
  }

  /**
   * A contact or membership lookup that fails leaves its variable out.
   */
  public function testMembershipTemplateRendersWithoutOptionalVars(): void {
    [$parts, $warnings] = $this->renderQuietly('herald_membership_signup_alert', []);
    $this->assertSame([], $warnings);
    $this->assertSame('🎉 <b>New membership</b>', $parts['body']);
    $this->assertSame('🎉 New membership', $parts['subject']);

    [$parts, $warnings] = $this->renderQuietly('herald_membership_signup_alert', [
      'membership' => ['membership_type' => 'General', 'status' => 'New'],
    ]);
    $this->assertSame([], $warnings);
    $this->assertSame("🎉 <b>New membership</b>\nGeneral\nStatus: New", $parts['body']);

    [$parts] = $this->renderQuietly('herald_membership_signup_alert', [
      'contact' => ['masked_name' => 'Jo***'],
      'membership' => ['membership_type' => 'General', 'status' => 'New'],
    ]);
    $this->assertSame("🎉 <b>New membership</b>\nJo*** — General\nStatus: New", $parts['body']);
    $this->assertSame('🎉 New membership: Jo***', $parts['subject']);
  }

  /**
   * @param array<string, mixed> $vars
   * @return array{array{subject: ?string, body: string}, list<string>}
   */
  private function renderQuietly(string $workflow, array $vars): array {
    $warnings = [];
    set_error_handler(
      static function (int $errno, string $errstr) use (&$warnings): bool {
        $warnings[] = $errstr;
        return TRUE;
      },
      E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE,
    );
    try {
      return [$this->renderer->renderParts($workflow, $vars), $warnings];
    }
    finally {
      restore_error_handler();
    }
  }

  /**
   * CiviCRM stores an HTML field left empty as NULL, so the text body is used.
   */
  public function testAnEmptyHtmlBodyFallsBackToTheTextBody(): void {
    $this->createTemplate('herald_test_empty_html', ['msg_html' => '', 'msg_text' => 'Hello {$name}']);
    $this->assertSame('Hello A &amp; B', $this->renderer->render('herald_test_empty_html', ['name' => 'A & B']));
  }

  public function testATemplateWithoutAnyBodyFails(): void {
    $this->createTemplate('herald_test_no_body', ['msg_html' => '', 'msg_text' => '']);
    $this->expectExceptionMessage('herald: MessageTemplate "herald_test_no_body" has empty msg_html and msg_text.');
    $this->renderer->render('herald_test_no_body', []);
  }

  public function testThrowsOnMissingTemplate(): void {
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/not found/');
    $this->renderer->render('herald_does_not_exist', []);
  }

  public function testRenderInlineUsesCallerSuppliedBody(): void {
    $body = $this->renderer->renderInline('Hello {$name}!', ['name' => 'World']);
    $this->assertSame('Hello World!', $body);
  }

  public function testRenderInlineRefusesCoreApiCalls(): void {
    $this->expectExceptionMessage("Tag 'crmAPI' is not allowed");
    $this->renderer->renderInline('{crmAPI entity="Domain" action="getcount" var="n"}{$n}', []);
  }

  public function testThePolicyIsOffAgainAfterARender(): void {
    $this->renderer->renderInline('x', []);
    self::assertFalse(self::policyOn());
  }

  public function testAPolicyTheCallerEnabledStaysOn(): void {
    $policy = \Civi::service('civi.smarty.userContent');
    $policy->enable();
    try {
      $this->renderer->renderInline('x', []);
      self::assertTrue(self::policyOn());
    }
    finally {
      $policy->disable();
    }
  }

  private static function policyOn(): bool {
    return \CRM_Core_Smarty::singleton()->security_policy !== NULL;
  }

  /**
   * @param array<string, string> $bodies
   */
  private function createTemplate(string $workflowName, array $bodies): void {
    \Civi\Api4\MessageTemplate::create(checkPermissions: FALSE)
      ->setValues(['workflow_name' => $workflowName, 'msg_title' => $workflowName, 'is_default' => TRUE] + $bodies)
      ->execute();
  }

}

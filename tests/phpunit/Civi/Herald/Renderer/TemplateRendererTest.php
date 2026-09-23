<?php

declare(strict_types = 1);

namespace Civi\Herald\Renderer;

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
    return \Civi\Test::headless()->install('org.civicoop.civirules')->installMe(__DIR__)->apply();
  }

  public function setUp(): void {
    parent::setUp();
    $this->renderer = new TemplateRenderer();
  }

  public function testRendersShippedDigestTemplate(): void {
    $body = $this->renderer->render('herald_contribution_digest_daily', [
      'period' => ['label' => '2026-05-06'],
      'totals' => ['amount' => 1234.5, 'count' => 7],
      'by_source' => [['source' => 'Stripe', 'total' => 1234.5, 'count' => 7]],
      'pending' => ['amount' => 0, 'count' => 0],
      'campaign' => ['title' => '', 'goal' => 0, 'total' => 0, 'count' => 0, 'percent' => 0],
      'histogram' => '5–10 ████ 3 (15)',
    ]);

    // Source string, not the de_DE rendering — the test env has no German
    // locale; translation correctness lives in l10n/de_DE, not here.
    $this->assertStringContainsString('Daily report 2026-05-06', $body);
    $this->assertStringContainsString('Stripe', $body);
    $this->assertStringContainsString('<pre>5–10 ████ 3 (15)</pre>', $body);
    // No campaign block when goal=0
    $this->assertStringNotContainsString('Campaign', $body);
  }

  public function testCampaignBlockAppearsWhenGoalSet(): void {
    $body = $this->renderer->render('herald_contribution_digest_daily', [
      'period' => ['label' => '2026-05-06'],
      'totals' => ['amount' => 100, 'count' => 1],
      'by_source' => [],
      'pending' => ['amount' => 0, 'count' => 0],
      'campaign' => ['title' => 'Campaign 2026', 'goal' => 30_000, 'total' => 25_000, 'count' => 50, 'percent' => 83.3],
      'histogram' => '',
    ]);

    $this->assertStringContainsString('Campaign', $body);
    $this->assertStringContainsString('Campaign 2026', $body);
    $this->assertStringContainsString('83.3%', $body);
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
      TemplateRenderer::escapeHtml(['contribution' => ['source' => 'Tom & Jerry', 'total_amount' => 42.5], 'n' => 3, 'x' => NULL]),
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

  public function testTextOnlyTemplateIsNotEscaped(): void {
    \Civi\Api4\MessageTemplate::create(checkPermissions: FALSE)
      ->setValues([
        'workflow_name' => 'herald_test_text_only',
        'msg_title' => 'text only',
        'msg_text' => 'Detail: {$detail}',
        'is_default' => TRUE,
      ])
      ->execute();

    $body = $this->renderer->render('herald_test_text_only', ['detail' => 'a < b & c']);
    $this->assertSame('Detail: a < b & c', $body);
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
    set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
      $warnings[] = $errstr;
      return TRUE;
    }, E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE);
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

  public function testThrowsOnMissingTemplate(): void {
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/not found/');
    $this->renderer->render('herald_does_not_exist', []);
  }

  public function testRenderInlineUsesCallerSuppliedBody(): void {
    $body = $this->renderer->renderInline('Hello {$name}!', ['name' => 'World']);
    $this->assertSame('Hello World!', $body);
  }

}

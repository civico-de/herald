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

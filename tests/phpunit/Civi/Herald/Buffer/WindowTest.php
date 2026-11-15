<?php

declare(strict_types = 1);

namespace Civi\Herald\Buffer;

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * The aggregation window of a delivery: its own value, else the site default.
 *
 * @group headless
 */
class WindowTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  public function testTheSiteDefaultIsOneMinute(): void {
    \Civi::settings()->revert(Window::SETTING);
    $this->assertSame(1, Window::of([]));
  }

  /**
   * @return array<string, array{0: array<string, mixed>, 1: int}>
   */
  public static function windows(): array {
    return [
      'nothing set' => [[], 7],
      'blank' => [[Window::KEY => ''], 7],
      'null' => [[Window::KEY => NULL], 7],
      'own window' => [[Window::KEY => 15], 15],
      'own window as text' => [[Window::KEY => '15'], 15],
      'a chosen 0 sends at once' => [[Window::KEY => 0], 0],
      'a chosen 0 as text' => [[Window::KEY => '0'], 0],
      'a negative window sends at once' => [[Window::KEY => -5], 0],
    ];
  }

  /**
   * @dataProvider windows
   * @param array<string, mixed> $params
   */
  public function testADeliverysOwnWindowWinsOverTheSiteDefault(array $params, int $expected): void {
    \Civi::settings()->set(Window::SETTING, 7);
    $this->assertSame($expected, Window::of($params));
  }

  public function testASiteDefaultOfZeroSendsAtOnce(): void {
    \Civi::settings()->set(Window::SETTING, 0);
    $this->assertSame(0, Window::of([]));
  }

}

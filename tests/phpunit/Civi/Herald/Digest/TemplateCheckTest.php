<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Test\HeadlessInterface;
use PHPUnit\Framework\TestCase;

/**
 * What the check turns into problems and what it leaves to PHP.
 *
 * @group headless
 */
class TemplateCheckTest extends TestCase implements HeadlessInterface {

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  /**
   * @param \Closure(): mixed $render
   * @param list<array{int, string}> $seen what reaches the handler outside the check
   */
  private static function outside(\Closure $render, array &$seen): void {
    set_error_handler(static function (int $level, string $message) use (&$seen): bool {
      $seen[] = [$level, $message];
      return TRUE;
    });
    try {
      TemplateCheck::render('', $render);
    }
    finally {
      restore_error_handler();
    }
  }

  public function testDeprecationsAndSuppressedWarningsStayWithPhp(): void {
    $seen = [];
    self::outside(
      static function (): void {
        trigger_error('old API', E_USER_DEPRECATED);
        // Smarty lowers error_reporting() the same way while it writes a compiled template.
        $reporting = error_reporting(0);
        trigger_error('expected by the caller', E_USER_WARNING);
        error_reporting($reporting);
      },
      $seen,
    );

    self::assertSame([[E_USER_DEPRECATED, 'old API'], [E_USER_WARNING, 'expected by the caller']], $seen);
  }

  public function testAWarningIsAProblemAndNotPassedOn(): void {
    $seen = [];
    try {
      self::outside(static fn () => trigger_error('Something odd', E_USER_WARNING), $seen);
      self::fail('no problem');
    }
    catch (DigestProblem $e) {
      self::assertSame(['The template causes a warning: Something odd'], $e->problems());
    }
    self::assertSame([], $seen);
  }

  public function testTheSettingsAreRestoredAfterAFailure(): void {
    $smarty = \CRM_Core_Smarty::singleton();
    $reporting = error_reporting(E_ALL & ~E_NOTICE);
    $unassigned = $smarty->error_unassigned;
    try {
      TemplateCheck::render('', static fn () => throw new \RuntimeException('boom'));
      self::fail('no problem');
    }
    catch (DigestProblem $e) {
      self::assertSame(['The template cannot be rendered: boom'], $e->problems());
      self::assertInstanceOf(\RuntimeException::class, $e->getCause());
    }
    finally {
      $restored = [error_reporting(), $smarty->error_unassigned];
      error_reporting($reporting);
    }
    self::assertSame([E_ALL & ~E_NOTICE, $unassigned], $restored);
  }

  public function testTheResultOfTheRenderIsReturned(): void {
    self::assertSame('done', TemplateCheck::render('', static fn (): string => 'done'));
  }

  public function testAKeyUsedInSeveralPlacesIsNamedOnce(): void {
    $this->expectExceptionMessage('{$a.sume}, {$b.sume} is not a placeholder of this digest.');
    TemplateCheck::render('{$a.sume} {$b.sume} {$a.sume}', static fn () => trigger_error(
      'Undefined array key "sume"',
      E_USER_WARNING,
    ));
  }

}

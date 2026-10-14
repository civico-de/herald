<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use CRM_Herald_ExtensionUtil as E;

/**
 * Renders a digest template so that every Smarty problem is a DigestProblem:
 * syntax errors, errors raised while rendering, and warnings such as a
 * placeholder no source provides. Deprecations stay with PHP.
 */
final class TemplateCheck {

  /**
   * @template T
   * @param string $source The template text, to name the placeholders a warning means
   * @param \Closure(): T $render
   * @return T
   * @throws DigestProblem
   */
  public static function render(string $source, \Closure $render): mixed {
    $smarty = \CRM_Core_Smarty::singleton();
    $warnings = [];
    $previous = NULL;
    $previous = set_error_handler(static function (int $level, string $message, string $file = '', int $line = 0) use (
      &$warnings,
      &$previous,
    ): bool {
      // Smarty and @ lower error_reporting() around what they expect to fail.
      if (in_array($level, [E_DEPRECATED, E_USER_DEPRECATED], TRUE) || (error_reporting() & $level) === 0) {
        return is_callable($previous) && (bool) $previous($level, $message, $file, $line);
      }
      $warnings[] = $message;
      return TRUE;
    });
    // Core lets Smarty warn only on debug sites; without error_unassigned an unknown {$name} renders empty.
    $reporting = error_reporting(E_ALL);
    $unassigned = $smarty->error_unassigned;
    $smarty->error_unassigned = TRUE;
    try {
      $result = $render();
    }
    catch (DigestProblem $e) {
      throw $e;
    }
    catch (\Throwable $e) {
      throw new DigestProblem([self::error($e, $source)], $e);
    }
    finally {
      $smarty->error_unassigned = $unassigned;
      error_reporting($reporting);
      restore_error_handler();
    }
    if ($warnings !== []) {
      throw new DigestProblem(self::warnings(array_values(array_unique($warnings)), $source));
    }
    return $result;
  }

  private static function error(\Throwable $e, string $source): string {
    // Compiler messages repeat the start of the template as its name.
    $message = trim((string) preg_replace('/ in template "[^"]*"/', '', $e->getMessage()));
    $encoded =
      $e instanceof \Smarty\CompilerException && preg_match('/\{[^{}]*&(?:gt|lt|amp);[^{}]*\}/', $source) === 1;
    return (
      $encoded
        ? E::ts(
          'The template has a syntax error: %1 The editor stores > as &gt; and && as &amp;&amp;, also inside Smarty tags; write gt, lt and and instead.',
          [1 => $message],
        )
        : E::ts('The template cannot be rendered: %1', [1 => $message])
    );
  }

  /**
   * @param list<string> $warnings
   * @return non-empty-list<string>
   */
  private static function warnings(array $warnings, string $source): array {
    $unknownSource = preg_grep('/^Undefined variable \$/', $warnings) !== [];
    $problems = array_values(array_unique(array_filter(array_map(
      static fn (string $warning): ?string => self::warning($warning, $unknownSource, $source),
      $warnings,
    ))));
    assert($problems !== [], 'a warning is dropped only beside an unknown source, which is a problem');
    return $problems;
  }

  private static function warning(string $warning, bool $unknownSource, string $source): ?string {
    if (preg_match('/^Undefined variable \$(\w+)$/', $warning, $m) === 1) {
      return E::ts('{$%1} is not a source of this digest.', [1 => $m[1]]);
    }
    if (preg_match('/^Undefined array key "(\w+)"$/', $warning, $m) === 1) {
      return self::unknownKey($m[1], $source);
    }
    // Reading into an unknown source warns twice; its name is in the first warning.
    $repeated = $unknownSource && str_starts_with($warning, 'Trying to access array offset on');
    return $repeated ? NULL : E::ts('The template causes a warning: %1', [1 => $warning]);
  }

  private static function unknownKey(string $key, string $source): string {
    preg_match_all('/\$(\w+(?:\.\w+)*)\.' . preg_quote($key, '/') . '\b/', $source, $m);
    $places = array_values(array_unique(array_map(
      static fn (string $path): string => '{$' . $path . '.' . $key . '}',
      $m[1],
    )));
    return (
      $places === []
        ? E::ts('A placeholder reads "%1", which its source does not provide.', [1 => $key])
        : E::ts('%1 is not a placeholder of this digest.', [1 => implode(', ', $places)])
    );
  }

}

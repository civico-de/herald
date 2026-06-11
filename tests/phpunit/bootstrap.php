<?php

/**
 * PHPUnit bootstrap for herald.
 *
 * Standard pattern produced by `civix generate:test --template headless`.
 * Designed to run inside the civikitchen :standalone container.
 */

ini_set('memory_limit', '2G');
error_reporting(E_ALL);

if (PHP_SAPI !== 'cli') {
  echo "phpunit must be run from the CLI.\n";
  exit(1);
}

if (!function_exists('cv')) {
  /**
   * Call the "cv" command. Mirrors the helper bundled by civicrm-buildkit /
   * civikitchen — kept inline so the extension is portable.
   */
  function cv(string $cmd, string $decode = 'json') {
    $cmd = "cv $cmd";
    $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR];
    $oldOutput = getenv('CV_OUTPUT');
    putenv('CV_OUTPUT=json-strict');
    $process = proc_open($cmd, $descriptorSpec, $pipes, __DIR__);
    putenv('CV_OUTPUT' . ($oldOutput === FALSE ? '' : '=' . $oldOutput));
    fclose($pipes[0]);
    $result = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    if (proc_close($process) !== 0) {
      throw new RuntimeException('cv command failed: ' . $cmd);
    }
    if ($decode === 'raw' || $decode === 'phpcode') {
      return $result;
    }
    return json_decode($result, TRUE);
  }
}

eval(cv('php:boot --level=full', 'phpcode'));

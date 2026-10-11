<?php

/**
 * Runs the pinned Strauss release for composer's prefix-namespaces script. The
 * phar stays in the system temp directory: whatever lands in the extension ships.
 */

declare(strict_types = 1);

const STRAUSS_VERSION = '0.30.0';

const STRAUSS_SHA256 = '08c1a8e553594745c22294e158129005fd11ed09ed452d7d4f48566f38c66c96';

// A deploy from git ships this file; a web request must not start the download.
if (PHP_SAPI !== 'cli') {
  exit(1);
}

// The shared cache only saves the download. What runs is a private copy of the
// bytes checked here, so nobody else with access to the temp dir can swap it.
$cache = sys_get_temp_dir() . '/strauss-' . STRAUSS_VERSION . '.phar';
// The genuine phar is about 11 MB; a bigger file only has to fail the hash.
$bytes = is_file($cache) ? file_get_contents($cache, length: 32 << 20) : FALSE;
$cached = is_string($bytes) && hash('sha256', $bytes) === STRAUSS_SHA256;
if (!$cached) {
  $url = 'https://github.com/BrianHenryIE/strauss/releases/download/' . STRAUSS_VERSION . '/strauss.phar';
  $bytes = file_get_contents($url);
  if (!is_string($bytes) || hash('sha256', $bytes) !== STRAUSS_SHA256) {
    fwrite(STDERR, "strauss: {$url} did not download or does not match the pinned SHA-256\n");
    exit(1);
  }
}
$phar = tempnam(sys_get_temp_dir(), 'strauss-');
if ($phar === FALSE || file_put_contents($phar, $bytes) !== strlen($bytes)) {
  if (is_string($phar)) {
    unlink($phar);
  }
  fwrite(STDERR, 'strauss: cannot write a private copy to ' . sys_get_temp_dir() . "\n");
  exit(1);
}

passthru(implode(' ', array_map('escapeshellarg', [PHP_BINARY, $phar, ...array_slice($argv, 1)])), $status);
// A cache path held by someone else only costs the next run a download.
if ($cached || !rename($phar, $cache)) {
  unlink($phar);
}
exit($status);

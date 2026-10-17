<?php

declare(strict_types = 1);

/**
 * Helpers for the digest form (afformHeraldDigest); Afform loads the module
 * wherever a form uses one of its exports.
 */
return [
  'js' => ['ang/heraldDigest.js'],
  'css' => ['ang/heraldDigest.css'],
  'requires' => ['af', 'api4'],
  'settingsFactory' => [\Civi\Herald\Digest\FormSettings::class, 'angular'],
  'exports' => [
    'herald-digest-intro' => 'A',
    'herald-digest-source' => 'A',
    'herald-digest-source-kind' => 'A',
    'herald-digest-source-header' => 'A',
    'herald-digest-source-type' => 'A',
    'herald-digest-placeholders' => 'A',
    'herald-digest-preview' => 'A',
  ],
];

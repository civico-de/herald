<?php

declare(strict_types = 1);

/**
 * Helpers for the digest form (afformHeraldDigest); Afform loads the module
 * wherever a form uses one of its exports.
 */
return [
  'js' => ['ang/heraldDigest.js'],
  'requires' => ['af'],
  'settingsFactory' => [\Civi\Herald\Digest\Options::class, 'angularSettings'],
  'exports' => [
    'herald-digest-block' => 'A',
    'herald-digest-block-type' => 'A',
  ],
];

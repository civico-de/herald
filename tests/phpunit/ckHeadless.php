<?php

declare(strict_types = 1);

/**
 * ck_headless(): \Civi\Test::headless() with this extension's info.xml
 * <requires> queued for install before the extension itself.
 *
 * Civi\Test's installMe() takes the key verbatim and leaves <requires>
 * uninstalled, so the next CIVICRM_UF=UnitTests boot dies in the class scan.
 * The list comes from info.xml, not from CRM_Extension_Manager: touching the
 * extension system here runs BEFORE Civi\Test rebuilds the headless schema
 * and leaves caches behind that the rebuilt site no longer matches. One level
 * deep, like the image entrypoint — a dependency's own <requires> are core or
 * registry extensions. A dependency the site does not have is an install
 * error, not a silent skip.
 *
 * Template-managed (rewritten by ckinit --update); chain further steps:
 *   ck_headless()->sqlFile(__DIR__ . '/fixtures.sql')->apply();
 */
function ck_headless(): \Civi\Test\CiviEnvBuilder {
  $info = ck_extension_info(dirname(__DIR__, 2));
  $builder = \Civi\Test::headless();
  // One step per extension, never one install() with the whole list: the
  // manager installs a list in one pass, and an extension whose schema
  // references a dependency's table then fails its own table lookup.
  foreach ($info->requires->ext ?? [] as $required) {
    $builder = $builder->install([trim((string) $required)]);
  }
  return $builder->install([trim((string) $info['key'])]);
}

/**
 * The info.xml in $dir — parsed as XML, never grepped; a missing key throws.
 */
function ck_extension_info(string $dir): SimpleXMLElement {
  $file = $dir . '/info.xml';
  libxml_use_internal_errors(use_errors: TRUE);
  $xml = is_file($file) ? simplexml_load_file($file) : FALSE;
  if ($xml === FALSE || trim((string) $xml['key']) === '') {
    throw new RuntimeException("ck_headless: no extension key in {$file}");
  }
  return $xml;
}

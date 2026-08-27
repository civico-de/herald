<?php

/**
 * ck_headless(): \Civi\Test::headless() with this extension AND its info.xml
 * <requires> queued for install, dependencies first.
 *
 * CRM_Extension_Manager::install() takes keys verbatim — only the APIv3
 * Extension.install action wraps them in findInstallRequirements() — so
 * Civi\Test's installMe() leaves <requires> uninstalled and the next
 * CIVICRM_UF=UnitTests boot dies in the class scan. This asks the manager for
 * the same closure APIv3 uses: transitive, topologically sorted, this
 * extension last. A dependency the site does not have is an install error,
 * not a silent skip; a key already installed is a no-op in the builder.
 *
 * Template-managed (rewritten by ckinit --update); chain further steps:
 *   ck_headless()->sqlFile(__DIR__ . '/fixtures.sql')->apply();
 */
function ck_headless(): \Civi\Test\CiviEnvBuilder {
  $key = ck_extension_key(dirname(__DIR__, 2));
  $keys = \CRM_Extension_System::singleton()->getManager()->findInstallRequirements([$key]);
  return \Civi\Test::headless()->install($keys);
}

/**
 * The extension key of the info.xml in $dir — parsed as XML, never grepped.
 */
function ck_extension_key(string $dir): string {
  $file = $dir . '/info.xml';
  libxml_use_internal_errors(TRUE);
  $xml = is_file($file) ? simplexml_load_file($file) : FALSE;
  $key = $xml === FALSE ? '' : trim((string) $xml['key']);
  if ($key === '') {
    throw new RuntimeException("ck_headless: no extension key in {$file}");
  }
  return $key;
}

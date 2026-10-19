<?php

declare(strict_types = 1);

/**
 * ck_headless(): \Civi\Test::headless() with this extension's info.xml
 * <requires>, and theirs in turn, installed before the extension itself.
 *
 * Civi\Test's installMe() takes the key verbatim and leaves <requires>
 * uninstalled, so the next CIVICRM_UF=UnitTests boot dies in the class scan.
 * The closure is resolved inside the step, through CRM_Extension_Manager:
 * touching the extension system here runs BEFORE Civi\Test rebuilds the
 * headless schema and leaves caches behind that the rebuilt site no longer
 * matches. A dependency the site does not have is an install error, not a
 * silent skip.
 *
 * Civi\Test reuses the scratch database while the step signatures match, and
 * an install() step signs only the key; a no-op step signed with the
 * extension's install files rebuilds it when one of them changes.
 *
 * $optional names extensions the code integrates with but does not require;
 * they install after <requires>, before the extension itself.
 *
 * Template-managed (rewritten by ckinit --update); chain further steps:
 *   ck_headless()->sqlFile(__DIR__ . '/fixtures.sql')->apply();
 *   ck_headless('org.example.optional')->apply();
 */
function ck_headless(string ...$optional): \Civi\Test\CiviEnvBuilder {
  ck_restore_core_foreign_keys();
  $info = ck_extension_info(dirname(__DIR__, 2));
  $keys = [];
  foreach ($info->requires->ext ?? [] as $required) {
    $keys[] = trim((string) $required);
  }
  $keys = [...$keys, ...array_values($optional), trim((string) $info['key'])];
  // Core's callback() declares no return type, so each step is checked.
  $steps = [
    ['ck_forget_signature', 'ck-forget-signature'],
    [static fn () => ck_install_in_order($keys), 'ck-install:' . implode(',', $keys)],
    // Last, so it drains what every earlier step queued. Its signature carries
    // the install files, so editing them rebuilds the test database.
    ['ck_discard_bootstrap_status', 'ck-discard-bootstrap-status;' . ck_install_signature(dirname(__DIR__, 2), $info)],
  ];
  $builder = \Civi\Test::headless();
  foreach ($steps as [$callback, $signature]) {
    $builder = $builder->callback($callback, $signature);
    if (!$builder instanceof \Civi\Test\CiviEnvBuilder) {
      throw new RuntimeException('ck_headless: callback() did not return the builder');
    }
  }
  return $builder;
}

/**
 * Civi\Test saves the signature only after the last step, so a rebuild that
 * fails halfway keeps the previous one and the next run trusts a wiped schema.
 */
function ck_forget_signature(): void {
  $database = \Civi\Test::dsn('database');
  if (!is_string($database) || \Civi\Test::execute('DELETE FROM `' . $database . '`.civitest_revs') === FALSE) {
    throw new RuntimeException('ck_headless: could not forget the saved signature');
  }
}

/**
 * Each key after its own <requires>, transitively, one install() per
 * extension: the manager installs a list in one pass, and an extension whose
 * schema references a dependency's table then fails its own table lookup.
 * Installing an installed extension is a no-op.
 *
 * Each install replaces the extension system, so every call asks the current
 * one: mixins see an extension as being installed only through its manager.
 *
 * @param list<string> $keys
 */
function ck_install_in_order(array $keys): void {
  foreach ($keys as $key) {
    foreach (\CRM_Extension_System::singleton()->getManager()->findInstallRequirements([$key]) as $each) {
      \CRM_Extension_System::singleton()->getManager()->install([$each]);
    }
  }
}

/**
 * Before 6.20, CoreSchemaStep::getSig() drops the core foreign keys
 * (civicrm-core#36980); re-running its cached SQL once per process puts them
 * back. On 6.20 and later the keys stay, and re-adding them fails.
 * Drop when the supported floor carries that fix.
 */
function ck_restore_core_foreign_keys(): void {
  static $restored = FALSE;
  if ($restored || version_compare(\CRM_Utils_System::version(), '6.20.alpha1', '>=')) {
    return;
  }
  $restored = TRUE;
  \Civi\Test::asPreInstall(static function (): void {
    $sql = (new \Civi\Test\CiviEnvBuilder\CoreSchemaStep())->getSql();
    $database = \Civi\Test::dsn('database');
    $content = is_array($sql) ? $sql['content'] ?? NULL : NULL;
    if (!is_string($database) || !is_string($content)) {
      throw new RuntimeException('ck_headless: no database name or no core schema SQL');
    }
    if (\Civi\Test::execute('USE `' . $database . '`;' . "\n" . $content) === FALSE) {
      throw new RuntimeException('ck_headless: could not restore the core foreign keys');
    }
  });
}

/**
 * A content hash of the files that decide what install() produces: info.xml,
 * the main file, the upgrader class, every *.mgd.php, and schema/, xml/, sql/.
 * Dot directories, vendor/ and node_modules/ belong to other packages or tools.
 * Hashed once per process: setUpHeadless() runs before every test.
 */
function ck_install_signature(string $dir, SimpleXMLElement $info): string {
  /** @var array<string, string> $signatures */
  static $signatures = [];
  if (isset($signatures[$dir])) {
    return $signatures[$dir];
  }
  $stems = array_filter([trim((string) $info->file), str_replace(['_', '\\'], '/', trim((string) $info->upgrader))]);
  $files = ['info.xml', ...array_map(static fn (string $stem): string => $stem . '.php', $stems)];
  $tree = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
    static fn (SplFileInfo $item): bool => (
      !$item->isDir()
      || !(
        str_starts_with($item->getFilename(), '.') || in_array($item->getFilename(), ['vendor', 'node_modules'], TRUE)
      )
    ),
  ));
  /** @var SplFileInfo $item */
  foreach ($tree as $item) {
    $relative = substr($item->getPathname(), strlen($dir) + 1);
    if (str_ends_with($relative, '.mgd.php') || preg_match('@^(schema|xml|sql)/@', $relative) === 1) {
      $files[] = $relative;
    }
  }
  $files = array_unique($files);
  sort($files, SORT_STRING);
  $digest = '';
  foreach (array_filter($files, static fn (string $relative): bool => is_file($dir . '/' . $relative)) as $relative) {
    $digest .= $relative . "\0" . hash_file('sha256', $dir . '/' . $relative) . "\n";
  }
  return $signatures[$dir] = 'ck-install-files:' . hash('sha256', $digest);
}

/**
 * Data::populate() reconciles managed entities before SearchKit's entity types
 * load, queueing "Unknown entity SearchDisplay" errors in the session.
 * Drop when the supported floor carries civicrm-core#36991.
 */
function ck_discard_bootstrap_status(): void {
  \CRM_Core_Session::singleton()->getStatus(reset: TRUE);
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

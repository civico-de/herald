<?php

declare(strict_types = 1);

/**
 * \Civi\Test::headless() with the info.xml <requires> closure and then
 * $optional installed before this extension; installMe() skips <requires>.
 * The closure is resolved inside the step: before it, the schema is not rebuilt.
 *
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
 * One install() per extension, each after its <requires>: in one pass, a
 * schema referencing a dependency's table fails. Each install replaces the
 * extension system, so every call asks the current singleton.
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
 * (civicrm-core#36980); re-running its SQL once per process restores them.
 * A database another core version built is rebuilt instead.
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
    $built = ck_test_database_version($database);
    if ($built === NULL) {
      return;
    }
    if ($built !== \CRM_Utils_System::version()) {
      fwrite(
        STDERR,
        "ck_headless: {$database} was built by CiviCRM {$built}, running "
        . \CRM_Utils_System::version()
        . "; rebuilding it\n",
      );
      if (\Civi\Test::execute('DROP TABLE IF EXISTS `' . $database . '`.civitest_revs') === FALSE) {
        throw new RuntimeException('ck_headless: could not forget the saved signature');
      }
      return;
    }
    if (\Civi\Test::execute('USE `' . $database . '`;' . "\n" . $content) === FALSE) {
      throw new RuntimeException('ck_headless: could not restore the core foreign keys');
    }
  });
}

/**
 * civicrm_domain.version of the scratch database; NULL while it has no schema,
 * where there are no keys to restore.
 */
function ck_test_database_version(string $database): ?string {
  $pdo = \Civi\Test::pdo();
  $tables = $pdo->query(
    'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '
    . $pdo->quote($database)
    . " AND TABLE_NAME = 'civicrm_domain'",
  );
  if ($tables === FALSE || (int) $tables->fetchColumn() === 0) {
    return NULL;
  }
  $version = $pdo->query('SELECT version FROM `' . $database . '`.civicrm_domain ORDER BY id LIMIT 1');
  $value = $version === FALSE ? FALSE : $version->fetchColumn();
  if (!is_string($value) || $value === '') {
    throw new RuntimeException("ck_headless: no core version in {$database}.civicrm_domain");
  }
  return $value;
}

/**
 * Content hash of the files install() depends on: info.xml, the main file, the
 * upgrader, *.mgd.php, schema/, xml/, sql/. Once per process.
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

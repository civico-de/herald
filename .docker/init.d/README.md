# First-boot provisioning hooks

Scripts here run once after a fresh auto-install, in lexical order:
`*.sh` via bash (as root), `*.php` via `cv scr` (as www-data). A failing
hook aborts the boot — broken provisioning is loud, not silent.

Use them for everything a reproducible dev/test setup needs beyond
`cv ext:enable`: API users with scoped roles, seed rows, settings,
fixture imports. Re-run from scratch with `docker compose down -v && up -d`
(a plain `restart` does NOT re-run hooks).

Note: the `civicrm_test` scratch DB and cv's `TEST_DB_DSN` are provisioned
by the civikitchen entrypoint itself — no hook needed for headless tests.

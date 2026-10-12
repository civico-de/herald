# First-boot provisioning hooks

Data the extension needs — dependencies, settings, seed records, API users,
fixture imports — belongs in the profile `../profiles/dev/profile.json`
(format: civikitchen `docs/images.md`, "Profiles"). Its dependencies are
enabled before this extension; its config, seed and scripts run after it.

Hooks here prepare the environment of the stack itself, for example a tool
the tests call. They run once per database after the profile, in lexical
order: `*.sh` via bash, `*.php` via `cv scr`, both as the image's web user. A
failing hook aborts the boot — broken provisioning is loud, not silent.
A tool a `*.sh` hook fetches goes into `~/.local/bin`, which is on `PATH`.
Re-run from scratch with `docker compose down -v && up -d` (a plain
`restart` does NOT re-run hooks).

Note: the `civicrm_test` scratch DB and cv's `TEST_DB_DSN` are provisioned
by the civikitchen entrypoint itself — no hook needed for headless tests.

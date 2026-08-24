# Herald demo seed

`seed.php` creates one synthetic coordinator and configures the logical
`members`, `finance`, and `ops` topics to create assigned CiviCRM activities.
It is safe to run repeatedly: the contact is keyed by a stable external
identifier and existing topic routes are preserved.

This is intentionally the normal `Herald.publish` path, not a demo-only
delivery shortcut. Other extension demos can publish to those logical topics
without knowing a recipient, contact ID, or transport configuration.

# Herald demo seed

Two scripts fill a demo or dev site with synthetic data. The dev stack's
profile (`.docker/profiles/dev`) runs both on first boot, after civikitchen's
`fundraising-data` profile.

## Topics: `seed.php`

`seed.php` creates one synthetic coordinator, an `activity` channel when the
site has none, and configures the logical `members`, `finance`, and `ops`
topics to create assigned CiviCRM activities.
It is safe to run repeatedly: the contact is keyed by a stable external
identifier and existing topic routes are preserved.

This is intentionally the normal `Herald.publish` path, not a demo-only
delivery shortcut. Other extension demos can publish to those logical topics
without knowing a recipient, contact ID, or transport configuration.

## Digests: `digests.php`

`digests.php` needs `seed.php` first, because the digests go to the
coordinator. It creates:

- six donors, keyed by the external identifiers `herald-seed-donor-1` to `-6`;
- completed contributions on most days of the last two months and a few
  older ones, spread over two contribution pages and three financial types,
  with amounts from 5 to 2,500 and sources written the way imports and
  payment processors write them;
- a cleaned field for the contribution source (Bank transfer, Stripe,
  Website, Other), unless the site already cleans it, and a search of last
  week's contributions grouped by it;
- one contribution each that the shipped searches leave out: two pending,
  one failed, one test;
- a price set "Demo shop" (Ticket, T-Shirt, Donation) and orders from it, so
  "Line items sold" lists more than "Contribution Amount";
- completed activities of the last three weeks, three overdue scheduled ones
  and one ahead;
- the digests `daily_report` (yesterday) and `weekly_report` (last week,
  with a section by cleaned source), each with a CiviRules schedule rule at 08:00 that sends it to the
  coordinator as an activity.

All dates are relative to the day of the run, so every period of the shipped
searches has data. To bring them up to date, run the script again:

```bash
cv scr /var/www/html/ext/herald/demo/digests.php
```

A rerun deletes every contribution and activity of the six donors and
creates them anew; anything else entered for those donors is lost too.
Digests and rules are only created when missing, so edits to them stay.

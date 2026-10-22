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

## Feature tour: `tour/`

`tour/run.sh` walks through herald's screens in a browser. It records an
English video with on-screen captions and writes the handbook screenshots:
English ones to `handbook/images/`, German ones to `handbook/images/de/`.

It needs the dev stack running (`cd .docker && docker compose up -d`),
`ffmpeg` on the host, and the pinned Playwright:

```bash
bun install
npx playwright install chromium
bun run tour
```

The video lands in `demo/tour/out/herald-tour.mp4`, which is ignored by git.
The screenshots are committed; rerun the tour after a screen changes.

Before the browser starts, the script runs `digests.php` and
`tour/prepare.php` on the site. `prepare.php` adds a topic `news` with three
synthetic subscribers, an `email` channel, the rule `herald_tour_donation`
and a parked alert. It also removes the channel `team_mail` and the topic
`donations`, which the tour creates on screen. For the German pass the
script sets the site language to `de_DE` and switches it back to `en_US`
when it exits.

---
title: Digests
order: 40
routes:
  - civicrm/admin/herald
---

# Digests

A digest sums things up in one message, for example yesterday's donations and last week's activities. It is a subject and a text that you write yourself, plus the sources that fill their placeholders:

- a **search** is a SearchKit display. Its column headers name the values, and its filters decide what counts, including the period;
- a **section** is a set of values another extension provides through a hook, for numbers no search can answer.

A source named `donations` that shows the sum and the count of yesterday's contributions fills `{$donations.sum}` and `{$donations.count}`. The text decides what the message says and how it looks:

```smarty
📊 <b>Yesterday</b>
Donations: {$donations.sum|crmMoney} ({$donations.count})
```

herald renders subject and text with Smarty, as CiviCRM renders a message template.

For news of every new contribution, no digest is needed: a rule sends each one as it comes in, or collects them into one message every few minutes, see [Aggregation](delivery.md#aggregation).

## Setting up a digest

1. Under Administer » Herald » Digests, choose "Add digest". Give it a title and a name.
2. Add a source for each part of the message and give it a name, such as `donations`. For a ready-made source, pick the figures and the period, for example "Contributions: sum and count" for last week. "Own search from SearchKit" takes any table, list or grid display instead. A section from another extension takes its key.
3. Below each source the form lists its placeholders and offers a snippet that uses them, with amounts and dates already formatted. Copy the snippet into the text and change it as you like. Write anything you like between them.
4. Fill in the subject. Left empty, the digest's title is the subject.
5. "Preview with today's data" composes the digest as the form holds it. It saves and sends nothing.

The **name** is what rules and scripts use to pick the digest. It takes lowercase letters, digits and underscores, and no two digests share one. Source names start with a lowercase letter, followed by lowercase letters, digits and underscores. They must differ within a digest, and `smarty` is reserved.

Saving checks the whole digest before anything is written: the name, the text, every source, and whether subject and text compose with today's values. The form lists every problem it finds. Through APIv4, each `HeraldDigest` and `HeraldDigestSource` record gets the same field checks, and a new subject or text of a stored digest must render with its sources before it is saved. A source that does not load at that moment does not block the edit; it fails when the digest is sent.

Scripts and deploys can create a digest with cv. JSON spread over several lines has to come through standard input, hence `--in=json`:

```bash
cv api4 --in=json HeraldDigest.create <<'JSON'
{
  "values": {"name": "weekly_report", "title": "Weekly report",
    "msg_subject": "Weekly report", "msg_html": "Donations: {$donations.sum|crmMoney}"},
  "chain": {"sources": ["HeraldDigestSource", "save", {
    "defaults": {"digest_id": "$id"},
    "records": [
      {"name": "donations", "type": "search", "search_display_id": 12},
      {"name": "seats", "type": "section", "section_key": "myext.seats"}
    ]
  }]}
}
JSON
```

## Placeholders

A search source hands every value column of its display to the text, named after the column header. The name is the header in lowercase, with umlauts spelled out and every other character turned into `_`: "Plätze gesamt" becomes `{$source.plaetze_gesamt}`. The form refuses a header that would give the name `rows`, starts with a digit, or gives two columns the same name. Rename the header in SearchKit.

- `{$source.sum}` and the other names hold the values of the first row. A search with one row, such as a total, needs nothing more.
- `{$source.rows}` holds every row, each with the same names. Loop over it for a list:

  ```smarty
  {foreach from=$donations.rows item=row}
  • {$row.form}: {$row.sum|crmMoney}
  {/foreach}
  ```

- Without rows, every value is empty and `rows` is an empty list. A sum or a count of no rows is 0.
- The values arrive raw, as the search returns them: amounts as numbers, dates as `2026-10-13 09:00:00`. Format them in the text with `|crmMoney` and `|crmDate`; the snippet does this already.

The display decides which rows go into the message. Its filters and sort apply, and so does its limit. A display with a pager sends only its first page. Give a display that lists records a limit, because every row goes into the message and only Telegram shortens a message that is too long. Only table, list and grid displays can be picked, because the other types have no rows. Links, buttons, menus, images and subsearches give no values.

herald runs the display without permission checks. Whoever can configure a digest can therefore send the rows of any display to a channel.

### Writing the text

Smarty does the dates, the arithmetic and the formatting: `{"yesterday"|date_format:"%d.%m.%Y"}` prints yesterday's date, and `{($part * 100 / $total)|round:1}` calculates a percentage. A division by zero stops the digest, so put such a calculation inside `{if $total gt 0}…{/if}`.

The text is HTML, stored exactly as you type it. Line breaks count: Telegram shows them as they are, and email turns them into `<br>`. How each channel shows the HTML is described under [Message format](channels.md#message-format).

A placeholder with a typo, such as `{$donations.sume}`, or a source the digest does not have is an error, not an empty line. Saving the digest and sending it both check this. When a digest cannot be composed at send time, its recipients get a short notice with the digest's title and the reason, and the error also goes to the log.

## Searches herald ships

herald ships searches you can use as they are. The form offers them as ready-made sources, by figures and period. They count no test contributions and no recurring templates, and they output no contact data:

| Display | Values |
|---|---|
| Digest: contributions *period* | `sum`, `count` of completed contributions by receive date |
| … by form | `form`, `sum`, `count` per contribution page |
| … by financial type | `financial_type`, `sum`, `count` per financial type |
| Digest: line items *period* | `item`, `price`, `quantity`, `sum` per line item label and price, without free items |
| Digest: contribution amounts *period* | `amount` per contribution |
| Digest: pending contributions | `sum`, `count` of every pending contribution, whatever its age |
| Digest: activities *period* by type and status | `type`, `status`, `count` |
| Digest: overdue activities by type | `type`, `count` of scheduled activities whose date has passed |

The contribution searches exist only while CiviContribute is enabled. The activity searches leave out herald's own notifications, which the activity channel files as activities. The periods are today, yesterday, this week, last week, last 7 days, this month, last month and this year. Apart from the last 7 days, which include today, they are calendar periods: last week is the previous full week, starting on the first day of the week set in CiviCRM's date settings, this week, this month and this year count up to the moment of sending, and today is the whole day, with activities scheduled for later today. herald replaces these searches on each upgrade, so for another period or a filter, copy one in SearchKit and change the copy. Their displays keep the headers they got at install, in the site's language, and the names follow the headers: a German site has `{$donations.summe}`. A later change of language does not rename them, so digests keep working. The form lists the names of each display.

## Charts

Two modifiers draw a column of a source's rows as a text chart. Put them inside `<pre>`, so the bars line up. The snippet beside a source with several rows already holds the fitting one: for a grouped search the bars alone, since they name every group, and for any other the list followed by a histogram. It charts the last amount column, else the last number.

### Bars

`herald_bars` draws one bar per row, sized by a number column and named by one or more other columns, separated by commas. It suits a grouped search such as contributions by form:

```smarty
<pre>{$forms.rows|herald_bars:"sum":"form":"money"}</pre>
<pre>{$activities.rows|herald_bars:"count":"type,status"}</pre>
```

Each line shows the names, each in its own aligned column, then the value and the bar, and the largest value gets the full bar. Bars grow in eighths of a block, so a small value still shows. A third argument `"money"` writes the value as an amount in the site's currency; without it the value is a plain number. Rows keep the order of the display. An empty name becomes "(none)", and without a value above zero the chart says "(no data)".

### Histograms

`herald_histogram` shows how the values of a column spread, such as the amounts of single contributions:

```smarty
<pre>{$amounts.rows|herald_histogram:"amount"}</pre>
<pre>{$amounts.rows|herald_histogram:"amount":5}</pre>
<pre>{$amounts.rows|herald_histogram:"amount":"0,100,1000,10000"}</pre>
```

Without a second argument, it aims for 8 buckets with edges that fit the values. A number sets the number of buckets. Fixed edges, separated by commas and in ascending order, suit a campaign with donation tiers. Values below the first edge land in an extra bucket from 0, values above the last in one labelled "≥" and the edge. Each line shows the bucket, the count with the bucket's sum in brackets, and a bar sized by the count. Empty values and values of zero or less are skipped, and with nothing to show the chart says "(no data)".

## Sections from other extensions

Some numbers no search can answer, such as booked seats from a ticketing extension or the depth of a queue. The extension that owns them answers for its own key through `hook_civicrm_heraldDigestSections`:

```php
function myext_civicrm_heraldDigestSections(array $keys, array $context, array &$sections): void {
  if (in_array('myext.seats', $keys, TRUE)) {
    $sections['myext.seats'] = ['booked' => 138, 'paid' => 120];
  }
}
```

A source of type section with the key `myext.seats` and the name `seats` then fills `{$seats.booked}` and `{$seats.paid}`. An answer holds named values and lists of rows of named values, with names as for sources. `$context` holds the digest's name under `digest`. Lists are looped over as `rows` are.

The answer holds values, not text: the digest's text formats them, and herald escapes them like every other variable. A key that no extension answers, or an answer that is text, fails the digest with a message that names the key, so a typo does not go unnoticed.

## Sending a digest

- A CiviRules rule with the trigger "Herald: Schedule" and the action "Herald: Send digest" sends a digest at a set hour. [Scheduled digests](recipes.md#scheduled-digests) shows the setup.
- The action "Herald: Send digest" works with any other trigger too, and sends the digest each time that trigger fires. The action picks the digest from a list and holds the delivery settings.
- Scripts and the server's crontab can call the API action `Herald.notifyDigest` directly.

All three ways support every [delivery mode](delivery.md#delivery-modes). The subject is the digest's own, and a digest without a subject sends its title. Delivery settings therefore take no subject key such as `email_subject` or `activity_subject`.

`Herald.notifyDigest` takes:

| Parameter | Meaning |
|---|---|
| `digest` | The name of the digest. Required. |
| `actionParams` | The delivery settings, such as `channel`, `chat_id` or `delivery_mode`. The channel defaults to `telegram`. |
| `sendHour` | Optional. The digest goes out only during this hour, from 0 to 23 in CiviCRM's timezone. In any other hour the call returns `skipped` and sends nothing, and a value outside 0 to 23 is an error. Leave it out when you send a digest by hand. |
| `daily` | Optional, with `sendHour`. The digest goes out on the first call at or after `sendHour`, once a day, so a scheduled job can call it on every cron run. Later calls that day return `skipped` with `sent_on`. Each set of delivery settings counts on its own. A call counts as the day's send before anything goes out, so a digest that fails or is empty is not tried again until the next day. `daily` without `sendHour` is an error. |
| `skipEmpty` | Optional. When every source is a search display without rows, the call returns `skipped` with `empty` and sends nothing. A section from an extension always counts as something to report, and so does a digest without sources. A search that only totals, such as a count without grouping, always has one row and never counts as empty. |

```bash
cv api4 Herald.notifyDigest '{"checkPermissions": false, "digest": "weekly_report", "actionParams": {"chat_id": "-1001234567890"}}'
```

### What a change breaks

Rules and scripts pick a digest by its name. After you change a digest's name or delete it, they fail with the message `Digest "weekly_report" does not exist.` until they name another. The rule list shows the same message in place of the digest's title. The title can change freely.

Deleting a source's display leaves the digest without it. It then fails when it is sent, with the message the form would show. Pick another display or remove the source.

## Digests from older releases

Older releases kept a digest inside the settings of its rule action or scheduled job, as a list of blocks. The upgrade turns each of them into a digest record and leaves only the delivery settings and the digest's name in the action. A rule action's digest is named `rule_action_<id>` and takes the rule's title, a job's digest is named `job_<id>` and takes the job's name. You can rename them afterwards. Then pick the digest again in each rule action that sent it, and update the jobs and scripts that name it.

Each old digest gets a text with the header, the block titles and what the blocks printed before: totals, the breakdown, the pending amounts, the campaign progress and the histogram. The blocks become sources:

- A contribution block uses the shipped searches. With a filter or another period than yesterday, it gets copies of them, labelled `Herald: <title>: …`. A campaign filter needs CiviCampaign enabled.
- A search block gets a new table display of its search, with the search's columns, sort and limit and no pager.
- A section block keeps its key. Its extension must answer with values by then, see [Sections from other extensions](#sections-from-other-extensions).
- An email subject from the delivery settings becomes the digest's subject.

The upgrade keeps what the digest counted before:

- An unknown period counted as yesterday and still does. Today, the last 7 and the last 30 days become filters on the copies; the last days end at midnight before the run.
- Histogram edges that are not ascending numbers are dropped with a warning in the upgrade log, and the histogram picks its own buckets. A negative bucket count becomes 1, and a count of 0 becomes the default 8, with a warning.
- A status filter of Completed is dropped, because the searches count completed contributions.
- A block broken down by line item keeps counting items: its totals, pending amounts and campaign progress come from searches on line items, the count is the quantity sold, and a financial type filter picks the items of that type, also out of a mixed cart.
- A block that used a template of its own gets the default text instead, with a warning in the upgrade log. Copy your wording into the digest's text.

The old templates "Herald: Daily contribution digest", "Weekly contribution digest", "Contribution digest block (composable)" and "Digest block from a search display" are removed.

A digest that cannot be reproduced is left as it was. The upgrade log has the warning "digest left inline, it cannot run until fixed" with the reason, nothing is created for it, and the rule or job fails until it names a digest record. Most of these digests could never have run:

- A block list that is not a JSON array of objects, or a block of unknown type.
- A section block without a key, or one whose extension still answers with text or not at all.
- A saved search that no longer exists, or whose entity is gone with its extension.
- A campaign or financial type that no longer exists.

The others ask for something digest records cannot do:

- A saved search that skips its first rows. A display always starts at the first row.
- A status filter other than Completed. This includes a Completed filter in a digest that showed the pending amounts without the line item breakdown, because there the filter also applied to the pending amounts.

A delayed action that was already waiting in the CiviRules queue during the upgrade still carries the old settings and fails once. The next time the rule fires, it uses the new settings.

A digest that took its subject and text from a message template gets both copied into the digest. The template stays under Mailings » Message Templates; delete it once nothing else uses it.

The digest form is an Afform, so herald needs the extension Form Core (`org.civicrm.afform`). Enable it before you upgrade herald on a site that does not have it yet.

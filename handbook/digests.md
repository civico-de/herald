---
title: Digests
order: 40
routes:
  - civicrm/admin/herald
---

# Digests

A digest sums things up in one message, for example yesterday's donations and last week's activities. It is a message template that you write yourself, plus the sources that fill its placeholders:

- a **search** is a SearchKit display. Its column headers name the values, and its filters decide what counts, including the period;
- a **section** is a set of values another extension provides through a hook, for numbers no search can answer.

A source named `donations` that shows the sum and the count of yesterday's contributions fills `{$donations.sum}` and `{$donations.count}`. The template decides what the message says and how it looks:

```smarty
📊 <b>Yesterday</b>
Donations: {$donations.sum|crmMoney} ({$donations.count})
```

## Setting up a digest

1. Under Mailings » Message Templates, add a template on the tab "User-driven Messages". Its subject becomes the subject of the digest. The text can stay empty for now.
2. Under Administer » Herald » Digests, choose "Add digest". Give it a title, a name and the template.
3. Add a source for each part of the message. Give it a name, such as `donations`, and pick a search display or enter a section key.
4. Below each source the form lists its placeholders and offers a snippet that uses them, with amounts and dates already formatted. Copy the snippet into the template and change it as you like.
5. "Preview with today's data" composes the digest as the form holds it. It saves and sends nothing.

The **name** is what rules and scripts use to pick the digest. It takes lowercase letters, digits and underscores, and no two digests share one. Source names start with a lowercase letter, followed by lowercase letters, digits and underscores. They must differ within a digest, and `smarty` is reserved.

Saving checks the whole digest before anything is written: the name, the template, every source, and whether the template composes with today's values. The form lists every problem it finds. Through APIv4, each `HeraldDigest` and `HeraldDigestSource` record gets the same field checks, and the digest is composed when it is sent.

Scripts and deploys can create a digest with cv. JSON spread over several lines has to come through standard input, hence `--in=json`:

```bash
cv api4 --in=json HeraldDigest.create <<'JSON'
{
  "values": {"name": "weekly_report", "title": "Weekly report", "message_template_id": 70},
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

A search source hands every value column of its display to the template, named after the column header. The name is the header in lowercase, with umlauts spelled out and every other character turned into `_`: "Plätze gesamt" becomes `{$source.plaetze_gesamt}`. The form refuses a header that would give the name `rows`, starts with a digit, or gives two columns the same name. Rename the header in SearchKit.

- `{$source.sum}` and the other names hold the values of the first row. A search with one row, such as a total, needs nothing more.
- `{$source.rows}` holds every row, each with the same names. Loop over it for a list:

  ```smarty
  {foreach from=$donations.rows item=row}
  • {$row.form}: {$row.sum|crmMoney}
  {/foreach}
  ```

- Without rows, every value is empty and `rows` is an empty list. A sum or a count of no rows is 0.
- The values arrive raw, as the search returns them: amounts as numbers, dates as `2026-10-13 09:00:00`. Format them in the template with `|crmMoney` and `|crmDate`; the snippet does this already.

The display decides which rows go into the message. Its filters and sort apply, and so does its limit. A display with a pager sends only its first page. Give a display that lists records a limit, because every row goes into the message and only Telegram shortens a message that is too long. Only table, list and grid displays can be picked, because the other types have no rows. Links, buttons, menus, images and subsearches give no values.

herald runs the display without permission checks. Whoever can configure a digest can therefore send the rows of any display to a channel.

### Writing the template

Smarty does the dates, the arithmetic and the formatting: `{"yesterday"|date_format:"%d.%m.%Y"}` prints yesterday's date, and `{($part * 100 / $total)|round:1}` calculates a percentage. A division by zero stops the digest, so put such a calculation inside `{if $total gt 0}…{/if}`.

Write comparisons as `gt`, `lt` and `and`. The template editor stores `>` as `&gt;` and `&&` as `&amp;&amp;`, inside Smarty tags too, and Smarty cannot read that. The error message then says so.

A placeholder with a typo, such as `{$donations.sume}`, or a source the digest does not have is an error, not an empty line. Saving the digest, saving its template and sending it all check this. When a digest cannot be composed at send time, its recipients get a short notice with the digest's title and the reason, and the error also goes to the log.

## Searches herald ships

herald ships searches you can use as they are. They count no test contributions and no recurring templates, and they output no contact data:

| Display | Values |
|---|---|
| Digest: contributions yesterday / last week | `sum`, `count` of completed contributions by receive date |
| … by form | `form`, `sum`, `count` per contribution page |
| … by financial type | `financial_type`, `sum`, `count` per financial type |
| Digest: line items yesterday / last week | `item`, `price`, `quantity`, `sum` per line item label and price, without free items |
| Digest: contribution amounts yesterday / last week | `amount` per contribution, for a histogram |
| Digest: pending contributions | `sum`, `count` of every pending contribution, whatever its age |
| Digest: activities yesterday / last week by type and status | `type`, `status`, `count` |
| Digest: overdue activities by type | `type`, `count` of scheduled activities whose date has passed |

The contribution searches exist only while CiviContribute is enabled. Yesterday and last week are calendar periods. Last week is the previous full week, starting on the first day of the week set in CiviCRM's date settings. herald replaces these searches on each upgrade, so for another period or a filter, copy one in SearchKit and change the copy. Their displays keep the headers they got at install, in the site's language, and the names follow the headers: a German site has `{$donations.summe}`. A later change of language does not rename them, so templates keep working. The form lists the names of each display.

## Histograms

The modifier `herald_histogram` draws a column of a source's rows as a text chart. Put it inside `<pre>`, so the bars line up:

```smarty
<pre>{$amounts.rows|herald_histogram:"amount"}</pre>
<pre>{$amounts.rows|herald_histogram:"amount":5}</pre>
<pre>{$amounts.rows|herald_histogram:"amount":"0,100,1000,10000"}</pre>
```

Without a second argument, it aims for 8 buckets with edges that fit the values. A number sets the number of buckets. Fixed edges, separated by commas and in ascending order, suit a campaign with donation tiers. Values below the first edge land in an extra bucket from 0, values above the last in one labelled "≥" and the edge. Empty values and values of zero or less are skipped, and with nothing to show the chart says "(no data)".

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

The answer holds values, not text: the template formats them, and herald escapes them like every other variable. A key that no extension answers, or an answer that is text, fails the digest with a message that names the key, so a typo does not go unnoticed.

## Sending a digest

- A CiviRules rule with the trigger "Herald: Schedule" and the action "Herald: Send digest" sends a digest at a set hour. [Scheduled digests](recipes.md#scheduled-digests) shows the setup.
- The action "Herald: Send digest" works with any other trigger too, and sends the digest each time that trigger fires. The action picks the digest from a list and holds the delivery settings.
- Scripts and the server's crontab can call the API action `Herald.notifyDigest` directly.

All three ways support every [delivery mode](delivery.md#delivery-modes). The subject comes from the template, and a template without a subject sends the digest's title. Delivery settings therefore take no `email_subject`.

`Herald.notifyDigest` takes:

| Parameter | Meaning |
|---|---|
| `digest` | The name of the digest. Required. |
| `actionParams` | The delivery settings, such as `channel`, `chat_id` or `delivery_mode`. The channel defaults to `telegram`. |
| `sendHour` | Optional. The digest goes out only during this hour, from 0 to 23 in CiviCRM's timezone. In any other hour the call returns `skipped` and sends nothing, and a value outside 0 to 23 is an error. Leave it out when you send a digest by hand. |

```bash
cv api4 Herald.notifyDigest '{"checkPermissions": false, "digest": "weekly_report", "actionParams": {"chat_id": "-1001234567890"}}'
```

### What a change breaks

Rules and scripts pick a digest by its name. After you change a digest's name or delete it, they fail with the message `Digest "weekly_report" does not exist.` until they name another. The rule list shows the same message in place of the digest's title. The title can change freely.

A template edit that breaks a digest using the template is refused, both in the template form and through APIv4, with the digest's title and the error. Deleting the template or a source's display leaves the digest without it. It then fails when it is sent, with the message the form would show. Pick another template or display, or remove the source.

## Digests from older releases

Older releases kept a digest inside the settings of its rule action or scheduled job, as a list of blocks. The upgrade turns each of them into a digest record and leaves only the delivery settings and the digest's name in the action. A rule action's digest is named `rule_action_<id>` and takes the rule's title, a job's digest is named `job_<id>` and takes the job's name. You can rename them afterwards. Then pick the digest again in each rule action that sent it, and update the jobs and scripts that name it.

Each old digest gets its own message template, `Herald: <title>`, with the header, the block titles and a text that prints what the blocks printed before: totals, the breakdown, the pending amounts, the campaign progress and the histogram. The blocks become sources:

- A contribution block uses the shipped searches. With a filter or another period than yesterday, it gets copies of them, labelled `Herald: <title>: …`. A campaign filter needs CiviCampaign enabled.
- A search block gets a new table display of its search, with the search's columns, sort and limit and no pager.
- A section block keeps its key. Its extension must answer with values by then, see [Sections from other extensions](#sections-from-other-extensions).
- An email subject from the delivery settings moves into the template.

The upgrade keeps what the digest counted before:

- An unknown period counted as yesterday and still does. Today, the last 7 and the last 30 days become filters on the copies; the last days end at midnight before the run.
- Histogram edges that are not ascending numbers are dropped with a warning in the upgrade log, and the histogram picks its own buckets. A negative bucket count becomes 1, and a count of 0 becomes the default 8, with a warning.
- A status filter of Completed is dropped, because the searches count completed contributions.
- A block broken down by line item keeps counting items: its totals, pending amounts and campaign progress come from searches on line items, the count is the quantity sold, and a financial type filter picks the items of that type, also out of a mixed cart.
- A block that used a template of its own gets the default text instead, with a warning in the upgrade log. Copy your wording into the new template.

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

The digest form is an Afform, so herald needs the extension Form Core (`org.civicrm.afform`). Enable it before you upgrade herald on a site that does not have it yet.

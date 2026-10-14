---
title: Digests
order: 40
routes:
  - civicrm/admin/herald
---

# Digests

A digest sums up a period in one message, for example yesterday's donations. Each digest is a record with a name, and a list of blocks that are rendered one after another:

| Block type | Content | Default template |
|---|---|---|
| Contributions | Contributions of a period: the total, a breakdown by source, the open pending amounts, campaign progress and an amount histogram | Herald: Contribution digest block (composable) |
| Search display | The rows of a SearchKit display | Herald: Digest block from a search display |
| Section from an extension | Text another extension provides through a hook | none |

## Setting up a digest

Digests are under Administer » Herald » Digests. "Add digest" opens the form, and each row of the list has Edit and Delete.

- **Title** names the digest in lists and in CiviRules.
- **Name** is what rules and scripts use to pick the digest. It takes lowercase letters, digits and underscores, and no two digests share one.
- **Period** applies to every block that does not set its own: yesterday, today, the last 7 days or the last 30 days.
- **Header** is an optional line above the blocks, inserted as HTML, for example `📊 <b>Daily report</b>`.

Below these come the blocks, at least one, in the order they appear in the message. Every block has a type, a title and, if it needs one, its own period. Contribution and search blocks also take a template. Leave it empty to use the block type's default. The other fields depend on the type and are described under [Block types](#block-types).

Saving checks the whole digest before anything is written. The form refuses:

- a name that is taken or has the wrong characters,
- a search block without a display,
- a section block without a key,
- a bucket count below 1,
- histogram edges that are not at least two numbers in ascending order.

A message about a block names it, for example "Block 2: a search block needs a search display." Through APIv4 the same checks apply to each `HeraldDigest` and `HeraldDigestBlock` record. A digest saved there without blocks fails when it is sent.

Scripts and deploys can create a digest with cv. The chain saves the blocks in the order given. JSON spread over several lines has to come through standard input, hence `--in=json`:

```bash
cv api4 --in=json HeraldDigest.create <<'JSON'
{
  "values": {"name": "weekly_report", "title": "Weekly report", "period": "last_7_days", "header": "📈 <b>Weekly report</b>"},
  "chain": {"blocks": ["HeraldDigestBlock", "save", {
    "defaults": {"digest_id": "$id"},
    "records": [
      {"type": "contribution", "title": "Donations", "financial_type_id": 1},
      {"type": "section", "section_key": "myext.seats"}
    ]
  }]}
}
JSON
```

### What a change breaks

Rules and scripts pick a digest by its name. After you change a digest's name or delete it, they fail with the message `Digest "weekly_report" does not exist.` until they name another. The rule list shows the same message in place of the digest's title. The title can change freely.

A campaign or a financial type that a block filters on cannot be deleted while the block exists, and the attempt ends in a database error. Without its filter the block would count every contribution. Change or remove the block first.

A search display can be deleted, and so can its saved search, which takes its displays with it. The block that showed it keeps no display, and the digest fails when it is sent, with the message the form would have shown. Pick another display or remove the block.

## Sending a digest

- A CiviRules rule with the trigger "Herald: Schedule" and the action "Herald: Send digest" sends a daily or weekly digest at a set hour. [Scheduled digests](recipes.md#scheduled-digests) shows the setup.
- The action "Herald: Send digest" works with any other trigger too, and sends the digest each time that trigger fires. The action picks the digest from a list and holds the delivery settings.
- Scripts and the server's crontab can call the API action `Herald.notifyDigest` directly.

All three ways support every [delivery mode](delivery.md#delivery-modes).

`Herald.notifyDigest` takes:

| Parameter | Meaning |
|---|---|
| `digest` | The name of the digest. Required. |
| `actionParams` | The delivery settings, such as `channel`, `chat_id` or `delivery_mode`. The channel defaults to `telegram`. |
| `sendHour` | Optional. The digest goes out only during this hour, from 0 to 23 in CiviCRM's timezone. In any other hour the call returns `skipped` and sends nothing, and a value outside 0 to 23 is an error. Leave it out when you send a digest by hand. |

```bash
cv api4 Herald.notifyDigest '{"checkPermissions": false, "digest": "weekly_report", "actionParams": {"chat_id": "-1001234567890"}}'
```

For email, a digest with one block uses that block's template subject. A digest with several blocks has no common subject. Set `email_subject` in the delivery settings, or the subject is "Notification".

## Block types

### Contribution blocks

| Field | API name | Default | Meaning |
|---|---|---|---|
| Campaign | `campaign_id` | none | Count only this campaign's contributions and add its progress. |
| Financial Type | `financial_type_id` | none | Count only this financial type. |
| Source Pattern | `source_pattern` | none | A SQL LIKE pattern on the contribution source, for example `%Bank TX%`. |
| Break Down By | `group_by` | contribution source | "Line item and unit price" breaks the numbers down by line item label and unit price. |
| Show Pending Pool | `show_pending` | on | Show the open pending amounts. |
| Show Histogram | `show_histogram` | on | Show the amount histogram. |
| Histogram Buckets | `bucket_count` | 8 | The number of histogram buckets to aim for. |
| Histogram Bucket Edges | `bucket_edges` | chosen to fit | Fixed bucket edges as a JSON array, for example `[0, 100, 1000, 10000]`. |

How a contribution block counts:

- Completed contributions count by their receive date inside the period, not by the day they became Completed. Test contributions and recurring templates never count.
- Yesterday and today are calendar days. The last 7 and the last 30 days end at midnight before the run, so today is not included.
- The pending amounts are every open pending contribution, whatever its age.
- A contribution without a source is listed as "Unknown".
- Amounts are added up as numbers without currency conversion. With several currencies the totals mean nothing.

With the line item breakdown, the totals, the counts and the pending amounts come from the line items. A mixed cart is split across its items, and the counts are item quantities. Items with a line total of zero or less are left out. The financial type filter then applies to each item, not to the contribution. Only the composable block template prints the items, so leave the template empty for this view.

The histogram shows the completed contributions of the period that match the filters, and ignores amounts of zero or less. It always uses whole contribution amounts, so with the line item breakdown its financial type filter applies to the contribution. With nothing to show, it says "(no data)".

Without fixed edges, herald picks bucket edges that fit the amounts. Fixed edges suit a campaign that is told in tiers, such as a major donor campaign. Give at least two values, for example `[0, 100, 1000, 10000, 100000, 1000000]`. Amounts below the first or above the last edge land in extra buckets labelled "<low" and "≥high". With the histogram switched off, the edges are not checked.

The campaign progress takes the title and the goal from the Campaign record. It counts every completed contribution of the campaign that matches the financial type and source filters, regardless of the period. With the line item breakdown it counts the campaign's items instead. A campaign without a goal shows no progress line.

### Search blocks

A search block shows the rows of a SearchKit display, picked in the field Search Display (`search_display_id`). Build the display in SearchKit as usual. To show counts, group the search, for example memberships by type.

The default template prints one line per row. The display decides what goes into it: its value columns appear in their order and formatted as in the display, so a yes/no field reads "Yes" and a date reads as the site formats dates. Link, button and menu columns are left out. The display's sort applies. A display without a sort of its own shows the rows unsorted, even when the search has an order. Its limit applies too, and the first page if it has a pager. Give the display a limit, because every row goes into the message and only Telegram shortens a message that is too long.

herald runs the display without permission checks, as it does with its own contribution queries. Whoever can configure a digest can therefore send the rows of any display to a channel.

### Section blocks

Some numbers no query can answer, such as booked seats from a ticketing extension or the depth of a queue. The extension that owns them can provide a section through the hook `hook_civicrm_heraldDigestSections`:

```php
function myext_civicrm_heraldDigestSections(array $keys, array $context, array &$sections): void {
  if (in_array('myext.seats', $keys, TRUE)) {
    $sections['myext.seats'] = "🪑 <b>Seats</b>\n138 booked";
  }
}
```

A block with the section key `myext.seats` inserts the answer. `$context` holds the digest's name under `digest` and the block's period under `period`, so the listener can match the digest's timeframe.

The answer is inserted as HTML, so the listener escapes its own data. An empty answer means there is nothing to report, and the block is left out. A key that no extension answers fails the digest with the error "no extension answers the digest section", so a typo does not go unnoticed.

## Digests from older releases

Older releases kept a digest inside the settings of its rule action or scheduled job. The upgrade turns each of them into a record and leaves only the delivery settings and the digest's name in the action. A rule action's digest is named `rule_action_<id>` and takes the rule's title, a job's digest is named `job_<id>` and takes the job's name. You can rename them afterwards. Then pick the digest again in each rule action that sent it, and update the jobs and scripts that name it.

The upgrade keeps what the digest sent before. A period herald did not know was read as yesterday, and it still is. Histogram edges that are not ascending numbers are dropped with a warning in the upgrade log, and the histogram picks its own buckets. A search block gets a new table display of its search, labelled "Herald: " and the digest's title, with the search's columns, sort and limit and no pager. Its values now appear formatted, as the display shows them. A digest that could never have run is left as it was, and the upgrade log has the warning "digest left inline, it cannot run until fixed" with the reason. Examples are a block list that is not a JSON array of objects, a block of unknown type, a section block without a key, a saved search, campaign or financial type that no longer exists, and a contribution status filter, which records no longer have. Such a rule or job fails until it names a digest.

The digest form is an Afform, so herald needs the extension Form Core (`org.civicrm.afform`). Enable it before you upgrade herald on a site that does not have it yet.

## Source normalisation

Contribution sources are often technical, such as a payment processor's reference or the transaction prefix of a bank import. The setting `herald_source_normalization` maps them to readable labels. The labels appear in the digest breakdown and in the source of contribution notifications. The setting is empty by default, which leaves sources as they are.

The rules are under Administer » Herald » Contribution source names. Each row holds the source text, how it is matched and the name to show. The arrow buttons change the order in which the rules are tried. Saving rejects an incomplete rule and a regular expression that does not compile, and names the rule.

With cv:

```bash
cv api4 --in=json Setting.set <<'JSON'
{"values": {"herald_source_normalization": [
  {"match": "Bank TX", "type": "contains", "label": "Bank transfer"},
  {"match": "Ueberweisung", "type": "prefix", "label": "Bank transfer"},
  {"match": "/^pi_[A-Za-z0-9]+$/", "type": "regex", "label": "Stripe"}
]}}
JSON
```

- The rules are tried in order, and the first match wins.
- The type is contains, prefix or regex. Contains and prefix are case-sensitive.
- A regex uses PHP's preg syntax, including the delimiters.
- A malformed rule that reaches the setting is ignored, so a typo cannot break an alert. The screen and `Herald.saveSourceRules` reject it before it is stored.
- In the digest, sources with the same label are added up into one line.

## Template variables

Contribution block templates and the daily and weekly digest templates receive:

| Variable | Content |
|---|---|
| period.label, period.start, period.end | The name of the period and its bounds. The label is the date for yesterday and today, and "last 7 days" or "last 30 days" otherwise. |
| totals.amount, totals.count | The completed contributions of the period. With the line item breakdown, the item totals and quantities. |
| by_source | A list of source, total and count. |
| by_item | With the line item breakdown, a list of label, unit_price, qty and total. |
| pending.amount, pending.count | The open pending contributions. |
| pending_items | With the line item breakdown, the pending items, like by_item. |
| campaign.title, campaign.goal, campaign.total, campaign.count, campaign.percent | The campaign progress when the block names a campaign. The goal is 0 when the campaign has none. The percentage is rounded to one decimal. |
| histogram | The histogram as preformatted text. It is empty when the histogram is off. |
| block_title | The block's title. |

Search block templates receive block_title, count, which is the number of rows, labels, the column headings in order, and rows. Each row has cells, the column values in order, and values, the same values keyed by the column's field. In the keys, every character that is not a letter, digit or underscore becomes an underscore. The column `contact_type:label` becomes `contact_type_label`, and `COUNT(id) AS n` becomes `n`.

In a template, these are Smarty variables such as `{$totals.amount|crmMoney}`.

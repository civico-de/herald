---
title: Digests
order: 40
routes:
  - civicrm/admin/herald
---

# Digests

A digest sums up what happened over a period in one message. It is made of blocks, which are rendered one after another:

| Block type | Content | Default template |
|---|---|---|
| contribution | Contributions of a period: the total, a breakdown by source, the open pending amounts, campaign progress and an amount histogram | Herald: Contribution digest block (composable) |
| search | The rows of a SearchKit saved search | Herald: Digest block from a saved search |
| section | Text another extension provides through a hook | none |

A digest without blocks is a single contribution block. Its settings are given at the top level, and it is rendered with the template you name, usually "Herald: Daily contribution digest" or "Herald: Weekly contribution digest".

## Ways to send a digest

- A CiviRules rule with the trigger "Herald: Schedule" and the action "Herald: Send digest" sends daily or weekly summaries at a set hour. [Scheduled digests](recipes.md#scheduled-digests) shows the setup.
- The action "Herald: Send digest" works with any other trigger too, and sends a digest each time that trigger fires.
- Scripts and the server's crontab can call the API action `Herald.notifyDigest` directly.

All three support every [delivery mode](delivery.md#delivery-modes).

For email, a digest with one block uses that block's template subject. A digest with several blocks has no common subject. Set `email_subject` for it, or the subject is "Notification".

## Digest settings

In the CiviRules action, each setting has a form field. For `Herald.notifyDigest`, the settings go into `actionParams`, together with the delivery settings such as `channel` and `chat_id`.

| Setting | Default | Meaning |
|---|---|---|
| `blocks` | none | A JSON array of blocks, see [Block types](#block-types). Without it, the top-level settings form the one block. |
| `header` | none | A line above the blocks. It is inserted as HTML, for example `📊 <b>Daily report</b>`. |
| `template` | the block type's default | The template of the single block. With `blocks`, each block names its own. |
| `channel` | `telegram` | The channel key. |
| `period` | `yesterday` | `yesterday`, `today`, `last_7_days` or `last_30_days`. Each block can set its own. Any other value counts as yesterday. |

A block takes nothing from the top level except the period.

`Herald.notifyDigest` also takes `sendHour`, outside `actionParams`. With it, the digest goes out only during that hour of the day, from 0 to 23 in CiviCRM's timezone. In any other hour the call returns `skipped` and sends nothing, and a value outside 0 to 23 is an error. Leave it out when you run a digest by hand. A rule sets its hour in the "Herald: Schedule" trigger instead.

## Block types

Every block takes `type`, which is contribution when left out, and `period`. Contribution and search blocks also take `title` and `template`. The title is plain text.

The CiviRules form checks the blocks before it saves them. It reports a list that is not a JSON array of objects, an unknown type, a search block without `search`, a section block without `key`, and a saved search that does not exist. A scheduled job fails on the same mistakes when it runs, and the job log names the reason.

### Contribution blocks

| Setting | Default | Meaning |
|---|---|---|
| `campaign_id` | none | Count only this campaign's contributions and add its progress. |
| `financial_type_id` | none | Count only this financial type. |
| `source_pattern` | none | A SQL LIKE pattern on the contribution source, for example `%Bank TX%`. |
| `group_by` | source | `line_item` breaks the numbers down by line item label and unit price. The form calls this "Break down by". |
| `show_pending` | on | Show the open pending amounts. |
| `show_histogram` | on | Show the amount histogram. |
| `bucket_count` | 8 | The number of histogram buckets to aim for. |
| `bucket_edges` | chosen to fit | Fixed bucket edges as a JSON array, for example `[0, 100, 1000, 10000]`. |

How a contribution block counts:

- Completed contributions count by their receive date inside the period, not by the day they became Completed. Test contributions and recurring templates never count.
- Yesterday and today are calendar days. The last 7 and the last 30 days end at midnight before the run, so today is not included.
- The pending amounts are every open pending contribution, whatever its age.
- A contribution without a source is listed as "Unknown".
- Amounts are added up as numbers without currency conversion. With several currencies the totals mean nothing.

With the line item breakdown, the totals, the counts and the pending amounts come from the line items. A mixed cart is split across its items, and the counts are item quantities. Items with a line total of zero or less are left out. The financial type filter then applies to each item, not to the contribution. Only the composable block template prints the items, so use blocks for this view.

The histogram shows the completed contributions of the period that match the filters, and ignores amounts of zero or less. It always uses whole contribution amounts, so with the line item breakdown its financial type filter applies to the contribution. With nothing to show, it says "(no data)".

Without fixed edges, herald picks bucket edges that fit the amounts. Fixed edges suit a campaign that is told in tiers, such as a major donor campaign. Give at least two values:

```json
"bucket_edges": [0, 100, 1000, 10000, 100000, 1000000]
```

Amounts below the first or above the last edge land in extra buckets labelled "<low" and "≥high".

The campaign progress takes the title and the goal from the Campaign record. It counts every completed contribution of the campaign that matches the financial type and source filters, regardless of the period. With the line item breakdown it counts the campaign's items instead. A campaign without a goal shows no progress line.

### Search blocks

A search block shows the rows of a SearchKit saved search. Name the search by its machine name. To show counts, group the search, for example memberships by type:

```json
{"type": "search", "search": "memberships_by_type", "title": "Members"}
```

The default template prints one line per row, with the search's columns in order. Only the search's query counts. Its displays play no part. Give the search a limit, because every row goes into the message and only Telegram shortens a message that is too long.

herald runs the search without permission checks, as it does with its own contribution queries. Whoever can configure a digest can therefore send the rows of any saved search to a channel.

### Section blocks

Some numbers no query can answer, such as booked seats from a ticketing extension or the depth of a queue. The extension that owns them can provide a section through the hook `hook_civicrm_heraldDigestSections`:

```php
function myext_civicrm_heraldDigestSections(array $keys, array $context, array &$sections): void {
  if (in_array('myext.seats', $keys, TRUE)) {
    $sections['myext.seats'] = "🪑 <b>Seats</b>\n138 booked";
  }
}
```

The block `{"type": "section", "key": "myext.seats"}` inserts the answer. `$context` holds the digest settings under `params` and the block's period under `period`, so the listener can match the digest's timeframe.

The answer is inserted as HTML, so the listener escapes its own data. An empty answer means there is nothing to report, and the block is left out. A key that no extension answers fails the digest with the error "no extension answers the digest section", so a typo does not go unnoticed.

### Example

Four blocks: donations of one campaign, ticket sales by item, members by type and a section from another extension.

```json
[
  {"title": "Donations", "financial_type_id": 1, "campaign_id": 2},
  {"title": "Tickets", "financial_type_id": 4, "group_by": "line_item", "show_histogram": false},
  {"type": "search", "search": "memberships_by_type", "title": "Members"},
  {"type": "section", "key": "myext.seats"}
]
```

## Source normalisation

Contribution sources are often technical, such as a payment processor's reference or the transaction prefix of a bank import. The setting `herald_source_normalization` maps them to readable labels. The labels appear in the digest breakdown and in the source of contribution notifications. The setting is empty by default, which leaves sources as they are.

The rules are under Administer » Herald » Contribution source names. Each row holds the source text, how it is matched and the name to show. The arrow buttons change the order in which the rules are tried. Saving rejects an incomplete rule and a regular expression that does not compile, and names the rule.

With cv:

```bash
cv api4 Setting.set +v 'herald_source_normalization=[
  {"match": "Bank TX", "type": "contains", "label": "Bank transfer"},
  {"match": "Ueberweisung", "type": "prefix", "label": "Bank transfer"},
  {"match": "/^pi_[A-Za-z0-9]+$/", "type": "regex", "label": "Stripe"}
]'
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

Search block templates receive block_title, count, which is the number of rows, and rows. Each row has cells, the column values in order, and values, the same values keyed by column name. In the keys, every character that is not a letter, digit or underscore becomes an underscore. The column `contact_type:label` becomes `contact_type_label`, and `COUNT(id) AS n` becomes `n`.

In a template, these are Smarty variables such as `{$totals.amount|crmMoney}`.

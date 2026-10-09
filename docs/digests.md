# Digests

A digest is one message built from blocks, each rendered on its own and joined in order:

| Block type | Content | Default template |
|---|---|---|
| `contribution` (default) | Contributions of a period: total, breakdown by source, open pending amounts, campaign progress, amount histogram | `herald_contribution_digest_block` |
| `search` | The rows of a SearchKit saved search | `herald_digest_search_block` |
| `section` | A body another extension contributes through a hook | none, inserted as HTML |

Without `blocks` the digest is a single contribution block whose parameters sit at the
top level, rendered with `template` (`herald_contribution_digest_daily`,
`herald_contribution_digest_weekly` or your own workflow).

## Ways to send one

| Sender | Use for | Delivery |
|---|---|---|
| Scheduled job running `Herald.notifyDigest` | Daily or weekly summaries | All [delivery modes](delivery.md#delivery-modes) |
| CiviRules action **Herald: Send digest** | A digest fired by a rule's trigger | All delivery modes |
| `Herald.notifyDigest` (APIv4) | Scripts | All delivery modes |

CiviRules has no plain time-of-day trigger, so regular summaries belong in the job; the
setup is in [docs/recipes.md](recipes.md#scheduled-digests). For email, a single block
uses its template's subject unless `email_subject` is set; several blocks have no common
subject, so set `email_subject` there, or the subject is "Notification".

## Parameters

`Herald.notifyDigest` takes the digest in `actionParams`; the CiviRules form has a field
for each. In a scheduled job they go into the JSON parameters shown in the
[recipe](recipes.md#scheduled-digests).

| Parameter | Default | Meaning |
|---|---|---|
| `blocks` | none | JSON array of blocks, see [block types](#block-types); without it the top-level parameters are the one block |
| `header` | none | Line above the blocks, inserted as HTML, e.g. `📊 <b>Daily report</b>` |
| `template` | the block type's default | Template of the single block; a block sets its own |
| `channel` | `telegram` | Channel key; the channel's own keys go alongside, e.g. `chat_id` |
| `period` | `yesterday` | `yesterday`, `today`, `last_7_days` or `last_30_days`; a block may set its own |

`Herald.notifyDigest` also takes `sendHour` outside `actionParams`: it sends only during
that hour (0–23, CiviCRM's timezone) and reports `skipped` otherwise, so an hourly job
sends once a day at a fixed time. A manual run leaves it out.

## Block types

Every block takes `type` and `period`; contribution and search blocks also take `title`
(plain text, escaped like every template variable) and `template`.

### Contribution blocks

| Parameter | Default | Meaning |
|---|---|---|
| `campaign_id` | none | Restricts every query to a campaign and adds its progress line |
| `financial_type_id` | none | Restricts every query to a financial type; with `group_by=line_item` the item's own type, except in the histogram |
| `source_pattern` | none | SQL `LIKE` pattern on the contribution source, e.g. `%Bank TX%` |
| `group_by` | source | `line_item`: break down by line-item label and unit price instead of source; totals and pending amounts then come from the items, so a mixed cart is split, and the counts are item quantities. Only the block template prints the items. The CiviRules form calls it "Break down by" |
| `show_pending` | `true` | Include the open pending amounts |
| `show_histogram` | `true` | Include the amount histogram |
| `bucket_count` | `8` | Target number of histogram buckets |
| `bucket_edges` | adaptive | Explicit bucket edges as a JSON array, e.g. `[0,100,1000,10000]` |

What a contribution block counts:

- Completed contributions count by `receive_date` inside the period, not by the date they
  became Completed. Test and template contributions never count.
- `yesterday` and `today` are calendar days; `last_7_days` and `last_30_days` are whole
  days ending at midnight before the run, so today is not included.
- Pending amounts are the whole open pool, whatever its age.
- The histogram covers the completed contributions of the period that match the filters,
  and ignores amounts of zero or less. It always shows whole contribution amounts, so
  with `group_by=line_item` its financial type filter applies to the contribution, not
  the item.
- The progress line takes the title and the goal (`goal_revenue`) from the Campaign record
  and counts every completed contribution of that campaign that matches the financial
  type and source filters, regardless of period. With `group_by=line_item` it counts the
  campaign's items instead, like the totals. A campaign without a goal shows no progress
  line.
- Amounts are added up as numbers, without currency conversion; mixed currencies give
  meaningless totals.

Without `bucket_edges` herald picks histogram edges that fit the amounts. Fixed edges suit
a campaign whose story is told in tiers, such as a major-donor campaign:
`"bucket_edges": [0, 100, 1000, 10000, 100000, 1000000]`. Amounts outside fixed edges land
in extra "<low" and "≥high" buckets.

### Search blocks

`search` names a SearchKit saved search (its machine name). herald runs the search's query
without permission checks, like its own contribution queries, so whoever may configure a
digest can report any search's rows into a channel. Group the search for counts, e.g.
memberships by type:

```json
{"type": "search", "search": "memberships_by_type", "title": "Members"}
```

The default template prints one line per row with the search's columns in order. The
search's displays play no part; only its query counts, so give the search a limit: every
row goes into the message, and only Telegram shortens an overlong one.

### Section blocks

Numbers no query answers, such as booked seats from a ticketing extension or queue depth,
come from the extension that owns them through `hook_civicrm_heraldDigestSections`:

```php
function myext_civicrm_heraldDigestSections(array $keys, array $context, array &$sections): void {
  if (in_array('myext.seats', $keys, TRUE)) {
    $sections['myext.seats'] = "🪑 <b>Seats</b>\n138 booked";
  }
}
```

The block `{"type": "section", "key": "myext.seats"}` inserts the answer. `$context` holds
the digest parameters under `params` and the block's period under `period`, so a listener
can match the digest's timeframe. Section bodies are inserted as HTML, so a listener
escapes its own data. An empty answer means nothing to report and leaves the block out;
a key no extension answers fails the digest, so a typo does not go unnoticed.

### Example

```json
[
  {"title": "Donations", "financial_type_id": 1, "campaign_id": 2},
  {"title": "Tickets", "financial_type_id": 4, "group_by": "line_item", "show_histogram": false},
  {"type": "search", "search": "memberships_by_type", "title": "Members"},
  {"type": "section", "key": "myext.seats"}
]
```

The CiviRules form reports an unknown type, a missing `search` or `key` and a saved search
that does not exist before saving. A scheduled job fails on the same mistakes when it
runs, with the reason in the job log.

## Source normalisation

Contribution sources are often technical: a payment processor's reference, a bank
import's transaction prefix. `herald_source_normalization` maps them to readable labels in
the digest breakdown and in the `contribution.source` of contribution notifications. It is empty
by default, which leaves sources untouched.

```bash
cv api4 Setting.set +v 'herald_source_normalization=[
  {"match": "Bank TX", "type": "contains", "label": "Bank transfer"},
  {"match": "Ueberweisung", "type": "prefix", "label": "Bank transfer"},
  {"match": "/^pi_[A-Za-z0-9]+$/", "type": "regex", "label": "Stripe"}
]'
```

Rules are tried in order and the first match wins. `type` is `contains`, `prefix` or
`regex`; a regex uses PHP `preg_match` syntax including delimiters. Malformed rules are
ignored, so a typo cannot break an alert. In the digest, sources that map to the same
label are added up into one line.

## Template variables

Contribution templates receive:

| Variable | Content |
|---|---|
| `period.label`, `period.start`, `period.end` | The period's name and bounds |
| `totals.amount`, `totals.count` | Completed contributions in the period; with `group_by=line_item` the item totals and quantities |
| `by_source` | List of `source`, `total`, `count` |
| `by_item` | With `group_by=line_item`: list of `label`, `unit_price`, `qty`, `total` |
| `pending.amount`, `pending.count` | Open pending contributions |
| `pending_items` | With `group_by=line_item`: the pending items, like `by_item` |
| `campaign.title`, `.goal`, `.total`, `.count`, `.percent` | Campaign progress with `campaign_id`; `goal` is `0` without a goal on the Campaign record; with `group_by=line_item` `count` is an item quantity |
| `histogram` | Preformatted histogram text, empty when disabled |
| `block_title` | The block's `title` |

Search templates receive `block_title`, `count` (number of rows) and `rows`, a list of
`cells` (the column values in order) and `values` (the same keyed by column, with
non-word characters as `_`: `contact_type:label` becomes `contact_type_label`, an alias
`COUNT(id) AS n` becomes `n`).

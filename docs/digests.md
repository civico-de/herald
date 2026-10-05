# Contribution digests

A digest summarises the contributions of a period: total, breakdown by source, open
pending amounts, campaign progress and an amount histogram.

## What it counts

- Completed contributions count by `receive_date` inside the period, not by the date they
  became Completed. Test and template contributions never count.
- `yesterday` and `today` are calendar days; `last_7_days` and `last_30_days` are whole
  days ending at midnight before the run, so today is not included.
- Pending amounts are the whole open pool, whatever its age.
- The histogram covers every completed contribution matching the filters, not only the
  period, and ignores amounts of zero or less.
- With `campaign_id` and `goal`, the progress line counts every completed contribution of
  that campaign, regardless of period, financial type or source. Without `campaign_id`,
  it uses the digest's own period totals.
- Amounts are added up as numbers, without currency conversion; mixed currencies give
  meaningless totals.

## Ways to send one

| Sender | Use for | Delivery |
|---|---|---|
| Scheduled job `Job.herald_contribution_digest` | Daily or weekly summaries | One channel, direct |
| CiviRules action **Herald: Send contribution digest** | A digest fired by a rule's trigger | All [delivery modes](delivery.md#delivery-modes) |
| `Herald.notifyDigest` (APIv4) | Scripts | All delivery modes |

CiviRules has no plain time-of-day trigger, so regular summaries belong in the job; the
setup is in [docs/recipes.md](recipes.md#scheduled-digests). The job alone offers
composed digests, extra sections, `send_hour` and `force`. The job does not use the
template's subject: for email, set `email_subject` on the job, or the subject is
"Notification".

## Parameters

| Parameter | Default | Meaning |
|---|---|---|
| `template` | required (single digest) | `herald_contribution_digest_daily`, `herald_contribution_digest_weekly` or your own workflow ([naming](reference.md#templates)) |
| `channel` | `telegram` | Channel key; the channel's own keys go alongside, e.g. `chat_id` |
| `period` | `yesterday` | `yesterday`, `today`, `last_7_days` or `last_30_days` |
| `campaign_id` | none | Restricts every query to a campaign |
| `financial_type_id` | none | Restricts every query to a financial type |
| `source_pattern` | none | SQL `LIKE` pattern on the contribution source, e.g. `%Bank TX%` |
| `group_by` | source | `line_item`: break down by line-item label and unit price instead of source; totals and pending amounts then come from the items, so a mixed cart is split, and the counts are item quantities. Only the block template prints the items. Not offered by the CiviRules form, so use it in the job or `Herald.notifyDigest` |
| `show_pending` | `true` | Include the open pending amounts |
| `show_histogram` | `true` | Include the amount histogram |
| `bucket_count` | `8` | Target number of histogram buckets |
| `bucket_edges` | adaptive | Explicit bucket edges as a JSON array, e.g. `[0,100,1000,10000]` |
| `goal` | none | Campaign goal; enables the progress line ([what it counts](#what-it-counts)) |
| `campaign_title` | none | Free-text campaign name for the progress line |

Job only:

| Parameter | Meaning |
|---|---|
| `blocks` | JSON array: a [composed digest](#composed-digests) instead of a single template |
| `header` | With `blocks`: line above the blocks, inserted as HTML, e.g. `📊 <b>Daily report</b>` |
| `sections` | With `blocks`: keys of [extra sections](#sections-from-other-extensions), comma-separated or a JSON array |
| `send_hour` | Send only during this hour (0–23, CiviCRM's timezone); run the job hourly |
| `force` | `1` ignores `send_hour`, for a manual run |

In the job's parameters field each parameter goes on its own line as `name=value`.

### Histogram buckets

Without `bucket_edges` herald picks edges that fit the amounts. Fixed edges suit a
campaign whose story is told in tiers, for example a major-donor campaign:

```text
template=herald_contribution_digest_daily
period=last_7_days
campaign_id=5
bucket_edges=[0,100,1000,10000,100000,1000000]
goal=500000
campaign_title=Major Donor Campaign 2026
```

Amounts outside fixed edges land in extra "<low" and "≥high" buckets.

## Composed digests

`blocks` turns the job into a multi-part message: each block runs its own query and is
rendered on its own, and the parts are joined into one message.

```text
header=📊 <b>Daily report</b>
period=yesterday
blocks=[{"title":"Donations","financial_type_id":1,"goal":30000,"campaign_id":2},{"title":"Tickets","financial_type_id":4,"group_by":"line_item","show_histogram":false}]
send_hour=8
```

A block takes the filters and display options from the parameter table plus `title` and
`template` (default `herald_contribution_digest_block`). It inherits the job's `period`
unless it sets its own. A block's `title` is plain text and is escaped like every template
variable.

## Sections from other extensions

A composed digest can only report what a contribution query answers. Numbers from other
entities, such as booked seats, membership counts or queue depth, come from the extension
that owns them through `hook_civicrm_heraldDigestSections`:

```php
function myext_civicrm_heraldDigestSections(array $keys, array $context, array &$sections): void {
  if (in_array('myext.seats', $keys, TRUE)) {
    $sections['myext.seats'] = "🪑 <b>Seats</b>\n138 booked";
  }
}
```

With `blocks` and `sections=myext.seats,myext.queue` on the job, herald appends the answers after the
blocks, in the order listed. `$context` holds the job parameters under `params` and the
period under `period`, so a listener can match the digest's timeframe. Section bodies are
inserted as HTML, so a listener escapes its own data. Keys nobody asked for and empty
bodies are dropped, so a listener may answer unconditionally.

## Source normalisation

Contribution sources are often technical: a payment processor's reference, a bank
import's transaction prefix. `herald_source_normalization` maps them to readable labels in
the digest breakdown and in the `contribution.source` of contribution alerts. It is empty
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

Digest templates receive:

| Variable | Content |
|---|---|
| `period.label`, `period.start`, `period.end` | The period's name and bounds |
| `totals.amount`, `totals.count` | Completed contributions in the period; with `group_by=line_item` the item totals and quantities |
| `by_source` | List of `source`, `total`, `count` |
| `by_item` | With `group_by=line_item`: list of `label`, `unit_price`, `qty`, `total` |
| `pending.amount`, `pending.count` | Open pending contributions |
| `pending_items` | With `group_by=line_item`: the pending items, like `by_item` |
| `campaign.title`, `.goal`, `.total`, `.count`, `.percent` | Campaign progress when `goal` is set |
| `histogram` | Preformatted histogram text, empty when disabled |
| `block_title` | Composed digests: the block's title |

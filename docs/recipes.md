# Recipes

End-to-end setups built from CiviRules rules and scheduled jobs. Everything here is
configured in the CiviCRM UI; no code changes are needed.

Rules live under Administer → CiviRules. The trigger and condition names below are
CiviRules' own; the `Herald: …` actions and conditions ship with herald. Each herald
action has a form, so you fill in fields rather than JSON. The JSON blocks show the
parameters the form stores, which is the same shape `cv` scripts and
[docs/reference.md](reference.md#civirules-actions) use.

Leaving the chat ID empty sends to `herald_telegram_default_chat_id`. Every recipe works
with any other channel: pick it in the form and put its keys into "Channel-specific
config" ([docs/channels.md](channels.md)).

## Donations

Four rules cover the life of a donation: paid immediately, announced as a bank transfer,
and received later.

| Rule | Trigger | Conditions | Action and template |
|---|---|---|---|
| Paid immediately | Contribution is added | Contribution Status is (not) one of: Completed | Send contribution alert, `herald_contribution_alert_completed` |
| Transfer announced | Contribution is added | Contribution Status is (not) one of: Pending; Contribution is (not) of Financial Type(s): Donation | Send contribution alert, `herald_contribution_alert_pending` |
| Transfer received | Contribution is changed | Herald: Previous Contribution status = Pending; Contribution Status is (not) one of: Completed | Send contribution alert, `herald_contribution_status_changed` |
| Daily summary | (scheduled job, see [Scheduled digests](#scheduled-digests)) | | `herald_contribution_digest_daily` |

The financial-type condition on "Transfer announced" matters when the site sells tickets:
a pending event order is also a pending contribution and would otherwise be announced as
a donation. Tickets get their own rules below.

"Transfer received" relies on herald's **Previous Contribution status** condition, which
reads the status before the edit from herald's own pre-edit snapshot, and from the
original data CiviRules stores when a rule runs delayed. Its form offers the statuses by
name.

Campaign progress: set `campaign_id` and `goal` on an alert action and the template gets
the campaign's running total, count and percentage.

```json
{
  "channel": "telegram",
  "template": "herald_contribution_alert_completed",
  "campaign_id": 2,
  "goal": 30000,
  "campaign_title": "Campaign 2026"
}
```

### Contributions created by a bank import

A bank import that creates contributions as Completed would fire "Paid immediately" for
every booked transaction. If its contributions carry a recognisable source, exclude them
with a second condition on that rule: **Field Value Comparison**, entity Contribution,
field `source`, operator "Does not contain string", value `Bank TX`. The same source
string can drive a dedicated rule with the reverse operator.

To show such sources under one readable label in alerts and digests, configure
[source normalisation](digests.md#source-normalisation).

## Ticket sales

| Rule | Trigger | Conditions | Template |
|---|---|---|---|
| Ticket paid online | Contribution is added | Financial Type: the ticket type; Status: Completed | `herald_contribution_alert_ticket` |
| Ticket ordered by transfer | Contribution is added | Financial Type: the ticket type; Status: Pending | `herald_contribution_alert_ticket_pending` |
| Ticket transfer received | Contribution is changed | Herald: Previous Contribution status = Pending; Financial Type: the ticket type; Status: Completed | `herald_contribution_alert_ticket_paid` |

All three use **Herald: Send contribution alert**. Sales come in bursts after an
announcement, so aggregate them:

```json
{
  "channel": "telegram",
  "template": "herald_contribution_alert_ticket",
  "aggregate_minutes": 5
}
```

Seven sales inside the window then arrive as one message headed
"📬 7 messages, total 385.00 € (collected over 5 min)", followed by the individual lines
([docs/delivery.md](delivery.md#aggregation)).

## Event lifecycle

Useful when events are maintained by a sync rather than by hand, so a new or vanished
event would otherwise go unnoticed. All rules use **Herald: Send notification (generic)**.

| Rule | Trigger | Conditions | Template |
|---|---|---|---|
| Event created | Event is added | none | `herald_event_created_alert` |
| Event deactivated | Event is changed | Herald: Event was deactivated (is_active 1 → 0) | `herald_event_deactivated_alert` |
| Event deleted | Event is deleted | none | `herald_event_deleted_alert` |
| New registration | Event Participant is added | Participant status is (not) one of: Registered | `herald_event_registration_alert` |
| Registration cancelled | Event Participant is changed | Compare Old Participant Status to New Participant Status: from Registered or Pending from pay later, to Cancelled | `herald_event_cancellation_alert` |

A sync usually unpublishes an event by setting `is_active = 0` instead of deleting it, so
"Event deactivated" is the rule that catches a removed event. Do not build it from a
plain Field Value Comparison on `is_active = 0`: a partial edit that does not write
`is_active` reads as empty, empty compares equal to `0`, and every unrelated edit raises
a false alarm. herald's condition compares the pre-edit value with the stored row and
fires only on a real 1 → 0 change.

Restricting the previous status of the cancellation rule keeps abandoned checkouts
(incomplete transaction → Cancelled) silent. Limiting "New registration" to Registered
keeps waitlist and pending entries out. A registration paid online starts as "Pending
from incomplete transaction" and becomes Registered once the payment goes through; catch
it with a second rule on **Event Participant is changed** with **Compare Old Participant
Status to New Participant Status** from "Pending from incomplete transaction" to
Registered, using the same template.

Registration, cancellation and the membership template below print the contact as a
masked name such as `Ja** Do*`; the newsletter template adds a masked email. That is
enough to recognise a signup or spot spam in a group chat without exposing the
identity.

## Memberships and newsletter signups

Both use **Herald: Send notification (generic)**.

| Rule | Trigger | Conditions | Template |
|---|---|---|---|
| New membership | Membership is added | optional: membership type | `herald_membership_signup_alert` |
| Newsletter signup | Contact is added to Group | Group is: the newsletter groups | `herald_group_subscription_alert` |

Signups arrive in bursts after a mailing or an import, so `aggregate_minutes` of 5–15
keeps the chat readable.

## Scheduled digests

CiviRules has no plain time-of-day trigger: its scheduled triggers fire once per matching
contact or record. A digest that goes out once a day is therefore a scheduled job, not a
rule.

Administer → System Settings → Scheduled Jobs → Add:

| Field | Value |
|---|---|
| API entity | `Job` |
| API action | `herald_contribution_digest` |
| Run frequency | Hourly |
| Parameters | see below |

```text
template=herald_contribution_digest_daily
period=yesterday
send_hour=8
campaign_id=2
goal=30000
campaign_title=Campaign 2026
```

`send_hour` makes the hourly job send only during that hour (CiviCRM's timezone). A Daily
frequency counts a day from the previous run, so its send time drifts; Hourly with
`send_hour` pins the hour. For a weekly digest use `herald_contribution_digest_weekly`,
`period=last_7_days` and the Weekly frequency. `force=1` sends immediately regardless of
`send_hour`, for a manual run.

All digest parameters, composed multi-block digests and sections contributed by other
extensions are in [docs/digests.md](digests.md).

## Per-person notifications

When several staff members want different notifications on their own channels, use
subscriber mode instead of one rule per person: each contact picks a channel and the
kinds of notifications they want, and one rule fans out to all subscribers. Setup and the
self-service profile are in [docs/delivery.md](delivery.md#subscribers).

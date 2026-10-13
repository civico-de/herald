---
title: Recipes
order: 10
---

# Recipes

These are complete setups for common alerts. They are built from CiviRules rules and scheduled jobs in the CiviCRM user interface, without any code.

Rules live under Administer, CiviRules, Manage Rules. The trigger and condition names below come from CiviRules. The actions and conditions whose names start with "Herald:" come with herald. Each herald action has a form, and the templates are listed there by title.

Each recipe sends to Telegram. Leave the chat ID empty to use the default chat from the settings. Every recipe works with any other channel too: choose it in the form and put its keys into "Channel-specific config", see [Channels](channels.md).

## Donations

Four rules cover the life of a donation: paid at once, announced as a bank transfer, and received later.

| Rule | Trigger | Conditions | Template |
|---|---|---|---|
| Paid at once | Contribution is added | Contribution Status is (not) one of: Completed | Herald: New completed contribution |
| Transfer announced | Contribution is added | Contribution Status is (not) one of: Pending, and Contribution is (not) of Financial Type(s): Donation | Herald: New pending contribution |
| Transfer received | Contribution is changed | Herald: Field transition, see below, and Contribution Status is (not) one of: Completed | Herald: Contribution status changed |
| Daily summary | A scheduled job, see [Scheduled digests](#scheduled-digests) | | Herald: Daily contribution digest |

All of them use the action "Herald: Send notification (generic)".

The financial type condition on "Transfer announced" matters when the site sells tickets. A pending event order is also a pending contribution and would otherwise be announced as a donation. Tickets get their own rules below.

"Transfer received" uses herald's condition "Herald: Field transition" with these values:

| Field | Value |
|---|---|
| Entity | Contribution |
| Field | contribution_status_id |
| Before | = 2, the ID of Pending on a standard install |
| After | any value |

The condition reads the status before the edit from the data CiviCRM had before the change. It works with delayed actions too.

Campaign progress needs no setup. A contribution that belongs to a campaign brings the campaign's title, total and count. When the campaign has a revenue goal, it also brings the goal and the percentage reached. See [Contribution variables](reference.md#contribution-variables).

### Contributions from a bank import

A bank import that creates Completed contributions would fire "Paid at once" for every booked transaction. If the imported contributions carry a recognisable source, exclude them with a second condition on that rule:

| Field | Value |
|---|---|
| Condition | Field Value Comparison |
| Entity | Contribution |
| Field | source |
| Operator | Does not contain string (case insensitive) |
| Value | Bank TX |

The same source can drive a rule of its own with the opposite operator.

To show such sources under one readable label in alerts and digests, set up [Source normalisation](digests.md#source-normalisation).

## Ticket sales

| Rule | Trigger | Conditions | Template |
|---|---|---|---|
| Ticket paid online | Contribution is added | Financial type is the ticket type, status is Completed | Herald: New ticket/event contribution |
| Ticket ordered by transfer | Contribution is added | Financial type is the ticket type, status is Pending | Herald: Ticket ordered, bank transfer announced |
| Ticket transfer received | Contribution is changed | Herald: Field transition on contribution_status_id with Before = 2, financial type is the ticket type, status is Completed | Herald: Ticket bank transfer received |

All three use "Herald: Send notification (generic)". Sales come in bursts after an announcement, so set "Aggregate window in minutes" to 5. Seven sales inside the window then arrive as one message headed "📬 7 messages, total 385.00 € (collected over 5 min)", followed by the individual sales. See [Aggregation](delivery.md#aggregation).

## Event lifecycle

These rules help when events are maintained by a sync rather than by hand, so a new or a vanished event would otherwise go unnoticed. All of them use "Herald: Send notification (generic)".

| Rule | Trigger | Conditions | Template |
|---|---|---|---|
| Event created | Event is added | none | Herald: New event created |
| Event deactivated | Event is changed | Herald: Field transition, entity Event, field is_active, Before = 1, After = 0 | Herald: Event deactivated |
| Event deleted | Event is deleted | none | Herald: Event deleted |
| New registration | Event Participant is added | Participant status is (not) one of: Registered | Herald: New event registration |
| Registration cancelled | Event Participant is changed | Compare Old Participant Status to New Participant Status: from Registered or Pending from pay later, to Cancelled | Herald: Event registration cancelled |

A sync usually unpublishes an event by deactivating it rather than deleting it, so "Event deactivated" is the rule that catches a removed event. Build it with herald's Field transition, not with a Field Value Comparison on is_active. A Field Value Comparison sees only the value after the edit, so it also fires on every other edit of an event that is already inactive. The Field transition fires only when the value really changes from 1 to 0.

Abandoned checkouts go from an incomplete transaction to Cancelled. Limiting the old status of the cancellation rule keeps them quiet. Limiting "New registration" to Registered keeps waitlist and pending entries out.

A registration paid online starts as "Pending from incomplete transaction" and becomes Registered once the payment goes through. To catch it, add a second rule on "Event Participant is changed" with "Compare Old Participant Status to New Participant Status" from "Pending from incomplete transaction" to Registered, and use the same template.

The registration, cancellation and membership templates show the contact as a masked name such as "Ja** Do*". The newsletter template adds a masked email address. That is enough to recognise a signup or spot spam in a group chat without exposing who it is.

## Memberships and newsletter signups

Both use "Herald: Send notification (generic)".

| Rule | Trigger | Conditions | Template |
|---|---|---|---|
| New membership | Membership is added | Optionally the membership type | Herald: New membership signup |
| Newsletter signup | Contact is added to Group | Group is: the newsletter groups | Herald: New group/newsletter subscription |

Signups arrive in bursts after a mailing or an import. An aggregation window of 5 to 15 minutes keeps the chat readable.

## Scheduled digests

A digest that goes out once a day is a scheduled job, not a rule. The scheduled triggers of CiviRules fire once for each matching contact or record, and CiviRules has no plain time-of-day trigger.

Add the job under Administer, System Settings, Scheduled Jobs:

| Field | Value |
|---|---|
| API entity | Herald |
| API action | notifyDigest |
| Run frequency | Hourly |
| Parameters | see below |

```json
{
  "version": 4,
  "checkPermissions": false,
  "sendHour": 8,
  "actionParams": {
    "template": "herald_contribution_digest_daily",
    "period": "yesterday",
    "campaign_id": 2,
    "chat_id": "-1001234567890"
  }
}
```

- `"version": 4` runs the job through APIv4.
- `"checkPermissions": false` lets it run without a logged-in user, as cron does.
- `sendHour` makes the hourly job send only during that hour, in CiviCRM's timezone.

For a weekly digest, use the template `herald_contribution_digest_weekly` with `"period": "last_7_days"`. To run a digest by hand, use "Execute now" on a copy of the job without `sendHour`, or call `Herald.notifyDigest` directly.

CiviCRM runs scheduled jobs only in the Production environment. On a test site, add `"runInNonProductionEnvironment": true` next to `"version": 4`.

All digest settings and the block types are described in [Digests](digests.md).

### Choosing the run frequency

CiviCRM schedules an Hourly job one hour after the start of its previous run, and a Daily job one day after it. The job then runs at the first cron run after that moment. So the run time slowly moves later, by up to one cron interval at a time.

A Daily job therefore drifts through the day. An Hourly job with `sendHour` keeps the hour on most days. When the drift carries it across a full hour, though, that hour gets no run, and on that day the digest is not sent. With cron every 15 minutes, a run at 7:45 followed by one at 9:00 skips 8 o'clock.

If the digest must go out every day, run it from the server's crontab instead of a CiviCRM scheduled job, at a fixed time and without `sendHour`:

```
0 8 * * * cd /var/www/example.org && cv api4 Herald.notifyDigest '{"checkPermissions": false, "actionParams": {"template": "herald_contribution_digest_daily", "period": "yesterday"}}'
```

This call is not limited to the Production environment.

## Notifications for each person

When several staff members want different notifications on their own channels, use subscriber mode instead of one rule per person. Each contact chooses a channel and the kinds of notifications they want, and one rule reaches all subscribers. The setup and the self-service profile are described in [Subscribers](delivery.md#subscribers).

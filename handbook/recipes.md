---
title: Recipes
order: 10
---

# Recipes

These are complete setups for common alerts. They are built from CiviRules rules and scheduled jobs in the CiviCRM user interface, without any code.

Rules live under Administer, CiviRules, Manage Rules. The trigger and condition names below come from CiviRules. The actions and conditions whose names start with "Herald:" come with herald. Each herald action has a form, and the templates are listed there by title.

Each recipe sends to Telegram. Leave the chat ID empty to use the default chat from the settings. Every recipe works with any other channel too: choose it in the form and put its keys into "Channel-specific config", see [Channels](channels.md).

## Donations

Four rules cover the life of a donation: paid at once, announced as a bank transfer, received later, and a summary each morning.

| Rule | Trigger | Conditions | Template |
|---|---|---|---|
| Paid at once | Contribution is added | Contribution Status is (not) one of: Completed | Herald: New completed contribution |
| Transfer announced | Contribution is added | Contribution Status is (not) one of: Pending, and Contribution is (not) of Financial Type(s): Donation | Herald: New pending contribution |
| Transfer received | Contribution is changed | Herald: Field transition, see below, and Contribution Status is (not) one of: Completed | Herald: Contribution status changed |
| Daily summary | Herald: Schedule, see [Scheduled digests](#scheduled-digests) | | Herald: Daily contribution digest, in the digest's contribution block |

The first three use the action "Herald: Send notification (generic)", the daily summary uses "Herald: Send digest".

The financial type condition on "Transfer announced" matters when the site sells tickets. A pending event order is also a pending contribution and would otherwise be announced as a donation. Tickets get their own rules below.

"Transfer received" uses herald's condition "Herald: Field transition" with these values:

| Field | Value |
|---|---|
| Entity | Contribution |
| Field | contribution_status_id:name |
| Before | = Pending |
| After | any value |

The condition reads the status before the edit from the copy CiviRules keeps of the record, so it works with delayed actions too. A custom field needs one more setting there, see [Field transition](reference.md#field-transition).

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
| Ticket transfer received | Contribution is changed | Herald: Field transition on contribution_status_id:name with Before = Pending, financial type is the ticket type, status is Completed | Herald: Ticket bank transfer received |

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

A sync usually takes an event down by deactivating it rather than deleting it, so "Event deactivated" is the rule that catches a removed event. Build it with herald's Field transition, not with a Field Value Comparison on is_active. A Field Value Comparison sees only the value after the edit, so it also fires on every other edit of an event that is already inactive. The Field transition fires only when the value really changes from 1 to 0.

Abandoned checkouts go from an incomplete transaction to Cancelled. Limiting the old status of the cancellation rule keeps them from sending an alert. Limiting "New registration" to Registered keeps waitlist and pending entries out.

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

A digest that goes out at a fixed time is a CiviRules rule with the trigger "Herald: Schedule" and the action "Herald: Send digest". The trigger fires once at the chosen hour and carries no contact, so the rule sends one digest, not one per record.

A weekly contribution digest every Monday at 8:00:

1. Under Administer » Herald » Digests, add a digest with the title "Weekly contributions", the name `weekly_contributions`, the period "Last 7 days" and one contribution block with the template "Herald: Weekly contribution digest".
2. Create a CiviRules rule and choose the trigger "Herald: Schedule".
3. In the trigger's form, choose Monday under Weekdays and 08:00 under Time. Leave Weekdays empty to send every day. The time is in CiviCRM's timezone.
4. Add the action "Herald: Send digest", pick the digest, and set the channel and, for Telegram, the chat ID.

The rule list shows the schedule as "Every Monday at 08:00" and the action as `Sends the digest "Weekly contributions".` For a daily digest, leave Weekdays empty and give the digest the period "Yesterday" and the template "Herald: Daily contribution digest".

The trigger is checked each time CiviRules' scheduled job runs, so the digest goes out with the first cron run after the full hour. Whether a slot has been sent is read from the rule's own log:

- If cron misses the hour, the next run catches up. When cron runs every 15 minutes but skips everything between 7:45 and 9:00, the 8 o'clock digest goes out at 9:00.
- After a longer outage, only the latest slot is sent. Three missed Mondays give one digest, not three.
- A new rule, or one whose schedule was just saved, never sends for a slot from before that moment.
- A run counts even when the send fails, for example because the channel is not configured. The error is logged, and the next digest goes out at the next slot.

CiviCRM runs scheduled jobs, the CiviRules job included, only in the Production environment.

How to set up a digest and its blocks is described in [Digests](digests.md).

### Without a rule

Scripts and the server's crontab can call `Herald.notifyDigest` directly, at a fixed time:

```
0 8 * * 1 cd /var/www/example.org && cv api4 Herald.notifyDigest '{"checkPermissions": false, "digest": "weekly_contributions", "actionParams": {"chat_id": "-1001234567890"}}'
```

This call is not limited to the Production environment.

A CiviCRM scheduled job of `Herald.notifyDigest`, set to Hourly with `"sendHour": 8` in its parameters, also works, but it cannot pick weekdays and it can skip a day: CiviCRM moves each job's next run later by up to one cron interval, and when that drift pushes the job past the whole hour, no run falls inside it that day.

## Notifications for each person

When several staff members want different notifications on their own channels, use subscriber mode instead of one rule per person. Each contact chooses a channel and the kinds of notifications they want, and one rule reaches all subscribers. The setup and the self-service profile are described in [Subscribers](delivery.md#subscribers).

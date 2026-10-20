---
title: Recipes
order: 10
---

# Recipes

These are complete setups for common alerts. They are built from CiviRules rules and scheduled jobs in the CiviCRM user interface, without any code.

Rules live under Administer » CiviRules » Manage Rules. The trigger and condition names below come from CiviRules. The actions and conditions whose names start with "Herald:" come with herald. Each herald action has a form, and the templates are listed there by title.

Each recipe sends to Telegram. Leave the chat ID empty to use the default chat from the settings. Every recipe works with any other channel too: choose it in the form and put its keys into "Channel-specific config", see [Channels](channels.md).

## Donations

Three rules follow a donation (paid at once, announced as a bank transfer, received later), and a fourth sends a summary each morning.

| Rule | Trigger | Conditions | Template |
|---|---|---|---|
| Paid at once | Contribution is added | Contribution Status is (not) one of: Completed | Herald: New completed contribution |
| Transfer announced | Contribution is added | Contribution Status is (not) one of: Pending, and Contribution is (not) of Financial Type(s): Donation | Herald: New pending contribution |
| Transfer received | Contribution is changed | Herald: Field transition, see below, and Contribution Status is (not) one of: Completed | Herald: Contribution status changed |
| Daily summary | Herald: Schedule, see [Scheduled digests](digests.md#scheduled-digests) | | A digest with the display "Digest: contributions yesterday" |

The first three use the action "Herald: Send notification (generic)"; the daily summary uses "Herald: Send digest".

The financial type condition on "Transfer announced" matters when the site sells tickets. A pending event order is also a pending contribution and would otherwise be announced as a donation. Tickets get their own rules below.

"Transfer received" uses herald's condition "Herald: Field transition" with these values:

| Field | Value |
|---|---|
| Entity | Contribution |
| Field | contribution_status_id:name |
| Before | = Pending |
| After | any value |

The condition reads the status before the edit from the copy CiviRules keeps of the record, so it works with delayed actions too. With a custom field, a delayed action needs one more setting, see [Field transition](reference.md#field-transition).

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

The same source can drive a rule of its own with the opposite operator. To count these contributions apart in a digest, give the source a [cleaned field](digests.md#cleaned-fields).

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

Abandoned checkouts go from an incomplete transaction to Cancelled. The cancellation rule accepts only Registered or Pending from pay later as the old status, so they trigger no alert. Limiting "New registration" to Registered keeps waitlist and pending entries out.

A registration paid online starts as "Pending from incomplete transaction" and becomes Registered once the payment goes through. To catch it, add a second rule on "Event Participant is changed" with "Compare Old Participant Status to New Participant Status" from "Pending from incomplete transaction" to Registered, and use the same template.

## Memberships and newsletter signups

Both use "Herald: Send notification (generic)".

| Rule | Trigger | Conditions | Template |
|---|---|---|---|
| New membership | Membership is added | Optionally the membership type | Herald: New membership signup |
| Newsletter signup | Contact is added to Group | Group is: the newsletter groups | Herald: New group/newsletter subscription |

The registration, cancellation, membership and newsletter templates show the contact as a masked name such as "Ja** Do*", and the newsletter template adds a masked email address, see [Trigger variables](reference.md#trigger-variables). That is enough to recognise a signup or spot spam in a group chat without revealing who it is.

Signups arrive in bursts after a mailing or an import. An aggregation window of 5 to 15 minutes keeps the chat readable.

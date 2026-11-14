---
title: herald
---

# herald

herald is a CiviCRM extension that sends notifications and digests. A CiviRules rule, a scheduled job, another extension or a script hands herald a message or a template. herald renders it and delivers it to a chat, a phone, an inbox or a CiviCRM activity.

It is meant for the people who run a CiviCRM site and want to know what happens in it: a donation came in, a bank transfer arrived, an event filled up, a scheduled job broke. They set up the alerts in the CiviCRM user interface. Extension developers can use herald as a delivery layer and as a place to report health checks.

## What it does

The simplest case is an email, to one address or to every member of a CiviCRM group. It works right after the install: herald sends through CiviCRM's outbound mailer. herald can also post to a chat or a phone, or assign a CiviCRM activity, see [Channels](channels.md).

Alerts come from CiviCRM message templates, so admins can edit the wording under Mailings, Message Templates. herald ships templates for contributions, ticket sales, events, memberships, group signups and health checks, in English with a German translation. A digest has its own subject and text, see below.

With CiviRules installed, herald adds four building blocks to it:

- the action "Herald: Send notification (generic)", which renders a template with the data of the trigger
- the action "Herald: Send digest", which sends a summary
- the trigger "Herald: Schedule", which fires once at a set hour on chosen weekdays
- the condition "Herald: Field transition", which compares a field's value before and after an edit, such as an event being deactivated. CiviRules ships such conditions only for a few fields, such as statuses.

A notification can go to one recipient, to every contact who subscribed to its topic, to the members of a group, or to a list of email addresses. Each contact can choose their own channel. Alerts that come in bursts can be collected and sent as one message per time window. See [Delivery](delivery.md).

Scripts and other extensions can publish to a named topic such as ops. A topic bundles messages of one kind, and its entry decides which channel and recipients they reach, so the publisher never needs to know a chat ID or a URL.

A digest is a subject and a text filled from SearchKit displays, such as yesterday's contributions, and from numbers another extension provides. You set them up under Administer » Herald » Digests, and they usually go out daily or weekly, from a rule on the trigger "Herald: Schedule". See [Digests](digests.md).

The check monitor reads the checks on CiviCRM's status page, those of core and of every extension. It reports when a check starts failing and when it recovers, and reminds you about checks that keep failing. See [Check monitor](monitor.md).

## Requirements

herald needs SearchKit and Afform, which ship with CiviCRM; the extensions screen lists Afform as "Form Core". FormBuilder is not needed. CiviRules is optional; if you use it, herald needs version 3.36 or later, and the system status reports an older one. herald registers its rule building blocks whether you install CiviRules before or after herald. Without CiviRules, the API, scheduled jobs, topics and the check monitor still work. The supported CiviCRM and PHP versions are listed in the extension's `info.xml`.

## Install

Download the release archive from the [releases page](https://github.com/civico-de/herald/releases), unpack it into your CiviCRM extensions directory and enable it:

```bash
cv ext:enable herald
```

A git checkout has no `vendor/` directory. Run `composer install --no-dev` in it before you enable the extension. Without `vendor/`, every page of the site fails once herald is enabled.

## First steps

The install creates the channel `email`. Send a test message from Administer » Herald » Test message: choose "A channel", then `email`, enter your address as recipient address and send. Or with cv:

```bash
cv api4 Herald.send channel=email subject='Test' message='<b>herald works</b>' \
  'config={"email_to":"you@example.org"}'
```

Now set up a rule that reports every completed contribution:

1. Go to Administer » CiviRules » Manage Rules, add a rule and choose the trigger "Contribution is added".
2. Add the condition "Contribution Status is (not) one of" with Completed.
3. Add the action "Herald: Send notification (generic)". Choose the template "Herald: New completed contribution" and the channel `email`, and enter the recipient address.

To reach a team instead of one address, set the delivery mode of the action to "Group" and choose a CiviCRM group. Every member gets the message at their primary email address, unless they chose another channel, see [Delivery](delivery.md#delivery-modes).

For Telegram, ntfy, Pushover, a webhook to Slack, Discord or Mattermost, or CiviCRM activities, add a channel under Administer » Herald » Channels. [Channels](channels.md) lists the fields of each type.

The administration page groups its screens: setting up, testing and operating, and advanced ones whose defaults suit most sites.

![The Herald administration page](images/admin.png)

## Uninstall

Uninstalling herald removes its CiviRules building blocks, and CiviRules removes them from every rule that uses them. A rule that lost a herald condition would then fire more often than before, so herald first switches off every active rule that uses one of its conditions or actions. A rule that runs on a herald trigger, such as a digest schedule, cannot work without it, so herald deletes that rule. The CiviCRM log lists the IDs of the rules switched off and the IDs and names of the rules deleted. Uninstalling also drops herald's tables, and with them the digests and every alert still waiting in the buffer, parked ones included.

## Where to go next

- [Recipes](recipes.md) walks through complete setups for donations, ticket sales, events and signups.
- [Channels](channels.md) covers configuring channels and the fields, options and limits of each type.
- [Delivery](delivery.md) explains recipients, subscriptions, topics, aggregation and what happens when a delivery fails.
- [Digests](digests.md) describes the text, sources, placeholders, the shipped searches, cleaned fields and scheduled sending.
- [Check monitor](monitor.md) shows how to contribute a health check and how to tune the alerts.
- [Reference](reference.md) lists the CiviRules actions, triggers and conditions, the API, the scheduled jobs, the templates, the settings and the hooks.
- [Deployment](deploy.md) explains how to update herald without breaking the writes its rules run in.

---
title: herald
---

# herald

herald is a CiviCRM extension that sends notifications and digests. A CiviRules rule, a scheduled job, another extension or a script hands herald a message or a template. herald renders it and delivers it to a chat, a phone, an inbox or a CiviCRM activity.

It is meant for the people who run a CiviCRM site and want to know what happens in it: a donation came in, a bank transfer arrived, an event filled up, a scheduled job broke. They set up the alerts in the CiviCRM user interface. Extension developers can use herald as a delivery layer and as a place to report health checks.

## What it does

herald delivers through six channels: Telegram, ntfy, Pushover, a generic webhook (for Slack, Discord, Mattermost or your own endpoint), email through CiviCRM's outbound mailer, and assigned CiviCRM activities. New channels are added in herald's code. See [Channels](channels.md).

Messages come from CiviCRM message templates, so admins can edit the wording under Mailings, Message Templates. herald ships templates for contributions, ticket sales, events, memberships, group signups, digests and health checks. The templates are in English, and a German translation is included.

With CiviRules installed, herald adds four building blocks to it:

- the action "Herald: Send notification (generic)", which renders a template with the data of the trigger
- the action "Herald: Send digest", which sends a summary
- the trigger "Herald: Schedule", which fires once at a set hour on chosen weekdays
- the condition "Herald: Field transition", which compares a field's value before and after an edit, such as a contribution moving from Pending to Completed

A notification can go to one recipient, to every contact who subscribed to that kind of notification, to the members of a group, or to a list of email addresses. Each contact can choose their own channel. Alerts that come in bursts can be collected and sent as one message per time window. See [Delivery](delivery.md).

Scripts and other extensions can publish to a named topic such as ops. A setting decides which channel and recipient each topic reaches, so the publisher never needs to know a chat ID or a URL.

A digest sums up contributions for a period, lists the rows of a SearchKit display, or includes numbers another extension provides. You set them up under Administer » Herald » Digests, and they usually go out daily or weekly, from a rule on the trigger "Herald: Schedule". See [Digests](digests.md).

The check monitor runs health checks that extensions contribute. It reports when a check starts failing and when it recovers, and reminds you about checks that keep failing. A healthy site stays quiet. See [Check monitor](monitor.md).

## Requirements

herald needs SearchKit and Form Core, which ship with CiviCRM. CiviRules is optional. herald registers its rule building blocks whether you install CiviRules before or after herald. Without CiviRules, the API, scheduled jobs, topics and the check monitor still work. The supported CiviCRM and PHP versions are listed in the extension's `info.xml`.

## Install

Download the release archive from the [releases page](https://github.com/civico-de/herald/releases), unpack it into your CiviCRM extensions directory and enable it:

```bash
cv ext:enable herald
```

A git checkout has no `vendor/` directory. Run `composer install --no-dev` in it before you enable the extension. Without `vendor/`, every page of the site fails once herald is enabled.

## First steps

Create a Telegram bot with @BotFather, add it to a chat and note the chat ID. Then store both on Administer » Herald » Settings, or with cv:

```bash
cv setting:set herald_telegram_bot_token=<bot-token>
cv setting:set herald_telegram_default_chat_id=<chat-id>
```

Send a test message from Administer » Herald » Test message: choose "A channel", then Telegram, and send. Or with cv:

```bash
cv api4 Herald.testTelegram message='<b>herald works</b>'
```

Now set up a rule that reports every completed contribution:

1. Go to Administer, CiviRules, Manage Rules, add a rule and choose the trigger "Contribution is added".
2. Add the condition "Contribution Status is (not) one of" with Completed.
3. Add the action "Herald: Send notification (generic)". Choose the template "Herald: New completed contribution" and the channel Telegram. Leave the chat ID empty to use the default.

The other channels are configured the same way. [Channels](channels.md) lists their settings.

## Uninstall

Uninstalling herald removes its CiviRules building blocks, and CiviRules removes them from every rule that uses them. A rule that lost a herald condition would then fire more often than before, so herald first switches off every active rule that uses one of its triggers, conditions or actions. The CiviCRM log lists the IDs of those rules. Uninstalling also drops herald's tables, and with them the digests and every alert still waiting in the buffer, parked ones included.

## Where to go next

- [Recipes](recipes.md) walks through complete setups for donations, ticket sales, events, signups and scheduled digests.
- [Channels](channels.md) covers each channel's settings, options and limits.
- [Delivery](delivery.md) explains recipients, subscriptions, topics, aggregation and what happens when a delivery fails.
- [Digests](digests.md) describes digest blocks, their filters and their template variables.
- [Check monitor](monitor.md) shows how to contribute a health check and how to tune the alerts.
- [Reference](reference.md) lists the CiviRules actions, triggers and conditions, the API, the scheduled jobs, the templates, the settings and the hooks.
- [Deployment](deploy.md) explains how to update herald without breaking the writes its rules run in.

# herald

herald is a CiviCRM extension that sends notifications and digests. CiviRules rules, scheduled jobs, other extensions and scripts hand it a message or a template. herald renders it and delivers it to Telegram, a webhook such as Slack or Discord, ntfy, Pushover, email or an assigned CiviCRM activity.

It ships templates for contributions, ticket sales, events, memberships and group signups. It adds CiviRules actions to send notifications and digests, a trigger that fires at a set hour on chosen weekdays, and a condition that compares a field before and after an edit. Notifications can go to one recipient, to subscribed contacts on their own channels, to a group or to a list of addresses, and bursts can be collected into one message. A check monitor reports when the health checks of your extensions start failing and when they recover.

Documentation: https://civico-de.github.io/herald/

## Requirements

- SearchKit, which ships with CiviCRM
- CiviRules, optional: it provides the rule actions, the schedule trigger and the field condition. herald registers them whether CiviRules is installed before or after it. Without CiviRules, the API, scheduled jobs, topics and the check monitor still work.

The supported CiviCRM and PHP versions are listed in `info.xml`.

## Install

Download the archive from the [releases page](https://github.com/civico-de/herald/releases), unpack it into your extensions directory and enable it:

```bash
cv ext:enable herald
```

A git checkout has no `vendor/` directory. Run `composer install --no-dev` in it first. Without it, every request fails once herald is enabled.

## Example

Store a Telegram bot token and a chat, then send a test message:

```bash
cv setting:set herald_telegram_bot_token=<bot-token>
cv setting:set herald_telegram_default_chat_id=<chat-id>
cv api4 Herald.testTelegram message='<b>herald works</b>'
```

To get a message for every completed contribution, create a CiviRules rule:

1. Trigger "Contribution is added".
2. Condition "Contribution Status is (not) one of" with Completed.
3. Action "Herald: Send notification (generic)" with the template "Herald: New completed contribution" and the channel Telegram.

Scripts and other extensions can publish to a named topic, and the setting `herald_topics` decides where it goes:

```bash
cv api4 Herald.publish topic=ops message='Import finished'
```

## Documentation

- [Getting started](https://civico-de.github.io/herald/)
- [Recipes](https://civico-de.github.io/herald/recipes/): donations, ticket sales, events, signups and scheduled digests
- [Channels](https://civico-de.github.io/herald/channels/): settings, options and limits of each channel
- [Delivery](https://civico-de.github.io/herald/delivery/): recipients, subscriptions, topics, aggregation and parked alerts
- [Digests](https://civico-de.github.io/herald/digests/): contribution, saved search and section blocks
- [Check monitor](https://civico-de.github.io/herald/monitor/): contributing health checks and tuning the alerts
- [Reference](https://civico-de.github.io/herald/reference/): CiviRules actions and conditions, API, jobs, templates, settings and hooks
- [Deployment](https://civico-de.github.io/herald/deploy/): updating herald without breaking the writes its rules run in

## Development

The dev stack and every CI gate run in the [civikitchen](https://github.com/civico-de/civikitchen) Docker image. herald needs its `vendor/` directory first, so run `composer install` (PHP 8.2 or later), then:

```bash
cd .docker
docker compose up -d     # CiviCRM on http://localhost:8080, admin/admin
docker compose exec -w /var/www/html/ext/herald app ck ci        # every CI gate
docker compose exec -w /var/www/html/ext/herald app ckphpunit    # the test suite alone
```

With a civikitchen checkout next to this repository, `../civikitchen/scaffold/ckup` does the `composer install` in the image, starts the stack on free host ports and prints its URL. `../civikitchen/scaffold/ckx ck ci` runs a command in it.

The first boot installs CiviCRM, CiviRules and herald. The tests install themselves into a separate test database, so the dev site stays untouched. CI runs the same gates on the same image. The coverage floor is `coverage.minimum` in `civikitchen.yaml`.

## License

AGPL-3.0-or-later. © civico GmbH. See [LICENSE.txt](LICENSE.txt).

---
title: Check monitor
order: 50
routes:
  - civicrm/admin/herald
---

# Check monitor

The check monitor tells you when something on the site breaks and when it works again. Extensions contribute health checks, and herald runs them on every cron run.

herald reports changes, not states. A check that starts failing sends one alert, and its recovery sends another. A check that keeps failing gets a reminder once a day. A healthy site stays quiet.

The scheduled job "Herald: Run check monitor" runs the checks. Each change goes out with the template "Herald: Monitor check transition" to the topic named in `herald_monitor_topic`, which is ops by default. Route that topic in `herald_topics` to decide where the alerts go, see [Topics](delivery.md#topics). A minimal route:

```bash
cv api4 Setting.set +v 'herald_topics={"ops": {"channel": "telegram", "chat_id": "-100…"}}'
```

The alerts look like this:

- 🔴 Check myext.queue failed: 1500 queue items waiting
- ⏰ Check myext.queue still failing: 1500 queue items waiting
- 🟢 Check myext.queue ok again

The text after the colon is the detail the check returns.

## Contributing a check

An extension adds checks through the hook `hook_civicrm_heraldChecks`. Each check is a function that returns a status and an optional detail:

```php
function myext_civicrm_heraldChecks(array &$checks): void {
  $checks['myext.queue'] = function (): array {
    $waiting = (int) CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_queue_item');
    return $waiting > 1000
      ? ['status' => 'fail', 'detail' => "{$waiting} queue items waiting"]
      : ['status' => 'ok'];
  };
}
```

| Status | Meaning |
|---|---|
| `ok` | Healthy. |
| `fail` | Broken. The detail goes into the alert. |
| `skipped` | Does not apply, for example because it is turned off in the configuration. Counts as healthy. |

Any other status counts as a failure. So does a check that throws an exception, with the exception message as the detail. Name each check after its extension, such as myext.queue, so an alert says where it comes from.

A check seen for the first time starts as healthy. A new check therefore alerts only if it is already failing.

## Built in checks

herald contributes two checks of its own:

| Check | Fails when | Detail |
|---|---|---|
| herald.buffer_backlog | Alerts waiting for aggregation are older than three times their window. The flush job is not delivering. | N buffered alert(s) older than 3x their window — flush job not delivering |
| herald.buffer_dead_letter | The buffer holds [parked alerts](delivery.md#parked-alerts). | N alert(s) parked in the dead letter — inspect with Herald.getParkedAlerts |

## Tuning

All settings are optional. By default herald reports the first failing run and the first healthy run after it, and reminds you once a day while a check keeps failing.

| Setting | Default | Effect |
|---|---|---|
| `herald_monitor_open_after` | 1 | Report a failure only after this many failing runs in a row. This rides out checks that flap. |
| `herald_monitor_close_after` | 1 | Report a recovery only after this many healthy runs in a row. |
| `herald_monitor_renotify_hours` | 24 | Send a reminder when a check has kept failing for this many hours. 0 turns reminders off. |
| `herald_monitor_topic` | ops | The topic the alerts are published to. |

These four are on the Herald settings screen, Administer » Herald » Settings. Snoozes and expected checks are on Administer » Herald » Monitor checks.

That screen lists every check an enabled extension offers, every expected or snoozed check and every check the monitor has a state for. Each row shows the last reported status in words, the failing and healthy runs in a row, when a failure was last reported and whether an enabled extension still offers the check. The screen does not run the checks and does not show their details.

### Snoozing a check

To silence a check during planned work, snooze it until a date and time. On the Monitor checks screen, enter the end in the check's "Snoozed until" field and save. Times are shown and entered in the browser's time zone and stored with their offset. "Clear" removes a snooze. An end that is not in the future is rejected.

With cv:

```bash
cv api4 Setting.set +v 'herald_monitor_snooze={"myext.disk": "2026-10-12T08:00:00"}'
```

While a check is snoozed, herald sends no failure alert and no reminder for it. If the check is still failing when the snooze ends, herald announces the failure then. A recovery is reported only for a failure that was announced, so a failure that started and ended during the snooze sends nothing. Snooze entries that have passed or cannot be read are removed on the next run.

### Expected checks

When an extension is disabled, its checks disappear from the hook, and herald forgets them quietly. To be told instead, mark the checks that must always be there. On the Monitor checks screen, tick "Expected" for a listed check, or add the name of a check whose extension is not enabled yet, and save.

With cv:

```bash
cv api4 Setting.set +v 'herald_monitor_expected=["myext.queue", "myext.disk"]'
```

A listed check that is missing counts as failing, with the detail "check missing — extension disabled?".

The setting `herald_monitor_state` holds the last state of each check. herald manages it, so do not edit it.

## Guarantees

- Each run takes a lock. A run that overlaps with another one skips instead of sending twice.
- The state of each check is saved right after its alert is published. A crash halfway through a run does not repeat the alerts before it. Only a crash in the moment between a publish and its save can send that one alert twice.
- When a publish fails, the check keeps its old state, so the next run reports the change again. The job run then ends with the error "herald monitor: N transition(s) could not be published, see the CiviCRM log", and each failure is a warning in that log.
- When the topic route aggregates, a published alert is only buffered. The flush job delivers it later.

## Limits

The monitor runs inside CiviCRM, so it cannot report a site that is down. Use an external uptime monitor for that case.

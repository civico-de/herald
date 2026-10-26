---
title: Check monitor
order: 50
routes:
  - civicrm/admin/herald
---

# Check monitor

The check monitor tells you when something on the site breaks and when it works again. It reads the messages of the CiviCRM status page (System.check) on every cron run, the same messages core and every extension show there.

herald alerts when a check starts failing and again when it recovers. When a reported failure gets worse or its detail changes, herald alerts at once. While a check keeps failing, a reminder goes out once a day.

The scheduled job "Herald: Run check monitor" reads the checks. Each change goes out with the template "Herald: Monitor check transition" to the topic named in `herald_monitor_topic`, which is ops by default. Give that topic an entry in `herald_topics` to decide where the alerts go, see [Topics](delivery.md#topics). A minimal entry:

```bash
cv api4 Setting.set +v 'herald_topics={"ops": {"channel": "telegram", "chat_id": "-100…"}}'
```

The alerts look like this:

- 🔴 Check myext_queue failed (Error)
- 🟠 Check myext_queue changed (Critical)
- ⏰ Check myext_queue still failing (Error)
- 🟢 Check myext_queue ok again

Below the first line follow the title and the text of the status message. herald turns the message's HTML into plain text, so links are lost; the full message is on the status page.

## Check keys

herald identifies a status message by its name. Some names carry a detail after a colon that may change without becoming a different problem. Core, for example, names its message about missing required extensions `checkExtensionsRequired:<missing extensions>`. herald splits every name at the first colon: the part before it is the key, the rest the detail. A name without a colon is its own key.

The state, the threshold and snoozes belong to the key. When only the detail of a reported failure changes, herald sends a "changed" alert with the new content instead of a recovery and a new failure. When one run returns several messages with the same key, the highest severity counts, and the detail is the sorted list of their details.

herald never compares message texts. Texts such as the one about the last cron run contain times and would alert on every run.

A key that has a state but returns no message in a run counts as healthy. That way herald also reports the recovery of checks that stay silent while healthy. herald keeps a state only for keys that have failed.

## Contributing a check

An extension adds checks through core's `hook_civicrm_check`, so they also appear on the status page:

```php
function myext_civicrm_check(array &$messages): void {
  $waiting = (int) CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_queue_item');
  if ($waiting > 1000) {
    $messages[] = new CRM_Utils_Check_Message(
      'myext_queue',
      ts('%1 queue items waiting.', [1 => $waiting]),
      ts('Queue backlog'),
      \Psr\Log\LogLevel::ERROR,
      'fa-tasks',
    );
  }
}
```

A healthy check returns no message, or one below the threshold. Follow these rules:

- **Not being able to check is a failure.** A check that could not check returns a message at error level ("Could not check: …"). It does not stay silent and does not swallow the exception.
- **The problem goes before the colon.** What names the problem belongs in the name before the colon, what changes about it after the colon. An ID that names a separate problem stays part of the key, such as `myext_discovery_<id>`.
- **Keep the last result.** A check that depends on a fetch, for example a remote feed, returns the last known result under the same name when the fetch fails, and reports the failed fetch as a message of its own. Otherwise a failed fetch reports an open failure as recovered.

Name each check after its extension, such as myext_queue, so an alert says where it comes from.

## Built in checks

herald contributes two checks of its own to the status page:

| Check | Severity | Reported when |
|---|---|---|
| herald_buffer_backlog | error | Alerts waiting for aggregation are older than three times their window. The flush job is not delivering. |
| herald_buffer_dead_letter | warning | The buffer holds [parked alerts](delivery.md#parked-alerts). |

When one of them cannot read the buffer, it reports an error "Could not check" under its own name.

## Threshold

A message counts as a failure when its severity reaches the threshold `herald_monitor_min_severity`, warning by default. The lowest allowed threshold is notice: messages at info and debug report healthy states, such as an up-to-date PHP version, and would fail forever.

`herald_monitor_severity` maps keys to their own threshold. A `*` at the end matches a prefix, because some keys carry an ID. The longest matching entry wins. herald ships `warden_*: error`, so warden's warnings, which are setup hints, do not alert.

```bash
cv api4 Setting.set +v 'herald_monitor_min_severity=warning' 'herald_monitor_severity={"warden_*": "error", "checkLastCron": "critical"}'
```

Both settings reject values below notice and keys with spaces or colons.

## Muting

There are two levels:

- **Core, for the status page and herald.** Hiding a message on the status page hides it for herald too: System.check returns it as not visible. Core hides each message by its full name, so a key can have visible and hidden messages at once; herald then reads only the visible ones, and the key is muted only when all its failing messages are hidden.
- **herald only.** `herald_monitor_snooze` mutes a key or a prefix ending in `*` for the monitor only, until a date or without an end. The status page keeps showing the message. A snooze applies to every detail of the key.

While a check is muted, herald records its state but sends no failure alert, no "changed" alert and no reminder. If the check is still failing when the muting ends, herald announces the failure then, with its current severity and detail. A recovery is reported only for a failure that was announced; that recovery goes out even while the check is muted, because it closes an alert that is already out. A failure that started and ended while muted sends nothing.

Snoozes that have ended or cannot be read are removed on the next run.

## Expected extensions

When an extension is disabled, its checks disappear from the status page, and herald reports at most a recovery for them. To be told instead, list the extensions that must stay installed and enabled in `herald_monitor_expected`:

```bash
cv api4 Setting.set +v 'herald_monitor_expected=["myext", "org.example.other"]'
```

A listed extension that is not installed and enabled fails as the check `herald_expected_<key>` at error level, whatever the threshold.

## The monitor screen

Administer » Herald » Monitor checks shows every key System.check returns and every key the monitor has a state for. Each row shows the title, detail, severity and visibility of the message, whether it fails at its threshold, whether it is snoozed, and the monitor state: the last reported status, the failing and healthy runs in a row, and when a failure was last reported. The screen runs System.check but does not show message texts and writes nothing.

Below the table you set the threshold and its exceptions, the snoozes and the expected extensions. Times are shown and entered in the browser's time zone and stored with their offset. "No end" makes a snooze permanent. A new end that is not in the future is rejected. A link leads to the status page, where messages are hidden in core.

![The monitor checks](images/monitor.png)

The other monitor settings are on the Herald settings screen, Administer » Herald » Settings, all but the topic under "Advanced". The threshold is there too:

| Setting | Default | Effect |
|---|---|---|
| `herald_monitor_open_after` | 1 | Report a failure only after this many failing runs in a row. This rides out checks that flap. |
| `herald_monitor_close_after` | 1 | Report a recovery only after this many healthy runs in a row. |
| `herald_monitor_renotify_hours` | 24 | Send a reminder when a check has kept failing for this many hours. 0 turns reminders off. |
| `herald_monitor_topic` | ops | The topic the alerts are published to. |

The setting `herald_monitor_state` holds the last reported state of each key. herald manages it, so do not edit it.

## Preview

`Herald.monitorPreview` lists the keys the next run would announce, with status, title, detail, text and severity. It sends nothing and writes no monitor state. System.check itself may write core records, for example the reminder of the last cron run.

```bash
cv api4 Herald.monitorPreview
```

## Upgrading from monitor checks of the herald hook

Releases before the switch to System.check ran checks of their own hook under names such as `pulse.updates`. The upgrade step clears the monitor state and the snoozes, because the old names do not exist on the status page. It translates the names in `herald_monitor_expected` into extension keys by their prefix (`pulse.updates` becomes `pulse`). An unknown prefix is kept unchanged as a key; if it is no installed extension, the monitor reports it as missing, and you correct it.

On the first run after the upgrade, every existing failure counts as new. On a site that has grown over the years, that can be a dozen long known warnings. Upgrade like this:

1. Disable the job "Herald: Run check monitor".
2. Upgrade herald together with the extensions that delivered checks, then the others.
3. Run `Herald.monitorPreview` and mute known warnings on the status page or in herald only.
4. Enable the job again.

## Guarantees

- Each run takes a lock. A run that overlaps with another one skips instead of sending twice.
- The state of each check is saved right after its alert is published. A crash halfway through a run does not repeat the alerts before it. Only a crash between publishing an alert and saving the check's state can send that one alert twice.
- When a publish fails, the check keeps its old state, so the next run reports the change again. The job run then ends with the error "herald monitor: N transition(s) could not be published, see the CiviCRM log", and each failure is a warning in that log.
- When the topic aggregates, a published alert is only buffered. The flush job delivers it later.

## Limits

The monitor runs inside CiviCRM, so it cannot report a site that is down. Use an external uptime monitor for that case.

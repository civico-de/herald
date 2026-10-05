# Check monitor

Extensions contribute health checks; herald runs them on every cron run and reports
changes of state. A broken check alerts once, its recovery closes the loop, and a healthy
steady state stays quiet. A check that keeps failing gets a reminder once a day by default
([tuning](#tuning)).

The managed job "Herald: Run check monitor" (`Job.herald_monitor`) runs on every cron run
and publishes each transition with the template `herald_monitor_alert` to the topic in
`herald_monitor_topic` (default `ops`). Route that topic in `herald_topics`
([docs/delivery.md](delivery.md#topics)).

## Contributing a check

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

A check returns `status` and an optional `detail`:

| `status` | Meaning |
|---|---|
| `ok` | Healthy |
| `fail` | Broken; `detail` goes into the alert |
| `skipped` | Not applicable, e.g. disabled by configuration; counts as healthy |

Any other status counts as a failure, and so does a check that throws, with the exception
message as detail. Name checks `<extension>.<check>` so alerts say where they come from.

A check seen for the first time starts as healthy, so a new check alerts only if it is
already failing. The pulse extension contributes its checks through this hook when both
extensions are installed.

## herald's own checks

| Check | Fails when |
|---|---|
| `herald.buffer_backlog` | Waiting aggregation alerts are older than three times their window: the flush job is not delivering |
| `herald.buffer_dead_letter` | The buffer holds [parked alerts](delivery.md#parked-alerts) |

## Tuning

All settings are optional. By default herald reports the first failing and the first
healthy run, and reminds once a day while a check keeps failing.

| Setting | Default | Effect |
|---|---|---|
| `herald_monitor_open_after` | `1` | Report a failure only after N consecutive failing runs, to ride out flapping checks |
| `herald_monitor_close_after` | `1` | Report a recovery only after M consecutive healthy runs |
| `herald_monitor_renotify_hours` | `24` | Remind about a check still failing after this many hours; `0` turns reminders off |
| `herald_monitor_snooze` | empty | Map of check name to ISO datetime: no failure or reminder alerts for that check until then. A recovery after a failure announced before the snooze still goes out. Expired entries are removed on the next run |
| `herald_monitor_expected` | empty | Check names that must be present. A listed check missing from the hook is reported as failing ("check missing, extension disabled?"); without the list a vanished check is silently dropped |

```bash
cv api4 Setting.set +v 'herald_monitor_snooze={"pulse.disk": "2026-10-12T08:00:00"}'
cv api4 Setting.set +v 'herald_monitor_expected=["pulse.cron", "pulse.database"]'
```

`herald_monitor_state` holds the last observed state per check; it is internal and not
meant to be edited.

## Guarantees

- Each run takes the lock `worker.herald.monitor`; an overlapping run skips instead of
  sending twice.
- The state of a check is saved right after its alert was published, so a crash halfway
  through a run does not repeat the alerts before it. Only a crash in the instant between
  a publish and its save can send that one alert twice.
- A failed publish keeps the check's old state and is retried on the next run. The job
  still reports success; the failure is a warning in CiviCRM's log. With an aggregating
  topic route, "published" means buffered, and delivery follows with the flush job.

## Limits

The monitor runs inside CiviCRM and cannot report a site that is down. Pair it with an
external poller on pulse's `/civicrm/pulse` endpoint for that case.

---
title: Check-Monitor
order: 50
routes:
  - civicrm/admin/herald
---

# Check-Monitor

Der Check-Monitor sagt Ihnen, wenn auf der Site etwas kaputtgeht und wenn es wieder funktioniert. Extensions steuern Health-Checks bei, und herald führt sie bei jedem Cron-Lauf aus.

herald meldet Wechsel, keine Zustände. Ein Check, der zu fehlschlagen beginnt, löst eine Meldung aus, seine Erholung eine weitere. Für einen Check, der dauerhaft fehlschlägt, kommt einmal am Tag eine Erinnerung. Auf einer gesunden Site bleibt es still.

Der geplante Job „Herald: Check-Monitor ausführen“ führt die Checks aus. Jeder Wechsel geht mit der Vorlage „Herald: Statuswechsel eines Monitor-Checks“ an das Topic, das in `herald_monitor_topic` steht, standardmäßig ops. Wohin die Meldungen gehen, legen Sie fest, indem Sie dieses Topic in `herald_topics` routen, siehe [Topics](delivery.md#topics). Eine minimale Route:

```bash
cv api4 Setting.set +v 'herald_topics={"ops": {"channel": "telegram", "chat_id": "-100…"}}'
```

Die Meldungen sehen auf einer deutschsprachigen Site so aus:

- 🔴 Check myext.queue fehlgeschlagen: 1500 queue items waiting
- ⏰ Check myext.queue weiterhin gestört: 1500 queue items waiting
- 🟢 Check myext.queue wieder ok

Der Text nach dem Doppelpunkt ist das Detail, das der Check selbst liefert.

## Eigene Checks bereitstellen

Eine Extension ergänzt Checks über den Hook `hook_civicrm_heraldChecks`. Jeder Check ist eine Funktion, die einen Status und optional ein Detail zurückgibt:

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

| Status | Bedeutung |
|---|---|
| `ok` | Gesund. |
| `fail` | Gestört. Das Detail kommt in die Meldung. |
| `skipped` | Trifft nicht zu, zum Beispiel weil der Check in der Konfiguration abgeschaltet ist. Zählt als gesund. |

Jeder andere Status zählt als Fehler. Ebenso ein Check, der eine Exception wirft, mit der Meldung der Exception als Detail. Benennen Sie jeden Check nach seiner Extension, etwa myext.queue, damit eine Meldung verrät, woher sie stammt.

Ein Check, den herald zum ersten Mal sieht, beginnt als gesund. Ein neuer Check meldet sich daher nur, wenn er bereits fehlschlägt.

## Eingebaute Checks

herald bringt zwei eigene Checks mit:

| Check | Schlägt fehl, wenn | Detail |
|---|---|---|
| herald.buffer_backlog | Meldungen, die auf das Sammeln warten, älter sind als das Dreifache ihres Fensters. Der Job zum Leeren des Puffers stellt nicht zu. | N buffered alert(s) older than 3x their window — flush job not delivering |
| herald.buffer_dead_letter | Der Puffer [geparkte Meldungen](delivery.md#geparkte-meldungen) enthält. | N alert(s) parked in the dead letter — inspect with Herald.getParkedAlerts |

## Feinabstimmung

Alle Einstellungen sind optional. Standardmäßig meldet herald den ersten fehlgeschlagenen Lauf und den ersten gesunden Lauf danach und erinnert einmal am Tag, solange ein Check weiter fehlschlägt.

| Einstellung | Standard | Wirkung |
|---|---|---|
| `herald_monitor_open_after` | 1 | Einen Fehler erst nach so vielen fehlgeschlagenen Läufen in Folge melden. So fallen Checks, die hin und her springen, nicht ins Gewicht. |
| `herald_monitor_close_after` | 1 | Eine Erholung erst nach so vielen gesunden Läufen in Folge melden. |
| `herald_monitor_renotify_hours` | 24 | Eine Erinnerung senden, wenn ein Check so viele Stunden lang fehlschlägt. 0 schaltet Erinnerungen ab. |
| `herald_monitor_topic` | ops | Das Topic, in das die Meldungen veröffentlicht werden. |

Diese vier finden Sie auf der Einstellungsseite von herald, „Administer » Herald » Settings“ (Administrieren » Herald » Einstellungen). Stummschaltungen und erwartete Checks stehen unter „Administer » Herald » Monitor checks“ (Monitor-Checks).

Diese Seite listet jeden Check, den eine aktivierte Extension anbietet, jeden erwarteten oder stummgeschalteten Check und jeden Check, für den der Monitor einen Zustand gespeichert hat. Jede Zeile zeigt den zuletzt gemeldeten Status in Worten, die gescheiterten und erfolgreichen Läufe in Folge, die letzte Fehlermeldung und ob eine aktivierte Extension den Check noch anbietet. Die Seite führt die Checks nicht aus und zeigt ihre Details nicht.

### Einen Check stummschalten

Um einen Check während geplanter Arbeiten ruhigzustellen, schalten Sie ihn bis zu einem Datum und einer Uhrzeit stumm. Tragen Sie auf der Seite „Monitor-Checks“ das Ende im Feld „Stumm bis“ des Checks ein und speichern Sie. Zeiten werden in der Zeitzone des Browsers angezeigt und eingegeben und mit ihrem Versatz gespeichert. „Leeren“ entfernt eine Stummschaltung. Ein Ende, das nicht in der Zukunft liegt, wird abgelehnt.

Mit cv:

```bash
cv api4 Setting.set +v 'herald_monitor_snooze={"myext.disk": "2026-10-12T08:00:00"}'
```

Solange ein Check stummgeschaltet ist, sendet herald für ihn weder eine Fehlermeldung noch eine Erinnerung. Schlägt der Check beim Ende der Stummschaltung noch fehl, meldet herald den Fehler dann. Eine Erholung wird nur für einen gemeldeten Fehler gemeldet, ein Fehler, der während der Stummschaltung begann und endete, löst also nichts aus. Abgelaufene oder unlesbare Einträge entfernt herald beim nächsten Lauf.

### Erwartete Checks

Wenn eine Extension deaktiviert wird, verschwinden ihre Checks aus dem Hook, und herald vergisst sie stillschweigend. Wenn Sie stattdessen benachrichtigt werden möchten, markieren Sie die Checks, die immer vorhanden sein müssen. Kreuzen Sie auf der Seite „Monitor-Checks“ bei einem aufgelisteten Check „Erwartet“ an, oder fügen Sie den Namen eines Checks hinzu, dessen Extension noch nicht aktiviert ist, und speichern Sie.

Mit cv:

```bash
cv api4 Setting.set +v 'herald_monitor_expected=["myext.queue", "myext.disk"]'
```

Ein aufgelisteter Check, der fehlt, zählt als fehlgeschlagen, mit dem Detail „Check fehlt — Extension deaktiviert?“.

Die Einstellung `herald_monitor_state` enthält den letzten Zustand jedes Checks. herald verwaltet sie selbst, bearbeiten Sie sie also nicht.

## Garantien

- Jeder Lauf setzt eine Sperre. Ein Lauf, der sich mit einem anderen überschneidet, wird übersprungen, statt doppelt zu senden.
- Der Zustand jedes Checks wird direkt nach dem Veröffentlichen seiner Meldung gespeichert. Ein Absturz mitten im Lauf wiederholt die Meldungen davor nicht. Nur ein Absturz genau zwischen einer Veröffentlichung und ihrem Speichern kann diese eine Meldung doppelt senden.
- Schlägt eine Veröffentlichung fehl, behält der Check seinen alten Zustand und wird beim nächsten Lauf erneut versucht. Der Joblauf endet dann mit dem Fehler „herald monitor: N transition(s) could not be published, see the CiviCRM log“, und jeder Fehler steht als Warnung in diesem Protokoll.
- Sammelt die Route des Topics Meldungen, wird eine veröffentlichte Meldung nur gepuffert. Der Job zum Leeren des Puffers stellt sie später zu.

## Grenzen

Der Monitor läuft innerhalb von CiviCRM und kann deshalb keine Site melden, die nicht erreichbar ist. Verwenden Sie dafür einen externen Uptime-Monitor.

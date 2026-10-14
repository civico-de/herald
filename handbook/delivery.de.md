---
title: Zustellung
order: 30
routes:
  - civicrm/admin/herald
---

# Zustellung

Diese Seite beschreibt, wer eine Nachricht erhält, wann sie verschickt wird und was passiert, wenn sie sich nicht zustellen lässt.

## Zustellmodi

Jede CiviRules-Aktion von herald hat einen Zustellmodus. Auch Topic-Routen kennen diesen Parameter, `delivery_mode`. Im Aktionsformular heißen die Modi „Direkt“, „Abonnent:innen“, „Gruppe“ und „Adressliste“.

| Modus | Empfänger:innen | Pflichtparameter |
|---|---|---|
| `direct` | Das an der Aktion eingetragene Ziel oder die Standardeinstellung des Kanals. Das ist der Standard. | keiner |
| `subscribers` | Alle Kontakte, die einen Abo-Typ abonniert haben, jeweils über ihren eigenen Kanal | `subscription_type` |
| `group` | Alle Kontakte einer CiviCRM-Gruppe, jeweils über ihren eigenen Kanal | `group_id` |
| `addresses` | Eine Liste von E-Mail-Adressen, ganz ohne Kontaktdatensätze | `email_addresses` |

Jeder andere Modus lässt den Versand scheitern. Das Aktionsformular speichert keinen Modus ohne seinen Pflichtparameter.

Im Abonnent:innen-Modus wird ein Kontakt ohne gewählten Kanal oder ohne das Ziel, das dieser Kanal braucht, übersprungen.

Im Gruppenmodus spielen Abonnements und Bezüge keine Rolle. Ein Gruppenmitglied ohne verwendbare Einstellungen erhält stattdessen eine E-Mail an die eigene Adresse. Das gilt auch für ein Mitglied, das Telegram gewählt, die Chat-ID aber leer gelassen hat.

Der Adresslisten-Modus sendet immer E-Mail. Trennen Sie die Adressen durch Kommas, Semikolons oder Zeilenumbrüche. Er eignet sich für Funktionsadressen und Empfänger:innen, die keine Kontakte sind.

Gelöschte und verstorbene Kontakte erhalten nie etwas. Kontakte mit dem Vermerk „Do not email“ (keine E-Mails) erhalten nie E-Mails, unabhängig von den folgenden Optionen.

Zwei Optionen gelten für die Kontaktmodi, also Abonnent:innen und Gruppe:

| Parameter | Standard | Wirkung |
|---|---|---|
| `respect_suspension` | aus | Auch E-Mail-Empfänger:innen überspringen, die sich abgemeldet haben oder deren Adresse gesperrt ist. |
| `email_location_type` | primär | An die E-Mail-Adresse des Kontakts mit diesem Adresstyp senden. Kontakte ohne eine solche Adresse erhalten die Nachricht an ihre primäre Adresse. |

Ein Info-Eintrag im CiviCRM-Protokoll zählt die ausgelassenen Kontakte nach Grund: `suspended` (gesperrt), `scope_mismatch` (Bezug passt nicht), `no_channel` (kein Kanal gewählt) und `no_recipient` (keine Adresse, kein Chat, kein Topic für den Kanal).

Scheitert die Zustellung an einzelne Empfänger:innen, hält das die übrigen nicht auf. herald protokolliert eine Warnung und macht mit dem nächsten Kontakt weiter. Die Zustellung als Ganzes schlägt nur fehl, wenn sie bei allen Empfänger:innen fehlgeschlagen ist oder wenn es niemanden gab, an den herald senden konnte: keine Abonnent:innen, eine leere Gruppe oder eine leere Adressliste. Sobald mindestens eine Person die Nachricht erhalten hat, gilt die Zustellung als erledigt; für die gescheiterten Empfänger:innen unternimmt herald keinen weiteren Versuch.

## Abonnements

Jeder Kontakt kann einen Kanal und die gewünschten Benachrichtigungen wählen. herald ergänzt Kontakte dafür um die Gruppe benutzerdefinierter Felder „Benachrichtigungseinstellungen“:

| Feld | Bedeutung |
|---|---|
| Bevorzugter Kanal | Telegram, Webhook, ntfy, Pushover oder E-Mail |
| Telegram-Chat-ID, Pushover-Benutzer-/Gruppenschlüssel, ntfy-Topic, Webhook-URL | Das Ziel für den gewählten Kanal. E-Mail braucht keines und verwendet die eigene Adresse des Kontakts. |
| Abonnements | Die Abo-Typen, die der Kontakt erhalten möchte |
| Bezug: Objekttyp, Bezug: Objekt-ID | Optional, siehe [Abos mit Bezug](#abos-mit-bezug) |

Admins können diese Felder am Kontaktdatensatz ausfüllen. Zur Selbstverwaltung installiert herald das Profil „My notification preferences“ (meine Benachrichtigungseinstellungen). Schlagen Sie seine ID unter Administrieren » Daten und Masken anpassen » Profile (englisch: Administer » Customize Data and Screens » Profiles) nach und schicken Sie den Mitarbeitenden diesen Link:

```
/civicrm/profile/edit?gid=<id>&reset=1
```

CiviCRM öffnet das Profil für den angemeldeten Kontakt, der nur seinen eigenen Datensatz sehen und bearbeiten kann.

Um die Abonnements zu nutzen, setzen Sie den Zustellmodus einer Aktion auf „Abonnent:innen“ und wählen den Abo-Typ. Das an der Aktion eingetragene Ziel wird in diesem Modus ignoriert. Von der Regel an die Kanäle der Abonnent:innen gelangen nur Darstellungsoptionen:

- Telegram: `parse_mode`, `disable_notification`, `disable_web_page_preview`
- Webhook: `webhook_body_format`, `webhook_body_key`
- ntfy: `ntfy_title`, `ntfy_priority`, `ntfy_tags`, `ntfy_click`, `ntfy_markdown`
- Pushover: `pushover_title`, `pushover_priority`, `pushover_sound`, `pushover_url`, `pushover_url_title`, `pushover_html`, `pushover_retry`, `pushover_expire`
- E-Mail: `email_subject`, `email_format`

Ziele, Tokens, Header, CC und BCC werden nie übernommen. Die Webhook-URL eines Kontakts erhält deshalb nie das Webhook-Token der Site.

herald liefert diese Abo-Typen mit:

| Name | Bezeichnung |
|---|---|
| `completed_alert` | Jeder abgeschlossene Beitrag |
| `pending_alert` | Jeder ausstehende Beitrag |
| `status_changed_alert` | Übergänge Ausstehend → Abgeschlossen |
| `daily_digest` | Täglicher Report |
| `weekly_digest` | Wöchentlicher Report |

Die Typen sind die Werte der Optionsgruppe `herald_subscription_type`. Eigene ergänzen Sie unter Administrieren » Daten und Masken anpassen » Optionsgruppen (englisch: Administer » Customize Data and Screens » Option Groups) oder mit `cv api4 OptionValue.create`. Ein neuer Wert erscheint in der Liste der Abo-Typen der Aktion. Wenn Sie einen Wert deaktivieren, verschwindet er nur aus dieser Liste. Aktionen, die ihn bereits verwenden, stellen weiter an seine Abonnent:innen zu.

Die beiden Report-Typen sind für Reports gedacht, die im Abonnent:innen-Modus verschickt werden, über die CiviRules-Aktion oder über einen geplanten Job.

### Abos mit Bezug

Ein Einstellungsdatensatz lässt sich auf einen einzelnen Datensatz beschränken, etwa auf das Event mit der ID 42. Setzen Sie dazu „Bezug: Objekttyp“ auf Event und „Bezug: Objekt-ID“ auf 42. Dieser Kontakt erhält dann nur noch Benachrichtigungen zu diesem Event.

Auf welchen Datensatz sich eine Regel bezieht, legt das Feld „Abo-Bezug“ an der Aktion „Herald: Send notification (generic)“ fest. Zur Auswahl stehen Event, Mitgliedschaft und Teilnehmer:in, und herald übernimmt die ID aus dem Trigger. Event funktioniert auch bei Triggern auf Teilnahmen, über das Event der Teilnahme.

Ein Einstellungsdatensatz ohne Bezug erhält alles, was er abonniert hat. Der Bezug gilt für alle Abonnements des Datensatzes. Ein Datensatz mit Bezug erhält daher keine Benachrichtigungen ohne Bezug mehr, etwa Beitragsmeldungen, und auch keine Reports, denn die Report-Aktion hat keinen Bezug. Das Profil zur Selbstverwaltung enthält die Bezugsfelder nicht, Admins setzen sie also am Kontakt.

## Topics

Bei Topics nennt der Absender nur ein Topic, und die Einstellung `herald_topics` entscheidet über Kanal, Ziel und Sammeln. Ein Skript oder eine andere Extension muss also nie eine Chat-ID oder eine URL kennen.

Die Routen finden Sie unter Administrieren » Herald » Topic-Routen. Die Liste zeigt jedes Topic mit seinem Kanal, seinem Zustellmodus und seinem Sammelfenster. „Topic hinzufügen“ und „Bearbeiten“ öffnen ein Formular mit den Zustellfeldern und den Optionen, die der Kanal des Topics deklariert. Schlüssel ohne eigenes Feld, etwa `channel_config`, gehören als JSON-Objekt in „Weitere Parameter“. Beim Speichern prüft herald die Route und speichert nichts, wenn es eines dieser Probleme findet: ein nicht registrierter Kanal, ein Wert vom falschen Typ, ein Zustellmodus ohne seinen Pflichtparameter oder ein bereits vergebener Topic-Name. Ein umbenanntes Topic behält seinen Platz in der Liste. „Löschen“ fragt vorher nach; Nachrichten an ein gelöschtes Topic laufen danach über die Route default.

So setzen Sie alle Routen auf einmal mit cv:

```bash
cv api4 --in=json Setting.set <<'JSON'
{"values": {"herald_topics": {
  "default": {"channel": "telegram"},
  "ops":     {"channel": "telegram", "chat_id": "-100…"},
  "revenue": {"channel": "telegram", "chat_id": "-100…", "aggregate_minutes": 5}
}}}
JSON
```

Dann veröffentlichen Sie:

```bash
cv api4 Herald.publish topic=ops message='🔴 disk almost full'
cv api4 Herald.publish topic=revenue template=herald_contribution_alert_completed \
    vars='{"contribution":{"total_amount":50}}' amount=50
```

Ein Routeneintrag nimmt dieselben Parameter auf wie eine Aktion, darunter `channel_config`, `delivery_mode` und `aggregate_minutes`. Die Werte im Eintrag des Topics überschreiben die des Eintrags default. Der Kanal ist standardmäßig Telegram. Ein Topic ohne eigenen Eintrag und ohne Eintrag default schlägt mit dem Fehler „no route for topic“ fehl.

`Herald.publish` nimmt entweder `message` oder ein `template` mit `vars` entgegen, nie beides. Eine Nachricht ist HTML, genau wie eine gerenderte Vorlage. Mit `format=text` ist die Nachricht Klartext: Zeichen wie `<` und `&` erscheinen unverändert, auch in Kanälen, die HTML darstellen. Das Format gilt nur für Nachrichten. `amount` geht in die Summe in der Kopfzeile einer Sammelnachricht ein.

Der Check-Monitor veröffentlicht in das Topic, das in `herald_monitor_topic` steht, standardmäßig ops.

## Sammelnachrichten

Meldungen, die gehäuft auftreten, lassen sich sammeln und als eine Nachricht verschicken. Setzen Sie dazu `aggregate_minutes` an einer Aktion oder einer Topic-Route; im Aktionsformular heißt das Feld „Sammelfenster in Minuten“. Der Standard 0 sendet sofort.

Ein Wert über 0 legt die gerenderte Meldung in der Tabelle `civicrm_herald_buffer` ab. Der geplante Job „Herald: Sammelpuffer für Meldungen leeren“ läuft bei jedem Cron-Lauf. Sobald die älteste wartende Meldung einer Gruppe älter ist als ihr Fenster, wird die ganze Gruppe als eine Nachricht verschickt.

- Eine Gruppe entspricht einem Satz von Parametern. Regeln, deren Aktionen identische Parameter tragen, teilen sich eine Nachricht. Ändern Sie die Parameter einer Regel, beginnt eine neue Gruppe. Topic-Routen bilden eigene Gruppen.
- Ist ein Abo-Bezug gesetzt, bilden Meldungen zu verschiedenen Events, Mitgliedschaften oder Teilnahmen getrennte Gruppen.
- Eine einzelne wartende Meldung wird unverändert verschickt. Das Sammeln hat sie nur verzögert.
- Mehrere Meldungen erhalten eine Kopfzeile mit ihrer Anzahl, gefolgt von den einzelnen Nachrichten. Tragen die Meldungen einen Betrag, wie es Beitragsmeldungen tun, zeigt die Kopfzeile auch die Summe, zum Beispiel „📬 7 Meldungen, gesamt 385,00 € (gesammelt über 5 min)“. Der Betrag verwendet das Währungsformat der Site.
- Eine gesammelte E-Mail erhält diese Kopfzeile als Betreff.
- Ein Import oder eine Massenänderung des Status kann Tausende Meldungen in ein Fenster bringen. Deshalb gibt herald höchstens `herald_buffer_max_items` Nachrichten aus, standardmäßig 25. Der Rest wird als „… und N weitere“ zusammengefasst, und die Kopfzeile zählt weiterhin jede Meldung. Mit 0 gibt herald alle aus.
- Telegram kürzt eine Nachricht, die noch immer über seiner Grenze von 4096 Zeichen liegt, und vermerkt das in der Nachricht, siehe [Telegram](channels.md#telegram). Andere Kanäle senden den vollständigen Text, halten Sie `herald_buffer_max_items` also niedrig genug für deren Grenzen.
- Die längste Verzögerung ist das Fenster plus das Cron-Intervall. Wählen Sie das Fenster pro Regel, zum Beispiel 5 Minuten für Ticketverkäufe und 60 für ausstehende Spenden.

## Geparkte Meldungen

Eine wartende Gruppe, deren Zustellung fehlschlägt, bleibt im Puffer und wird beim nächsten Cron-Lauf erneut versucht. Nach `herald_buffer_max_attempts` fehlgeschlagenen Versuchen, standardmäßig 5, parkt herald sie. Eine Gruppe ohne Empfänger:innen wird sofort geparkt, weil ein weiterer Versuch nicht helfen kann. Geparkte Meldungen werden nie gelöscht; sie bleiben in der Tabelle, bis jemand sie wieder aktiviert.

Die Seite Administrieren » Herald » Geparkte Meldungen listet sie auf. Die Liste ist eine SearchKit-Anzeige auf der schreibgeschützten Entität `HeraldBufferedAlert`. Um Meldungen wieder zu aktivieren, wählen Sie ihre Zeilen aus und dann im Aktionsmenü „Geparkte Meldungen reaktivieren“; die Rückfrage nennt die Anzahl der Meldungen. Weder die Anzeige noch `Herald.getParkedAlerts` zeigt Nachrichtentexte oder Zustellparameter, weil diese Kontaktdaten enthalten können.

So listen Sie geparkte Meldungen mit cv auf und aktivieren sie wieder:

```bash
cv api4 Herald.getParkedAlerts
cv api4 Herald.reactivateParkedAlerts '{"ids": [12, 13]}'
```

Die Liste zeigt für jede Meldung die ID, den Grund des Parkens, die Anzahl der Versuche, den letzten Fehler und die Zeitpunkte. Ohne `ids` aktiviert `reactivateParkedAlerts` alle geparkten Meldungen wieder.

Für wieder aktivierte Meldungen beginnt die Zählung der Versuche von vorn. Sie behalten die Parameter, die beim Puffern gespeichert wurden. Eine Korrektur an der Regel oder der Route korrigiert daher kein falsches Ziel, das an der Aktion eingetragen war. Empfänger:innen von Abonnements und Gruppen werden bei der Zustellung neu ermittelt, eine Korrektur an den Einstellungen eines Kontakts hilft also.

Der Check-Monitor meldet geparkte Meldungen und einen hängenden Puffer, siehe [Eingebaute Checks](monitor.md#eingebaute-checks).

Wenn Sie herald deinstallieren, wird die Puffertabelle samt geparkter Meldungen gelöscht. Beim Deaktivieren von herald bleibt sie erhalten.

Zustellungen ohne Sammeln werden nie gepuffert. Schlagen sie fehl, geht der Fehler an den Aufrufer zurück, also an das API-Ergebnis oder das CiviRules-Protokoll, und es gibt keinen weiteren Versuch.

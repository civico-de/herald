---
title: Referenz
order: 60
routes:
  - civicrm/herald/form/civirulesaction/sendnotification
  - civicrm/herald/form/civirulesaction/senddigest
  - civicrm/herald/form/civirulescondition/fieldtransition
  - civicrm/herald/form/civirulestrigger/schedule
  - civicrm/admin/herald
---

# Referenz

## CiviRules-Aktionen

herald ergänzt CiviRules um zwei Aktionen. Ihre Formulare haben diese Felder gemeinsam:

- Senden: „Mit eigener Zustellung“ oder „Über ein Topic“. Die Route eines Topics legt Kanal, Empfänger:innen und Sammeln fest, siehe [Topics](delivery.md#topics); die folgenden Zustellfelder sind dann ausgeblendet.
- Zustellmodus, mit dem Topic, dessen Abonnent:innen erreicht werden, der Gruppe, den E-Mail-Adressen, der Option „Kommunikationssperren beachten“ und dem Adresstyp der E-Mail. Siehe [Zustellmodi](delivery.md#zustellmodi).
- Kanal, einer der aktiven Kanäle unter Administrieren » Herald » Kanäle. Direkt- und Adresslisten-Modus senden über ihn. Abonnent:innen und Gruppenmitglieder verwenden ihren eigenen Kanal.
- Nachrichtenvorlage.
- Die Zustellfelder des Typs des gewählten Kanals, etwa die Telegram-Chat-ID. Leere Felder behalten die Standardwerte des Kanals.
- Sammelfenster in Minuten. 0 sendet sofort, siehe [Sammelnachrichten](delivery.md#sammelnachrichten).

Die Formulare erfordern die Berechtigung „administer CiviCRM“ (CiviCRM administrieren).

### Benachrichtigung senden

Die Aktion „Herald: Send notification (generic)“ rendert eine Vorlage mit den Daten, die der Trigger mitbringt, siehe [Triggervariablen](#triggervariablen). Ihr Formular listet jede Vorlage auf, deren Workflow-Name mit herald_ beginnt. Es hat zwei eigene Felder:

| Feld | Bedeutung |
|---|---|
| Variablen | Ein JSON-Objekt mit zusätzlichen Vorlagenvariablen. Ein Schlüssel der obersten Ebene ersetzt die gleichnamige Variable des Triggers vollständig, `{"contact": {…}}` verwirft also alle erzeugten Kontaktfelder. Das Formular lehnt ungültiges JSON ab. |
| Abo-Bezug | Event, Mitgliedschaft oder Teilnehmer:in: der Datensatz, um den es in der Benachrichtigung geht, für [Abos mit Bezug](delivery.md#abos-mit-bezug). |

Bei einem Beitrags-Trigger enthalten die Variablen außerdem den Beitrag, den Fortschritt seiner Kampagne und den Statuswechsel, siehe [Beitragsvariablen](#beitragsvariablen).

### Report senden

Die Aktion „Herald: Send digest“ verschickt bei jedem Auslösen der Regel einen Report. Im Formular wählen Sie einen der Reports, die unter Administrieren » Herald » Reports eingerichtet sind, siehe [Einen Report einrichten](digests.md#einen-report-einrichten), und legen die Zustellfelder wie bei „Send notification“ fest. Die Regelliste zeigt die Aktion als `Verschickt den Report „…“.` Wurde der Report umbenannt oder gelöscht, steht dort `Den Report „…“ gibt es nicht.`

## CiviRules-Trigger

### Zeitplan

Der Trigger „Herald: Schedule“ löst an den gewählten Wochentagen einmal zur vollen Stunde aus, in der Zeitzone von CiviCRM, und bezieht sich auf keinen Kontakt. Er ist für Aktionen wie „Herald: Send digest“ gedacht, siehe [Geplante Reports](digests.md#geplante-reports). Sein Formular hat zwei Felder:

| Feld | Bedeutung |
|---|---|
| Wochentage | Beliebig viele Tage von Montag bis Sonntag. Ohne Auswahl jeden Tag. |
| Uhrzeit | Die Stunde, von 00:00 bis 23:00. |

Die Regelliste beschreibt den Zeitplan, zum Beispiel „Jeden Montag um 08:00“ oder „Täglich um 08:00“. Der Trigger speichert `weekdays` (ISO-Nummern, 1 für Montag bis 7 für Sonntag), `hour` und `since`, den Zeitpunkt, zu dem das Formular gespeichert wurde.

Bei jedem Lauf des geplanten Jobs von CiviRules löst die Regel aus, wenn ihr jüngster Termin nicht älter ist als die Regel oder als `since` und das Regelprotokoll seit diesem Termin keinen Lauf enthält. Das Formular braucht die Berechtigung „administer CiviCRM“.

## CiviRules-Bedingungen

### Feldwechsel

Die Bedingung „Herald: Field transition“ ist wahr, wenn der Wert eines Feldes vor und nach einer Änderung zu Ihrer Konfiguration passt.

| Feld | Bedeutung |
|---|---|
| Entität | Der APIv4-Name der Entität, etwa Contribution. Kontakt-Untertypen zählen als Contact. |
| Feld | Der APIv4-Feldname. Ein benutzerdefiniertes Feld schreiben Sie als group.field. Ein Feld eines verknüpften Datensatzes erreichen Sie über dessen Fremdschlüssel, etwa `contact_id.contact_type`. An ein Auswahlfeld können Sie ein Suffix wie `:name` anhängen. Dann vergleicht die Bedingung den Namen der Option statt ihrer ID, etwa bei `contribution_status_id:name`. Nehmen Sie `:name` statt `:label`, denn eine Bezeichnung kann sich mit der Sprache der Site ändern. |
| Vorher: Operator, Vorher: Wert | Die Prüfung für den Wert vor der Änderung. |
| Nachher: Operator, Nachher: Wert | Die Prüfung für den Wert nach der Änderung. |

Das Formular speichert keine Entität, die es nicht gibt, kein Feld, das die Entität nicht hat, kein Suffix, das das Feld nicht anbietet, und keinen Suchfilter wie `groups`, der keinen Wert hat. Bei Feldnamen zählt die Groß- und Kleinschreibung. Außerdem lehnt das Formular `>`, `<`, `>=` und `<=` ab, wenn der Wert keine Zahl ist. Ein Optionsname ist Text, verwenden Sie diese Operatoren also am Feld ohne Suffix.

Die Operatoren sind `=`, `!=`, `>`, `<`, `>=`, `<=`, `is_empty` und `not_empty`. „- beliebiger Wert -“ lässt diese Seite offen. Jeder andere Operator, den nur die API übergeben kann, lässt die Bedingung mit einem Fehler scheitern.

`>`, `<`, `>=` und `<=` brauchen auf beiden Seiten Zahlen. `=` und `!=` vergleichen Zahlen, wenn beide Seiten Zahlen sind, und sonst Text ohne führende und folgende Leerzeichen. Ein leerer Wert ist gleich einem leeren Feld. Schreiben Sie Dezimalzahlen mit Punkt, nicht mit Komma.

Zahlen werden exakt verglichen, egal wie viele Stellen sie haben. Eine Zahl mit Exponent wie `1e3` wird als Gleitkommazahl verglichen und ist damit auf etwa 15 Stellen genau. Auch Felder vom Typ Money und Float kommen als Gleitkommazahlen bei herald an, und ein Betrag von 19.99 ist trotzdem gleich `19.99`.

- Sind beide Seiten offen, ist die Bedingung nie wahr.
- Ist nur eine Seite konfiguriert, muss sich der Wert außerdem geändert haben. Andere Änderungen an einem Datensatz, der bereits im Zielzustand ist, lösen daher nicht erneut aus. Ein Feld, das leer bleibt, hat sich nicht geändert.
- Sind beide Seiten konfiguriert, entscheiden allein die beiden Prüfungen.

Der Wert vor der Änderung stammt aus der Kopie, die CiviRules vom Datensatz aufbewahrt. Bei einem Feld mit Suffix schlägt herald die Option zur gespeicherten ID nach. Ein leerer gespeicherter Wert gilt als leer, ein gespeicherter Wert ohne Option als fehlend. Hat CiviRules keinen Wert, verwendet herald seine eigene Kopie, die direkt vor der Änderung entsteht. Ist der Wert dann noch unbekannt, ist die Bedingung falsch, denn eine ausgebliebene Meldung ist besser als ein Fehlalarm.

Die eigene Kopie von herald lebt nur so lange wie die Anfrage, eine verzögerte Aktion prüft ihre Bedingungen aber erst später im Cron-Lauf. Benutzerdefinierte Felder führt CiviRules unter anderen Namen, und Felder verknüpfter Datensätze hat es gar nicht. Für sie ist die Kopie von herald also die einzige Quelle. Setzen Sie bei einer verzögerten Aktion mit einem solchen Feld „Don't recheck condition upon processing of delayed action“, sonst löst die Bedingung nie aus.

Der Wert nach der Änderung wird aus dem gespeicherten Datensatz gelesen. Eine Teilaktualisierung, die das Feld nicht schreibt, wird daher nach dem tatsächlich gespeicherten Wert beurteilt.

herald kopiert nur Felder, die aktive Regeln beobachten. Ein lange laufender Queue- oder Kommandozeilen-Worker sieht ein neu konfiguriertes Feld erst nach einem Neustart.

Beispiele:

| Beobachtet | Entität | Feld | Vorher | Nachher |
|---|---|---|---|---|
| Eine Mitgliedschaft wechselt von Status 2 zu 3 | Membership | status_id | = 2 | = 3 |
| Ein Beitrag verlässt Pending | Contribution | contribution_status_id:name | = Pending | beliebiger Wert |
| Ein Beitrag war vor der Änderung Pending, geändert oder nicht | Contribution | contribution_status_id:name | = Pending | not_empty |
| Ein Event wird deaktiviert | Event | is_active | = 1 | = 0 |

## APIv4

Die Entität `Herald` bietet diese Aktionen. Die Aktionen erfordern die Berechtigung „administer CiviCRM“, nur die Metadaten-Aktionen wie getFields kommen mit „access CiviCRM“ (auf CiviCRM zugreifen) aus.

| Aktion | Parameter | Was sie tut |
|---|---|---|
| send | channel, message, subject, format, config | Stellt eine fertige Nachricht über einen konfigurierten Kanal zu, den `channel` nennt. Das ist der Einstiegspunkt für andere Extensions. Das Ergebnis listet `unknown_config_keys` auf. |
| publish | topic, message oder template mit vars, format, amount | Stellt über die Route in `herald_topics` zu, siehe [Topics](delivery.md#topics). |
| notify | actionParams, triggerContext | Wird von „Send notification“ aufgerufen. |
| notifyDigest | digest, actionParams, sendHour, daily, skipEmpty | Wird von „Send digest“ und von Skripten aufgerufen, siehe [Einen Report verschicken](digests.md#einen-report-verschicken). |
| checkSchedule | ruleId, schedule | Wird von „Herald: Schedule“ aufgerufen. Gibt `due`, `slot`, `last_run` und `not_before` zurück. |
| checkFieldTransition | entity, entityId, field, fromOperator, fromValue, toOperator, toValue, originalValue, hasOriginal | Wird von „Field transition“ aufgerufen. Gibt `matched` zurück. |
| getChannelTypes | type, optional | Listet die Kanaltypen und die Schlüssel auf, die sie akzeptieren, mit den Angaben zu Geltungsbereich, Geheimnis und Ziel. |
| getChannels | withUsage, optional | Listet die konfigurierten Kanäle auf. Geheimnisse erscheinen nur als gesetzt oder nicht gesetzt. withUsage ergänzt, wer jeden Kanal nennt. |
| saveChannel | name, channel, isNew | Prüft einen Kanal und speichert ihn in `herald_channels`, seine Geheimnisse verschlüsselt. Ein ausgelassenes oder leeres Geheimnis behält seinen gespeicherten Wert, `null` löscht ihn. |
| deleteChannel | name | Entfernt einen Kanal. Verweigert das, solange eine Route, eine Regelaktion, ein Job, eine gepufferte Meldung oder eine Einstellung ihn nennt. |
| getParkedAlerts | ids, optional | Listet [geparkte Meldungen](delivery.md#geparkte-meldungen) ohne ihre Nachrichtentexte auf. |
| reactivateParkedAlerts | ids, optional | Legt geparkte Meldungen zurück in den Puffer. Ohne ids alle. |
| renderTemplate | workflow, vars | Rendert eine Vorlage, ohne sie zu senden. |
| previewDigest | digest, sources | Setzt einen Report aus ungespeicherten Werten mit den heutigen Daten zusammen und sendet nichts. Die Vorschau des Report-Formulars ruft sie auf. |
| sendDigestTest | digest, sources | Setzt wie previewDigest zusammen und schickt das Ergebnis nur an die primäre E-Mail-Adresse des angemeldeten Benutzers. Gibt `sent`, `to` und `problems` zurück; solange es Probleme gibt, wird nichts verschickt. |
| getDigestPlaceholders | source, digest | Die Platzhalter und der Textbaustein einer Quelle, wie das Report-Formular sie zeigt. |
| saveTopic | topic, route, previousName | Prüft eine Route und speichert sie in `herald_topics`; mit previousName wird dieses Topic an seinem Platz umbenannt. Die Seite „Topic-Routen“ verwendet diese Aktion. |
| deleteTopic | topic | Entfernt eine Route aus `herald_topics`. Verweigert das, solange eine Regelaktion, ein Job oder der Monitor sie nennt. |
| getTopicUsage | keine | Listet die Regelaktionen, Jobs und den Monitor auf, die jedes Topic nennen. |
| saveCleanedFields | fields | Prüft und speichert `herald_cleaned_fields`. Fehler nennen das Feld und die Regel. |
| saveAllowedHosts | hosts | Prüft und speichert `herald_webhook_allowed_hosts`. Leere Einträge werden übersprungen. |
| getMonitorChecks | keine | Listet jeden angebotenen, erwarteten, stummgeschalteten oder verfolgten Check mit seinem gespeicherten Zustand, ohne Details und ohne die Checks auszuführen. |
| saveMonitorChecks | expected, snooze | Prüft und speichert `herald_monitor_expected` und `herald_monitor_snooze`. Eine Stummschaltung muss in der Zukunft enden, sofern sie nicht unverändert ist. |

Die Speicheraktionen speichern genau die Form, die `Setting.set` erwartet. `Setting.set` funktioniert weiterhin, prüft aber nichts. Ungültige Eingaben lehnen die Speicheraktionen mit einer Liste aller Probleme ab und speichern dann nichts.

`send` braucht einen Kanal und eine Nachricht. `publish` braucht genau eines von message und template. Beide lehnen ein anderes Format als text oder html ab. Bei `publish` gilt das Format nur für eine Nachricht, denn eine gerenderte Vorlage ist immer HTML.

So probieren Sie eine Vorlage aus:

```bash
cv api4 Herald.renderTemplate workflow=herald_contribution_alert_completed \
    vars='{"contribution": {"total_amount": 50, "currency": "EUR"}}'
```

Die Entität `HeraldBufferedAlert` ist der Meldungspuffer. Administrator:innen können ihn mit get und getFields lesen und geparkte Meldungen mit reactivate zurückholen; alle drei erfordern „administer CiviCRM“. Die Aktionen update und delete sind herald selbst vorbehalten, sonst darf niemand sie aufrufen. Eine Aktion create gibt es nicht: Neue Zeilen schreibt herald selbst, damit der gespeicherte Nachrichtentext genau so bleibt, wie er gerendert wurde. Die Felder für Nachrichtentext und Zustellung können Kontaktdaten enthalten, deshalb lässt die mitgelieferte Anzeige der geparkten Meldungen sie weg.

Die Entitäten `HeraldDigest` und `HeraldDigestSource` enthalten die Reports und ihre Quellen, mit den üblichen Aktionen wie get, create, save und delete. Sie erfordern „administer CiviCRM“. Beim Speichern wird jeder Datensatz geprüft, siehe [Einen Report einrichten](digests.md#einen-report-einrichten), und das Löschen eines Reports löscht seine Quellen.

Eine andere Extension kann `send` und `publish` aufrufen, ohne fest von herald abzuhängen:

```php
if (class_exists(\Civi\Api4\Herald::class)) {
  civicrm_api4('Herald', 'publish', ['topic' => 'ops', 'message' => 'Import finished']);
}
```

## Geplante Jobs

| Job | Mit herald ausgeliefert | Häufigkeit | Was er tut |
|---|---|---|---|
| Herald: Sammelpuffer für Meldungen leeren | ja | Always (bei jedem Cron-Lauf) | Sendet die gesammelten Gruppen, deren Fenster abgelaufen ist. |
| Herald: Check-Monitor ausführen | ja | Always (bei jedem Cron-Lauf) | Führt den [Check-Monitor](monitor.md) aus. |
| Herald.notifyDigest | nein, legen Sie ihn selbst an | Hourly mit sendHour, oder Always mit sendHour und daily | Verschickt einen Report ohne Regel, siehe [Ohne Regel](digests.md#ohne-regel). |

Beide mitgelieferten Jobs setzen eine Sperre. Ein Lauf, der sich mit einem anderen überschneidet, sendet daher nicht doppelt.

## Vorlagen

Die Vorlagen von herald sind CiviCRM-Nachrichtenvorlagen. Sie bearbeiten sie unter „Mailings, Message Templates, System Workflow Messages“ (Mailings, Nachrichtenvorlagen, System-Workflow-Nachrichten). Sie werden als Smarty-Vorlagen gerendert.

| Titel | Workflow-Name | Verwendet für |
|---|---|---|
| Herald: Neuer abgeschlossener Beitrag | herald_contribution_alert_completed | Einen abgeschlossenen Beitrag |
| Herald: Neuer ausstehender Beitrag | herald_contribution_alert_pending | Einen ausstehenden Beitrag, etwa eine angekündigte Überweisung |
| Herald: Beitragsstatus geändert | herald_contribution_status_changed | Einen Statuswechsel, etwa von Pending zu Completed |
| Herald: Neuer Ticket-/Event-Beitrag | herald_contribution_alert_ticket | Einen Ticket- oder Event-Beitrag |
| Herald: Ticket bestellt, Überweisung angekündigt | herald_contribution_alert_ticket_pending | Ein per Überweisung bestelltes Ticket |
| Herald: Ticket-Überweisung eingegangen | herald_contribution_alert_ticket_paid | Eine eingegangene Ticket-Überweisung |
| Herald: Neues Event angelegt | herald_event_created_alert | Ein neues Event |
| Herald: Event deaktiviert | herald_event_deactivated_alert | Ein deaktiviertes Event |
| Herald: Event gelöscht | herald_event_deleted_alert | Ein gelöschtes Event |
| Herald: Neue Event-Registrierung | herald_event_registration_alert | Eine neue Registrierung, mit maskiertem Kontakt |
| Herald: Event-Registrierung storniert | herald_event_cancellation_alert | Eine stornierte Registrierung, mit maskiertem Kontakt |
| Herald: Neue Mitgliedschaftsanmeldung | herald_membership_signup_alert | Eine neue Mitgliedschaft, mit maskiertem Kontakt |
| Herald: Neue Gruppen-/Newsletter-Anmeldung | herald_group_subscription_alert | Einen Kontakt, der einer Gruppe hinzugefügt wurde, mit maskiertem Kontakt |
| Herald: Statuswechsel eines Monitor-Checks | herald_monitor_alert | Einen Wechsel im Check-Monitor. Variablen: check, status, detail, renotify |

Die Titel erscheinen in der Sprache, in der CiviCRM die Vorlagen angelegt oder zuletzt aktualisiert hat. Auf einer englischsprachigen Site heißt „Herald: Neuer abgeschlossener Beitrag“ zum Beispiel „Herald: New completed contribution“. Der Workflow-Name ist in jeder Sprache gleich.

Die Vorlagen sind auf Englisch geschrieben. Eine deutsche Übersetzung liegt bei und wird verwendet, wenn CiviCRM auf Deutsch läuft.

Eine Änderung, die Sie an einer mitgelieferten Vorlage vornehmen, bleibt erhalten, bis ein herald-Release genau diese Vorlage ändert. Das Release überschreibt dann Ihre Änderung. Um eine angepasste Fassung zu behalten, kopieren Sie sie unter einem eigenen Workflow-Namen. Das Formular für Nachrichtenvorlagen hat kein Feld für den Workflow-Namen. Legen Sie die Kopie dort im Reiter „User-driven Messages“ an und setzen Sie den Namen danach mit cv, etwa `cv api4 MessageTemplate.update +w id=123 +v workflow_name=herald_meine_meldung`. Die API und die geplanten Jobs akzeptieren jeden Workflow-Namen, die CiviRules-Formulare listen aber nur Namen, die mit `herald_` beginnen. Mit einem Namen wie `herald_meine_meldung` erscheint die Kopie also auch dort.

herald rendert nur die Standardfassung einer Vorlage. Nennt ein Aufruf eine Vorlage, die es nicht gibt, scheitert er mit dem Fehler `MessageTemplate "…" not found. Is the herald extension enabled?`. Eine Vorlage ohne HTML- und ohne Textinhalt scheitert ebenfalls.

### Maskierung

Vorlagen werden zu HTML gerendert. String-Variablen werden HTML-maskiert, bevor sie in den HTML-Inhalt gelangen, sodass Daten kein Markup einschleusen können. Der Betreff erhält sie unmaskiert. Eine Vorlage, die nur einen Textinhalt hat, wird gerendert und dann als Ganzes maskiert.

Jede Vorlage wird unter der Richtlinie von CiviCRM für Inhalte von Benutzer:innen gerendert, wie die Nachrichtenvorlagen von Mailings: `{crmAPI}`, PHP-Konstanten und Superglobals sind gesperrt, und PHP-Funktionen funktionieren nur in den wenigen Formen, die die Richtlinie erlaubt, etwa `|count`. Die eigenen Modifikatoren von Smarty und CiviCRM, etwa `|crmMoney`, `|crmDate` und `|round`, funktionieren wie gewohnt.

### Triggervariablen

„Send notification“ füllt diese Variablen aus dem Trigger, wenn der Trigger den Datensatz mitbringt:

| Variable | Inhalt |
|---|---|
| contact.id, contact.display_name, contact.email | Der Kontakt des Triggers. Die E-Mail ist die primäre Adresse, sofern es eine gibt. |
| contact.masked_name, contact.masked_email | Dasselbe, maskiert. Jedes Wort behält seine ersten beiden Zeichen, aus „Jane Doe“ wird also „Ja** Do*“. In der E-Mail werden der lokale Teil und jeder Teil der Domain auf dieselbe Weise maskiert, nur die Top-Level-Domain bleibt lesbar: aus jane@example.org wird ja**@ex*****.org. |
| group.id, group.title | Bei „Contact is added to Group“. |
| membership.membership_type, membership.status | Bei Mitgliedschafts-Triggern: der Name des Typs und die Bezeichnung des Status. |
| participant.role, participant.status | Bei Teilnahme-Triggern: die Bezeichnung der ersten Rolle und die Bezeichnung des Status. |
| event.id, event.title, event.start_date, event.is_active | Bei Event-Triggern. Teilnahme-Trigger liefern nur die ID und den Titel. |

Schlägt eine Abfrage fehl, protokolliert herald einen Fehler und lässt die Variable weg, statt die Benachrichtigung scheitern zu lassen. Bei einem gelöschten Kontakt oder einer gelöschten Gruppe bleibt die ID erhalten, die Textfelder sind leer. Ein gelöschtes Event behält die Werte aus den eigenen Daten des Triggers.

### Beitragsvariablen

„Send notification“ ergänzt diese Variablen, wenn der Trigger einen Beitrag mitbringt:

| Variable | Inhalt |
|---|---|
| contribution | Die Felder des Beitrags, etwa total_amount, currency, receive_date und source. Bereinigte Felder zeigen ihren bereinigten Wert, siehe [Felder bereinigen](digests.md#felder-bereinigen). |
| campaign.title, campaign.total, campaign.count | Die Kampagne des Beitrags: ihr Titel sowie Summe und Anzahl ihrer abgeschlossenen Beiträge. |
| campaign.goal, campaign.percent | Das Umsatzziel der Kampagne und der erreichte Anteil, auf eine Nachkommastelle gerundet. Beide sind 0, wenn die Kampagne kein Ziel hat. |
| from_status, to_status | Die Statusnamen vor und nach der Änderung. from_status ist leer, wenn der Status vor der Änderung unbekannt ist. |

Ohne Kampagne ist jedes Kampagnenfeld leer oder 0, sodass `{if $campaign.goal}` die Fortschrittszeile in einer Vorlage ausblendet.

Reports haben keine festen Variablen; ihre Quellen benennen sie, siehe [Platzhalter](digests.md#platzhalter).

## Einstellungen

Setzen Sie sie mit `cv setting:set name=value` oder `cv api4 Setting.set +v 'name=value'`. Einstellungen, die eine Liste oder eine Zuordnung enthalten, nehmen JSON auf. Die einfachen Einstellungen finden Sie auch auf der Einstellungsseite von herald, Administrieren » Herald » Einstellungen (`civicrm/admin/setting/herald`). Die Listen- und Zuordnungseinstellungen haben eigene Seiten unter `civicrm/admin/herald`: Kanäle, Topic-Routen, Bereinigte Felder, Erlaubte Webhook-Hosts und Monitor-Checks. Die Einstellungsseite verlinkt sie. Alle erfordern „administer CiviCRM“.

| Einstellung | Standard | Bedeutung |
|---|---|---|
| `herald_channels` | ein Kanal `email`, bei der Installation angelegt | Die [Kanäle](channels.md#einen-kanal-konfigurieren), Geheimnisse verschlüsselt. Bearbeiten Sie sie auf der Seite „Kanäle“ oder mit `Herald.saveChannel`, nicht mit `Setting.set`. |
| `herald_contact_email_channel` | email | Der E-Mail-Kanal, der Gruppenmitglieder ohne eigenen Kanal erreicht. |
| `herald_webhook_allowed_hosts` | leer | Webhook-Hosts, die erreicht werden dürfen. Ist die Liste leer, ist jede öffentliche Adresse erlaubt und jede private gesperrt. |
| `herald_topics` | leer | Die Routen der [Topics](delivery.md#topics). |
| `herald_buffer_max_attempts` | 5 | Fehlgeschlagene Zustellungen, bevor eine gesammelte Gruppe geparkt wird. |
| `herald_buffer_max_items` | 25 | Ausgegebene Nachrichten in einer Sammelnachricht. 0 gibt alle aus. |
| `herald_cleaned_fields` | leer | Textfelder, deren Werte Regeln unter einem Namen gruppieren, siehe [Felder bereinigen](digests.md#felder-bereinigen). |
| `herald_monitor_topic` | ops | Das Topic für Meldungen des Monitors. |
| `herald_monitor_open_after`, `herald_monitor_close_after`, `herald_monitor_renotify_hours`, `herald_monitor_snooze`, `herald_monitor_expected` | siehe [Feinabstimmung](monitor.md#feinabstimmung) | Feinabstimmung des Monitors. |
| `herald_monitor_state` | leer | Der eigene Zustand des Monitors. Nicht bearbeiten. |

## Hooks

| Hook | Signatur | Zweck |
|---|---|---|
| `hook_civicrm_heraldChecks` | `(array &$checks)` | Checks zum Monitor hinzufügen, siehe [Eigene Checks bereitstellen](monitor.md#eigene-checks-bereitstellen). |
| `hook_civicrm_heraldDigestSections` | `(array $keys, array $context, array &$sections)` | Die Werte für die Abschnittsquellen eines Reports liefern, siehe [Abschnitte aus anderen Extensions](digests.md#abschnitte-aus-anderen-extensions). |

## Systemstatus

Solange ein Deploy die Regeln von herald abgeschaltet hat, zeigt der Systemstatus von CiviCRM die Warnung „Herald: Regeln durch einen Deploy abgeschaltet“. Sie nennt die IDs der Regeln und den Befehl, mit dem Sie sie wieder einschalten. Siehe [Deployment](deploy.md).

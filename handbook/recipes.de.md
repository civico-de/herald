---
title: Rezepte
order: 10
---

# Rezepte

Hier finden Sie vollständige Einrichtungen für häufige Meldungen. Sie bestehen aus CiviRules-Regeln und geplanten Jobs in der CiviCRM-Oberfläche und kommen ohne Code aus.

Regeln verwalten Sie unter „Administer, CiviRules, Manage Rules“ (Administrieren, CiviRules, Regeln verwalten). Die Namen der Trigger und Bedingungen unten stammen aus CiviRules und sind mit ihrer englischen Bezeichnung angegeben. Aktionen und Bedingungen, deren Name mit „Herald:“ beginnt, bringt herald mit. Jede herald-Aktion hat ein Formular, in dem die Vorlagen nach ihrem Titel aufgelistet sind.

Alle Rezepte senden an Telegram. Lassen Sie die Chat-ID leer, dann gilt der Standard-Chat aus den Einstellungen. Jedes Rezept funktioniert auch mit jedem anderen Kanal: Wählen Sie ihn im Formular und tragen Sie seine Schlüssel in „Kanalspezifische Konfiguration“ ein, siehe [Kanäle](channels.md).

Die Beitragsstatus heißen in CiviCRM unter anderem Completed (abgeschlossen), Pending (ausstehend) und Cancelled (storniert). Die Tabellen verwenden die englischen Bezeichnungen aus CiviRules.

## Spenden

Vier Regeln decken den Weg einer Spende ab: sofort bezahlt, als Überweisung angekündigt, später eingegangen und eine Zusammenfassung jeden Morgen.

| Regel | Trigger | Bedingungen | Vorlage |
|---|---|---|---|
| Sofort bezahlt | Contribution is added | Contribution Status is (not) one of: Completed | Herald: Neuer abgeschlossener Beitrag |
| Überweisung angekündigt | Contribution is added | Contribution Status is (not) one of: Pending, und Contribution is (not) of Financial Type(s): Donation | Herald: Neuer ausstehender Beitrag |
| Überweisung eingegangen | Contribution is changed | Herald: Field transition, siehe unten, und Contribution Status is (not) one of: Completed | Herald: Beitragsstatus geändert |
| Tägliche Zusammenfassung | Herald: Schedule, siehe [Geplante Reports](#geplante-reports) | | Herald: Täglicher Beitragsreport, im Beitragsblock des Reports |

Die ersten drei verwenden die Aktion „Herald: Send notification (generic)“, die tägliche Zusammenfassung „Herald: Send digest“.

Die Bedingung auf den Finanztyp bei „Überweisung angekündigt“ ist wichtig, wenn die Site Tickets verkauft. Eine ausstehende Event-Bestellung ist ebenfalls ein ausstehender Beitrag und würde sonst als Spende angekündigt. Für Tickets gibt es weiter unten eigene Regeln.

„Überweisung eingegangen“ verwendet die herald-Bedingung „Herald: Field transition“ mit diesen Werten:

| Feld | Wert |
|---|---|
| Entität | Contribution |
| Feld | contribution_status_id:name |
| Vorher | = Pending |
| Nachher | beliebiger Wert |

Die Bedingung liest den Status vor der Änderung aus der Kopie, die CiviRules vom Datensatz aufbewahrt, und funktioniert daher auch mit verzögerten Aktionen. Ein benutzerdefiniertes Feld braucht dort eine zusätzliche Einstellung, siehe [Feldwechsel](reference.md#feldwechsel).

Der Fortschritt einer Kampagne braucht keine Einrichtung. Ein Beitrag, der zu einer Kampagne gehört, bringt Titel, Summe und Anzahl der Kampagne mit. Hat die Kampagne ein Umsatzziel, kommen das Ziel und der erreichte Prozentsatz hinzu. Siehe [Beitragsvariablen](reference.md#beitragsvariablen).

### Beiträge aus einem Bankimport

Ein Bankimport, der Beiträge im Status Completed anlegt, würde für jede gebuchte Transaktion „Sofort bezahlt“ auslösen. Wenn die importierten Beiträge eine erkennbare Quelle tragen, schließen Sie sie mit einer zweiten Bedingung an dieser Regel aus:

| Feld im CiviRules-Formular | Wert |
|---|---|
| Condition (Bedingung) | Field Value Comparison |
| Entity (Entität) | Contribution |
| Field (Feld) | source |
| Operator | Does not contain string (case insensitive) |
| Value (Wert) | Bank TX |

Dieselbe Quelle kann mit dem umgekehrten Operator auch eine eigene Regel steuern.

Damit solche Quellen in Meldungen und Reports unter einer lesbaren Bezeichnung erscheinen, richten Sie die [Normalisierung der Quellen](digests.md#quellen-normalisieren) ein.

## Ticketverkauf

| Regel | Trigger | Bedingungen | Vorlage |
|---|---|---|---|
| Ticket online bezahlt | Contribution is added | Finanztyp ist der Ticket-Typ, Status ist Completed | Herald: Neuer Ticket-/Event-Beitrag |
| Ticket per Überweisung bestellt | Contribution is added | Finanztyp ist der Ticket-Typ, Status ist Pending | Herald: Ticket bestellt, Überweisung angekündigt |
| Ticket-Überweisung eingegangen | Contribution is changed | Herald: Field transition auf contribution_status_id:name mit Vorher = Pending, Finanztyp ist der Ticket-Typ, Status ist Completed | Herald: Ticket-Überweisung eingegangen |

Alle drei verwenden „Herald: Send notification (generic)“. Nach einer Ankündigung kommen Verkäufe gehäuft, setzen Sie daher das „Sammelfenster in Minuten“ auf 5. Sieben Verkäufe innerhalb des Fensters erscheinen dann als eine Nachricht mit der Kopfzeile „📬 7 Meldungen, gesamt 385,00 € (gesammelt über 5 min)“, gefolgt von den einzelnen Verkäufen. Siehe [Sammelnachrichten](delivery.md#sammelnachrichten).

## Lebenszyklus von Events

Diese Regeln helfen, wenn Events nicht von Hand, sondern per Synchronisation gepflegt werden und ein neues oder verschwundenes Event sonst unbemerkt bliebe. Alle verwenden „Herald: Send notification (generic)“.

| Regel | Trigger | Bedingungen | Vorlage |
|---|---|---|---|
| Event angelegt | Event is added | keine | Herald: Neues Event angelegt |
| Event deaktiviert | Event is changed | Herald: Field transition, Entität Event, Feld is_active, Vorher = 1, Nachher = 0 | Herald: Event deaktiviert |
| Event gelöscht | Event is deleted | keine | Herald: Event gelöscht |
| Neue Registrierung | Event Participant is added | Participant status is (not) one of: Registered | Herald: Neue Event-Registrierung |
| Registrierung storniert | Event Participant is changed | Compare Old Participant Status to New Participant Status: von Registered oder Pending from pay later nach Cancelled | Herald: Event-Registrierung storniert |

Eine Synchronisation nimmt ein Event meist offline, indem sie es deaktiviert statt löscht. Deshalb erkennt die Regel „Event deaktiviert“ ein entferntes Event. Bauen Sie sie mit der herald-Bedingung Field transition, nicht mit einer Field Value Comparison auf is_active. Eine Field Value Comparison sieht nur den Wert nach der Änderung und löst deshalb auch bei jeder anderen Änderung eines bereits inaktiven Events aus. Field transition löst nur aus, wenn der Wert tatsächlich von 1 auf 0 wechselt.

Abgebrochene Bestellvorgänge wechseln von einer unvollständigen Transaktion zu Cancelled. Wenn Sie den alten Status der Stornoregel einschränken, lösen sie keine Meldung aus. Wenn Sie „Neue Registrierung“ auf Registered beschränken, bleiben Wartelisten- und ausstehende Einträge außen vor.

Eine online bezahlte Registrierung beginnt als „Pending from incomplete transaction“ (ausstehend wegen unvollständiger Transaktion) und wird zu Registered, sobald die Zahlung durch ist. Um sie zu erfassen, legen Sie eine zweite Regel auf „Event Participant is changed“ an, mit „Compare Old Participant Status to New Participant Status“ von „Pending from incomplete transaction“ nach Registered, und verwenden dieselbe Vorlage.

Die Vorlagen für Registrierung, Stornierung und Mitgliedschaft zeigen den Kontakt mit maskiertem Namen, etwa „Ja** Do*“. Die Newsletter-Vorlage ergänzt eine maskierte E-Mail-Adresse. Das reicht, um in einem Gruppenchat eine Anmeldung zu erkennen oder Spam zu bemerken, ohne preiszugeben, um wen es sich handelt.

## Mitgliedschaften und Newsletter-Anmeldungen

Beide verwenden „Herald: Send notification (generic)“.

| Regel | Trigger | Bedingungen | Vorlage |
|---|---|---|---|
| Neue Mitgliedschaft | Membership is added | Optional der Mitgliedschaftstyp | Herald: Neue Mitgliedschaftsanmeldung |
| Newsletter-Anmeldung | Contact is added to Group | Group is: die Newsletter-Gruppen | Herald: Neue Gruppen-/Newsletter-Anmeldung |

Anmeldungen kommen nach einem Mailing oder einem Import gehäuft. Ein Sammelfenster von 5 bis 15 Minuten hält den Chat lesbar.

## Geplante Reports

Ein Report, der zu einer festen Zeit verschickt wird, ist eine CiviRules-Regel mit dem Trigger „Herald: Schedule“ und der Aktion „Herald: Send digest“. Der Trigger löst einmal zur gewählten Stunde aus und bezieht sich auf keinen Kontakt. Die Regel verschickt also einen Report und nicht einen pro Datensatz.

Ein wöchentlicher Beitragsreport jeden Montag um 8:00 Uhr:

1. Legen Sie unter Administrieren » Herald » Reports einen Report mit dem Titel „Weekly contributions“, dem Namen `weekly_contributions`, dem Zeitraum „Letzte 7 Tage“ und einem Beitragsblock mit der Vorlage „Herald: Wöchentlicher Beitragsreport“ an.
2. Legen Sie eine CiviRules-Regel an und wählen Sie den Trigger „Herald: Schedule“.
3. Wählen Sie im Formular des Triggers unter „Wochentage“ den Montag und unter „Uhrzeit“ 08:00. Bleibt „Wochentage“ leer, geht der Report jeden Tag hinaus. Die Uhrzeit gilt in der Zeitzone von CiviCRM.
4. Fügen Sie die Aktion „Herald: Send digest“ hinzu, wählen Sie den Report, und setzen Sie den Kanal und bei Telegram die Chat-ID.

Die Regelliste zeigt den Zeitplan als „Jeden Montag um 08:00“ und die Aktion als `Verschickt den Report „Weekly contributions“.` Für einen täglichen Report lassen Sie „Wochentage“ leer und geben dem Report den Zeitraum „Gestern“ und die Vorlage „Herald: Täglicher Beitragsreport“.

Der Trigger wird bei jedem Lauf des geplanten Jobs von CiviRules geprüft, der Report geht also mit dem ersten Cron-Lauf nach der vollen Stunde hinaus. Ob ein Termin schon verschickt wurde, steht im Protokoll der Regel selbst:

- Verpasst Cron die Stunde, holt der nächste Lauf sie nach. Läuft Cron alle 15 Minuten, fällt aber zwischen 7:45 Uhr und 9:00 Uhr aus, geht der Report für 8 Uhr um 9:00 Uhr hinaus.
- Nach einem längeren Ausfall wird nur der jüngste Termin verschickt. Drei verpasste Montage ergeben einen Report, nicht drei.
- Eine neue Regel oder eine, deren Zeitplan gerade gespeichert wurde, verschickt nie einen Termin von vor diesem Zeitpunkt.
- Ein Lauf zählt auch, wenn das Senden fehlschlägt, etwa weil der Kanal nicht eingerichtet ist. Der Fehler wird protokolliert, und der nächste Report geht zum nächsten Termin hinaus.

CiviCRM führt geplante Jobs, auch den von CiviRules, nur in der Umgebung Production aus.

Wie Sie einen Report und seine Blöcke einrichten, beschreibt die Seite [Reports](digests.md).

### Ohne Regel

Skripte und die Crontab des Servers können `Herald.notifyDigest` direkt aufrufen, zu einer festen Uhrzeit:

```
0 8 * * 1 cd /var/www/example.org && cv api4 Herald.notifyDigest '{"checkPermissions": false, "digest": "weekly_contributions", "actionParams": {"chat_id": "-1001234567890"}}'
```

Dieser Aufruf ist nicht auf die Umgebung Production beschränkt.

Ein geplanter Job von CiviCRM für `Herald.notifyDigest`, auf Hourly (stündlich) gestellt und mit `"sendHour": 8` in den Parametern, funktioniert ebenfalls. Er kann aber keine Wochentage wählen und kann einen Tag auslassen: CiviCRM schiebt den nächsten Lauf jedes Jobs um bis zu ein Cron-Intervall nach hinten, Rutscht der Job dadurch über die ganze Stunde hinweg, fällt an diesem Tag kein Lauf in sie.

## Benachrichtigungen pro Person

Wenn mehrere Mitarbeitende unterschiedliche Benachrichtigungen über ihre eigenen Kanäle erhalten möchten, verwenden Sie den Abonnent:innen-Modus statt einer Regel pro Person. Jeder Kontakt wählt einen Kanal und die Arten von Benachrichtigungen, die er möchte, und eine einzige Regel erreicht alle Abonnent:innen. Die Einrichtung und das Profil zur Selbstverwaltung beschreibt der Abschnitt [Abonnements](delivery.md#abonnements).

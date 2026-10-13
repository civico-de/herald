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

Vier Regeln decken den Weg einer Spende ab: sofort bezahlt, als Überweisung angekündigt und später eingegangen.

| Regel | Trigger | Bedingungen | Vorlage |
|---|---|---|---|
| Sofort bezahlt | Contribution is added | Contribution Status is (not) one of: Completed | Herald: Neuer abgeschlossener Beitrag |
| Überweisung angekündigt | Contribution is added | Contribution Status is (not) one of: Pending, und Contribution is (not) of Financial Type(s): Donation | Herald: Neuer ausstehender Beitrag |
| Überweisung eingegangen | Contribution is changed | Herald: Field transition, siehe unten, und Contribution Status is (not) one of: Completed | Herald: Beitragsstatus geändert |
| Tägliche Zusammenfassung | Ein geplanter Job, siehe [Geplante Reports](#geplante-reports) | | Herald: Täglicher Beitragsreport |

Alle verwenden die Aktion „Herald: Send notification (generic)“.

Die Bedingung auf den Finanztyp bei „Überweisung angekündigt“ ist wichtig, wenn die Site Tickets verkauft. Eine ausstehende Event-Bestellung ist ebenfalls ein ausstehender Beitrag und würde sonst als Spende angekündigt. Für Tickets gibt es weiter unten eigene Regeln.

„Überweisung eingegangen“ verwendet die herald-Bedingung „Herald: Field transition“ mit diesen Werten:

| Feld | Wert |
|---|---|
| Entität | Contribution |
| Feld | contribution_status_id |
| Vorher | = 2, die ID von Pending auf einer Standardinstallation |
| Nachher | beliebiger Wert |

Die Bedingung liest den Status vor der Änderung aus den Daten, die CiviCRM vor der Änderung hatte. Sie funktioniert auch mit verzögerten Aktionen.

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
| Ticket-Überweisung eingegangen | Contribution is changed | Herald: Field transition auf contribution_status_id mit Vorher = 2, Finanztyp ist der Ticket-Typ, Status ist Completed | Herald: Ticket-Überweisung eingegangen |

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

Abgebrochene Bestellvorgänge wechseln von einer unvollständigen Transaktion zu Cancelled. Wenn Sie den alten Status der Stornoregel einschränken, bleiben sie still. Wenn Sie „Neue Registrierung“ auf Registered beschränken, bleiben Wartelisten- und ausstehende Einträge außen vor.

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

Ein Report, der einmal täglich verschickt wird, ist ein geplanter Job und keine Regel. Die zeitgesteuerten Trigger von CiviRules lösen für jeden passenden Kontakt oder Datensatz einzeln aus, und einen einfachen Trigger für eine Uhrzeit hat CiviRules nicht.

Legen Sie den Job unter „Administer, System Settings, Scheduled Jobs“ (Administrieren, Systemeinstellungen, geplante Jobs) an:

| Feld | Wert |
|---|---|
| API entity | Herald |
| API action | notifyDigest |
| Run frequency (Ausführungshäufigkeit) | Hourly (stündlich) |
| Parameters | siehe unten |

```json
{
  "version": 4,
  "checkPermissions": false,
  "sendHour": 8,
  "actionParams": {
    "template": "herald_contribution_digest_daily",
    "period": "yesterday",
    "campaign_id": 2,
    "chat_id": "-1001234567890"
  }
}
```

- `"version": 4` führt den Job über APIv4 aus.
- `"checkPermissions": false` lässt ihn ohne Anmeldung laufen, so wie Cron ihn startet.
- `sendHour` sorgt dafür, dass der stündliche Job nur in dieser Stunde sendet, in der Zeitzone von CiviCRM.

Für einen wöchentlichen Report verwenden Sie die Vorlage `herald_contribution_digest_weekly` mit `"period": "last_7_days"`. Um einen Report von Hand auszulösen, nutzen Sie „Execute now“ (jetzt ausführen) an einer Kopie des Jobs ohne `sendHour` oder rufen `Herald.notifyDigest` direkt auf.

CiviCRM führt geplante Jobs nur in der Umgebung Production aus. Auf einer Testsite ergänzen Sie `"runInNonProductionEnvironment": true` neben `"version": 4`.

Alle Report-Einstellungen und die Blocktypen beschreibt die Seite [Reports](digests.md).

### Die Ausführungshäufigkeit wählen

CiviCRM plant einen Job mit Hourly eine Stunde nach dem Start seines vorigen Laufs ein, einen Job mit Daily einen Tag danach. Der Job läuft dann beim ersten Cron-Lauf nach diesem Zeitpunkt. Die Laufzeit verschiebt sich dadurch langsam nach hinten, jeweils um bis zu ein Cron-Intervall.

Ein Job mit Daily wandert deshalb durch den Tag. Ein Job mit Hourly und `sendHour` hält die Stunde an den meisten Tagen. Trägt ihn die Verschiebung jedoch über eine volle Stunde hinweg, fällt in dieser Stunde kein Lauf an, und an diesem Tag wird der Report nicht verschickt. Läuft Cron alle 15 Minuten, überspringt ein Lauf um 7:45 Uhr, auf den einer um 9:00 Uhr folgt, die 8-Uhr-Stunde.

Wenn der Report wirklich jeden Tag verschickt werden muss, starten Sie ihn statt über einen geplanten Job von CiviCRM aus der Crontab des Servers, zu einer festen Uhrzeit und ohne `sendHour`:

```
0 8 * * * cd /var/www/example.org && cv api4 Herald.notifyDigest '{"checkPermissions": false, "actionParams": {"template": "herald_contribution_digest_daily", "period": "yesterday"}}'
```

Dieser Aufruf ist nicht auf die Umgebung Production beschränkt.

## Benachrichtigungen pro Person

Wenn mehrere Mitarbeitende unterschiedliche Benachrichtigungen über ihre eigenen Kanäle erhalten möchten, verwenden Sie den Abonnent:innen-Modus statt einer Regel pro Person. Jeder Kontakt wählt einen Kanal und die Arten von Benachrichtigungen, die er möchte, und eine einzige Regel erreicht alle Abonnent:innen. Die Einrichtung und das Profil zur Selbstverwaltung beschreibt der Abschnitt [Abonnements](delivery.md#abonnements).

---
title: Rezepte
order: 10
---

# Rezepte

Diese Seite zeigt vollständige Beispiele für häufige Meldungen. Sie bestehen aus CiviRules-Regeln und geplanten Jobs in der CiviCRM-Oberfläche und kommen ohne Code aus.

Regeln verwalten Sie unter Administrieren » CiviRules » Regeln verwalten (englisch: Administer » CiviRules » Manage Rules). Die Namen der Trigger und Bedingungen unten stammen aus CiviRules und sind mit ihrer englischen Bezeichnung angegeben. Aktionen und Bedingungen, deren Name mit „Herald:“ beginnt, bringt herald mit. Jede herald-Aktion hat ein Formular, in dem die Vorlagen nach ihrem Titel aufgelistet sind.

Alle Rezepte senden an Telegram. Lassen Sie die Chat-ID leer, dann gilt der Standard-Chat aus den Einstellungen. Jedes Rezept funktioniert auch mit jedem anderen Kanal: Wählen Sie ihn im Formular und tragen Sie seine Schlüssel in „Kanalspezifische Konfiguration“ ein, siehe [Kanäle](channels.md).

Die Beitragsstatus heißen in CiviCRM unter anderem Completed (abgeschlossen), Pending (ausstehend) und Cancelled (storniert). Die Tabellen verwenden die englischen Bezeichnungen aus CiviRules.

## Spenden

Drei Regeln begleiten eine Spende: sofort bezahlt, als Überweisung angekündigt, später eingegangen. Eine vierte verschickt jeden Morgen eine Zusammenfassung.

| Regel | Trigger | Bedingungen | Vorlage |
|---|---|---|---|
| Sofort bezahlt | Contribution is added | Contribution Status is (not) one of: Completed | Herald: Neuer abgeschlossener Beitrag |
| Überweisung angekündigt | Contribution is added | Contribution Status is (not) one of: Pending, und Contribution is (not) of Financial Type(s): Donation | Herald: Neuer ausstehender Beitrag |
| Überweisung eingegangen | Contribution is changed | Herald: Field transition, siehe unten, und Contribution Status is (not) one of: Completed | Herald: Beitragsstatus geändert |
| Tägliche Zusammenfassung | Herald: Schedule, siehe [Geplante Reports](#geplante-reports) | | Ein Report mit der Anzeige „Report: Beiträge gestern“ |

Die ersten drei verwenden die Aktion „Herald: Send notification (generic)“, die tägliche Zusammenfassung „Herald: Send digest“.

Die Bedingung auf den Finanztyp bei „Überweisung angekündigt“ ist wichtig, wenn die Site Tickets verkauft. Eine ausstehende Event-Bestellung ist ebenfalls ein ausstehender Beitrag und würde sonst als Spende angekündigt. Für Tickets gibt es weiter unten eigene Regeln.

„Überweisung eingegangen“ verwendet die herald-Bedingung „Herald: Field transition“ mit diesen Werten:

| Feld | Wert |
|---|---|
| Entität | Contribution |
| Feld | contribution_status_id:name |
| Vorher | = Pending |
| Nachher | beliebiger Wert |

Die Bedingung liest den Status vor der Änderung aus der Kopie, die CiviRules vom Datensatz aufbewahrt, und funktioniert daher auch mit verzögerten Aktionen. Bei einem benutzerdefinierten Feld braucht eine verzögerte Aktion eine zusätzliche Einstellung, siehe [Feldwechsel](reference.md#feldwechsel).

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

### Quellen normalisieren

Beitragsquellen sind oft technisch, etwa die Referenz eines Zahlungsanbieters oder das Transaktionspräfix eines Bankimports. Die Einstellung `herald_source_normalization` ordnet ihnen lesbare Bezeichnungen zu. Beitragsbenachrichtigungen zeigen sie als Quelle. Standardmäßig ist die Einstellung leer, und die Quellen bleiben, wie sie sind.

Die Regeln finden Sie unter Administrieren » Herald » Namen der Beitragsquellen. Jede Zeile enthält den Quelltext, die Art des Vergleichs und den anzuzeigenden Namen. Die Pfeil-Schaltflächen ändern die Reihenfolge, in der die Regeln geprüft werden. Beim Speichern lehnt die Seite unvollständige Regeln und reguläre Ausdrücke ab, die sich nicht kompilieren lassen, und nennt die Nummer der Regel.

Mit cv:

```bash
cv api4 --in=json Setting.set <<'JSON'
{"values": {"herald_source_normalization": [
  {"match": "Bank TX", "type": "contains", "label": "Bank transfer"},
  {"match": "Transfer", "type": "prefix", "label": "Bank transfer"},
  {"match": "^pi_[A-Za-z0-9]+$", "type": "regex", "label": "Stripe"}
]}]}
JSON
```

- Die Regeln werden der Reihe nach geprüft, und der erste Treffer gewinnt.
- Der Typ ist contains, prefix oder regex. Bei contains und prefix zählt die Groß- und Kleinschreibung.
- Ein regulärer Ausdruck verwendet die preg-Syntax von PHP, einschließlich der Begrenzungszeichen.
- Eine fehlerhafte Regel, die in die Einstellung gelangt, wird ignoriert, sodass ein Tippfehler keine Meldung verhindern kann. Die Seite und `Herald.saveSourceRules` lehnen sie ab, bevor sie gespeichert wird.

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

Eine Synchronisation deaktiviert ein Event meist, statt es zu löschen. Deshalb erkennt die Regel „Event deaktiviert“ ein entferntes Event. Bauen Sie sie mit der herald-Bedingung Field transition, nicht mit einer Field Value Comparison auf is_active. Eine Field Value Comparison sieht nur den Wert nach der Änderung und löst deshalb auch bei jeder anderen Änderung eines bereits inaktiven Events aus. Field transition löst nur aus, wenn der Wert tatsächlich von 1 auf 0 wechselt.

Abgebrochene Bestellvorgänge wechseln von einer unvollständigen Transaktion zu Cancelled. Die Stornoregel lässt als alten Status nur Registered und Pending from pay later zu, deshalb lösen sie keine Meldung aus. Weil „Neue Registrierung“ auf Registered beschränkt ist, bleiben Wartelisten- und ausstehende Einträge außen vor.

Eine online bezahlte Registrierung beginnt als „Pending from incomplete transaction“ (ausstehend wegen unvollständiger Transaktion) und wird zu Registered, sobald die Zahlung durch ist. Um sie zu erfassen, legen Sie eine zweite Regel auf „Event Participant is changed“ an, mit „Compare Old Participant Status to New Participant Status“ von „Pending from incomplete transaction“ nach Registered, und verwenden dieselbe Vorlage.

## Mitgliedschaften und Newsletter-Anmeldungen

Beide verwenden „Herald: Send notification (generic)“.

| Regel | Trigger | Bedingungen | Vorlage |
|---|---|---|---|
| Neue Mitgliedschaft | Membership is added | Optional der Mitgliedschaftstyp | Herald: Neue Mitgliedschaftsanmeldung |
| Newsletter-Anmeldung | Contact is added to Group | Group is: die Newsletter-Gruppen | Herald: Neue Gruppen-/Newsletter-Anmeldung |

Die Vorlagen für Registrierung, Stornierung, Mitgliedschaft und Newsletter zeigen den Kontakt mit maskiertem Namen, etwa „Ja** Do*“, die Newsletter-Vorlage zusätzlich mit maskierter E-Mail-Adresse. Das reicht, um in einem Gruppenchat eine Anmeldung zu erkennen oder Spam zu bemerken, ohne preiszugeben, um wen es sich handelt.

Anmeldungen kommen nach einem Mailing oder einem Import gehäuft. Ein Sammelfenster von 5 bis 15 Minuten hält den Chat lesbar.

## Geplante Reports

Ein Report, der zu einer festen Zeit verschickt wird, ist eine CiviRules-Regel mit dem Trigger „Herald: Schedule“ und der Aktion „Herald: Send digest“. Der Trigger löst einmal zur gewählten Stunde aus und bezieht sich auf keinen Kontakt. Die Regel verschickt also einen Report und nicht einen pro Datensatz.

Ein wöchentlicher Beitragsreport jeden Montag um 8:00 Uhr:

1. Legen Sie unter Administrieren » Herald » Reports einen Report mit dem Titel „Wöchentliche Beiträge“ und dem Namen `weekly_contributions` an. Geben Sie ihm eine Quelle `spenden` mit der Anzeige „Report: Beiträge letzte Woche“, kopieren Sie den Textbaustein aus dem Formular in den Text und prüfen Sie die Vorschau.
2. Legen Sie eine CiviRules-Regel an und wählen Sie den Trigger „Herald: Schedule“.
3. Wählen Sie im Formular des Triggers unter „Wochentage“ den Montag und unter „Uhrzeit“ 08:00. Bleibt „Wochentage“ leer, wird der Report jeden Tag verschickt. Die Uhrzeit gilt in der Zeitzone von CiviCRM.
4. Fügen Sie die Aktion „Herald: Send digest“ hinzu, wählen Sie den Report, und setzen Sie den Kanal und bei Telegram die Chat-ID.

Die Regelliste zeigt den Zeitplan als „Jeden Montag um 08:00“ und die Aktion als `Verschickt den Report „Wöchentliche Beiträge“.` Für einen täglichen Report lassen Sie „Wochentage“ leer und verwenden die Anzeige „Report: Beiträge gestern“.

CiviRules prüft den Trigger bei jedem Lauf seines geplanten Jobs. Der Report wird also mit dem ersten Cron-Lauf nach der vollen Stunde verschickt. Ob ein Termin schon verschickt wurde, steht im Protokoll der Regel selbst:

- Verpasst Cron die Stunde, holt der nächste Lauf sie nach. Läuft Cron alle 15 Minuten, fällt aber zwischen 7:45 Uhr und 9:00 Uhr aus, wird der Report für 8 Uhr um 9:00 Uhr verschickt.
- Nach einem längeren Ausfall wird nur der jüngste Termin verschickt. Drei verpasste Montage ergeben einen Report, nicht drei.
- Eine neue Regel oder eine, deren Zeitplan gerade gespeichert wurde, verschickt nie einen Termin von vor diesem Zeitpunkt.
- Ein Lauf zählt auch, wenn das Senden fehlschlägt, etwa weil der Kanal nicht eingerichtet ist. Der Fehler wird protokolliert, und der nächste Report wird zum nächsten Termin verschickt.

CiviCRM führt geplante Jobs, auch den von CiviRules, nur in der Umgebung Production aus.

Wie Sie einen Report und seine Quellen einrichten, beschreibt die Seite [Reports](digests.md).

### Ohne Regel

Skripte und die Crontab des Servers können `Herald.notifyDigest` direkt aufrufen, zu einer festen Uhrzeit:

```
0 8 * * 1 cd /var/www/example.org && cv api4 Herald.notifyDigest '{"checkPermissions": false, "digest": "weekly_contributions", "actionParams": {"chat_id": "-1001234567890"}}'
```

Dieser Aufruf ist nicht auf die Umgebung Production beschränkt.

Ein geplanter Job von CiviCRM für `Herald.notifyDigest`, auf Hourly (stündlich) gestellt und mit `"sendHour": 8` in den Parametern, funktioniert ebenfalls. Er kann aber keine Wochentage wählen und kann einen Tag auslassen. Ein stündlicher Job läuft erst wieder, wenn seit seinem letzten Lauf eine volle Stunde vergangen ist. Läuft Cron alle 15 Minuten und lief der Job um 7:45:05 Uhr, kommt der Cron-Lauf um 8:45 Uhr ein paar Sekunden zu früh. Der Job läuft erst um 9:00 Uhr wieder, und die Stunde ab 8 Uhr bekommt an diesem Tag keinen Lauf.

## Benachrichtigungen pro Person

Wenn mehrere Mitarbeitende unterschiedliche Benachrichtigungen über ihre eigenen Kanäle erhalten möchten, verwenden Sie den Abonnent:innen-Modus statt einer Regel pro Person. Jeder Kontakt wählt einen Kanal und die Arten von Benachrichtigungen, die er möchte, und eine einzige Regel erreicht alle Abonnent:innen. Die Einrichtung und das Profil zur Selbstverwaltung beschreibt der Abschnitt [Abonnements](delivery.md#abonnements).

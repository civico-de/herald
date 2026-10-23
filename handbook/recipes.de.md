---
title: Rezepte
order: 10
---

# Rezepte

Diese Seite zeigt vollständige Beispiele für häufige Meldungen. Sie bestehen aus CiviRules-Regeln und geplanten Jobs in der CiviCRM-Oberfläche und kommen ohne Code aus.

Regeln verwalten Sie unter Administrieren » CiviRules » Regeln verwalten (englisch: Administer » CiviRules » Manage Rules). Die Namen der Trigger und Bedingungen unten stammen aus CiviRules und sind mit ihrer englischen Bezeichnung angegeben. Aktionen und Bedingungen, deren Name mit „Herald:“ beginnt, bringt herald mit. Jede herald-Aktion hat ein Formular, in dem die Vorlagen nach ihrem Titel aufgelistet sind.

Alle Rezepte senden über einen Telegram-Kanal, siehe [Einen Kanal konfigurieren](channels.md#einen-kanal-konfigurieren). Lassen Sie die Chat-ID leer, dann gilt der Standard-Chat des Kanals. Jedes Rezept funktioniert auch mit jedem anderen Kanal: Wählen Sie ihn im Formular, und das Formular zeigt die Felder seines Typs. Soll ein Thema über Kanal und Ziel entscheiden, setzen Sie stattdessen „Senden“ auf „Über ein Thema“.

Die Beitragsstatus heißen in CiviCRM unter anderem Completed (abgeschlossen), Pending (ausstehend) und Cancelled (storniert). Die Tabellen verwenden die englischen Bezeichnungen aus CiviRules.

## Spenden

Drei Regeln begleiten eine Spende: sofort bezahlt, als Überweisung angekündigt, später eingegangen. Eine vierte verschickt jeden Morgen eine Zusammenfassung.

| Regel | Trigger | Bedingungen | Vorlage |
|---|---|---|---|
| Sofort bezahlt | Contribution is added | Contribution Status is (not) one of: Completed | Herald: Neuer abgeschlossener Beitrag |
| Überweisung angekündigt | Contribution is added | Contribution Status is (not) one of: Pending, und Contribution is (not) of Financial Type(s): Donation | Herald: Neuer ausstehender Beitrag |
| Überweisung eingegangen | Contribution is changed | Compare Old Contribution Status to New Contribution Status: von Pending zu Completed | Herald: Beitragsstatus geändert |
| Tägliche Zusammenfassung | Herald: Schedule, siehe [Geplante Reports](digests.md#geplante-reports) | | Ein Report mit der Anzeige „Report: Beiträge gestern“ |

Die ersten drei verwenden die Aktion „Herald: Send notification (generic)“, die tägliche Zusammenfassung „Herald: Send digest“.

Die Bedingung auf den Finanztyp bei „Überweisung angekündigt“ ist wichtig, wenn die Site Tickets verkauft. Eine ausstehende Event-Bestellung ist ebenfalls ein ausstehender Beitrag und würde sonst als Spende angekündigt. Für Tickets gibt es weiter unten eigene Regeln.

„Überweisung eingegangen“ verwendet eine Bedingung, die CiviRules mitbringt. Sie vergleicht den Status vor der Änderung mit dem danach und feuert daher einmal, wenn eine Überweisung gebucht wird, nicht bei späteren Änderungen des Beitrags. Für Felder, für die CiviRules keine solche Bedingung hat, ergänzt herald „Herald: Field transition“, siehe [Lebenszyklus von Events](#lebenszyklus-von-events).

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

Dieselbe Quelle kann mit dem umgekehrten Operator auch eine eigene Regel steuern. Um diese Beiträge in einem Report getrennt zu zählen, geben Sie der Quelle ein [bereinigtes Feld](digests.md#felder-bereinigen).

## Ticketverkauf

| Regel | Trigger | Bedingungen | Vorlage |
|---|---|---|---|
| Ticket online bezahlt | Contribution is added | Finanztyp ist der Ticket-Typ, Status ist Completed | Herald: Neuer Ticket-/Event-Beitrag |
| Ticket per Überweisung bestellt | Contribution is added | Finanztyp ist der Ticket-Typ, Status ist Pending | Herald: Ticket bestellt, Überweisung angekündigt |
| Ticket-Überweisung eingegangen | Contribution is changed | Compare Old Contribution Status to New Contribution Status: von Pending zu Completed, und Finanztyp ist der Ticket-Typ | Herald: Ticket-Überweisung eingegangen |

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

Die Vorlagen für Registrierung, Stornierung, Mitgliedschaft und Newsletter zeigen den Kontakt mit maskiertem Namen, etwa „Ja** Do*“, die Newsletter-Vorlage zusätzlich mit maskierter E-Mail-Adresse, siehe [Triggervariablen](reference.md#triggervariablen). Das reicht, um in einem Gruppenchat eine Anmeldung zu erkennen oder Spam zu bemerken, ohne preiszugeben, um wen es sich handelt.

Anmeldungen kommen nach einem Mailing oder einem Import gehäuft. Ein Sammelfenster von 5 bis 15 Minuten hält den Chat lesbar.

---
title: Reports
order: 40
---

# Reports

Ein Report (im Code Digest genannt) fasst in einer Nachricht zusammen, was in einem Zeitraum passiert ist. Er besteht aus Blöcken, die nacheinander gerendert werden:

| Blocktyp | Inhalt | Standardvorlage |
|---|---|---|
| contribution | Beiträge eines Zeitraums: die Summe, eine Aufschlüsselung nach Quelle, die offenen ausstehenden Beträge, der Fortschritt einer Kampagne und ein Histogramm der Beträge | Herald: Beitragsreport-Block (kombinierbar) |
| search | Die Zeilen einer gespeicherten SearchKit-Suche | Herald: Report-Block aus einer gespeicherten Suche |
| section | Text, den eine andere Extension über einen Hook liefert | keine |

Ein Report ohne Blöcke ist ein einzelner Beitragsblock. Seine Einstellungen stehen dann auf der obersten Ebene, und er wird mit der angegebenen Vorlage gerendert, meist „Herald: Täglicher Beitragsreport“ oder „Herald: Wöchentlicher Beitragsreport“.

## Wege, einen Report zu verschicken

- Eine CiviRules-Regel mit dem Trigger „Herald: Schedule“ und der Aktion „Herald: Send digest“ verschickt tägliche oder wöchentliche Zusammenfassungen zu einer festen Stunde. Die Einrichtung zeigt [Geplante Reports](recipes.md#geplante-reports).
- Die Aktion „Herald: Send digest“ funktioniert auch mit jedem anderen Trigger und verschickt dann bei jedem Auslösen einen Report.
- Skripte und die Crontab des Servers können die API-Aktion `Herald.notifyDigest` direkt aufrufen.

Alle drei Wege unterstützen jeden [Zustellmodus](delivery.md#zustellmodi).

Bei E-Mail verwendet ein Report mit einem Block den Vorlagenbetreff dieses Blocks. Ein Report mit mehreren Blöcken hat keinen gemeinsamen Betreff. Setzen Sie dafür `email_subject`, sonst lautet der Betreff „Notification“.

## Reporteinstellungen

In der CiviRules-Aktion hat jede Einstellung ein Formularfeld. Bei `Herald.notifyDigest` stehen die Einstellungen in `actionParams`, zusammen mit den Zustelleinstellungen wie `channel` und `chat_id`.

| Einstellung | Standard | Bedeutung |
|---|---|---|
| `blocks` | keiner | Ein JSON-Array von Blöcken, siehe [Blocktypen](#blocktypen). Ohne diese Einstellung bilden die Einstellungen der obersten Ebene den einen Block. |
| `header` | keiner | Eine Zeile über den Blöcken. Sie wird als HTML eingefügt, zum Beispiel `📊 <b>Daily report</b>`. |
| `template` | der Standard des Blocktyps | Die Vorlage des einzelnen Blocks. Mit `blocks` nennt jeder Block seine eigene. |
| `channel` | `telegram` | Der Kanalschlüssel. |
| `period` | `yesterday` | `yesterday`, `today`, `last_7_days` oder `last_30_days`. Jeder Block kann einen eigenen Zeitraum setzen. Jeder andere Wert zählt als gestern. |

Ein Block übernimmt von der obersten Ebene nichts außer dem Zeitraum.

`Herald.notifyDigest` nimmt außerdem `sendHour` entgegen, außerhalb von `actionParams`. Damit wird der Report nur in dieser Stunde des Tages verschickt, von 0 bis 23 in der Zeitzone von CiviCRM. In jeder anderen Stunde gibt der Aufruf `skipped` zurück und sendet nichts, und ein Wert außerhalb von 0 bis 23 ist ein Fehler. Lassen Sie die Einstellung weg, wenn Sie einen Report von Hand auslösen. Eine Regel legt ihre Stunde stattdessen im Trigger „Herald: Schedule“ fest.

## Blocktypen

Jeder Block nimmt `type` auf, das ohne Angabe contribution ist, und `period`. Beitrags- und Suchblöcke nehmen außerdem `title` und `template` auf. Der Titel ist Klartext.

Das CiviRules-Formular prüft die Blöcke vor dem Speichern. Es meldet eine Liste, die kein JSON-Array aus Objekten ist, einen unbekannten Typ, einen Suchblock ohne `search`, einen Abschnittsblock ohne `key` und eine gespeicherte Suche, die es nicht gibt. Ein geplanter Job scheitert an denselben Fehlern, wenn er läuft, und das Job-Protokoll nennt den Grund.

### Beitragsblöcke

| Einstellung | Standard | Bedeutung |
|---|---|---|
| `campaign_id` | keiner | Nur die Beiträge dieser Kampagne zählen und ihren Fortschritt ergänzen. |
| `financial_type_id` | keiner | Nur diesen Finanztyp zählen. |
| `source_pattern` | keiner | Ein SQL-LIKE-Muster für die Beitragsquelle, zum Beispiel `%Bank TX%`. |
| `group_by` | source | `line_item` schlüsselt die Zahlen nach Bezeichnung und Einzelpreis der Position auf. Im Formular heißt das Feld „Aufschlüsseln nach“. |
| `show_pending` | an | Die offenen ausstehenden Beträge anzeigen. |
| `show_histogram` | an | Das Histogramm der Beträge anzeigen. |
| `bucket_count` | 8 | Die angestrebte Anzahl der Histogramm-Klassen. |
| `bucket_edges` | passend gewählt | Feste Klassengrenzen als JSON-Array, zum Beispiel `[0, 100, 1000, 10000]`. |

So zählt ein Beitragsblock:

- Abgeschlossene Beiträge zählen nach ihrem Eingangsdatum innerhalb des Zeitraums, nicht nach dem Tag, an dem sie abgeschlossen wurden. Testbeiträge und Vorlagen wiederkehrender Beiträge zählen nie.
- Gestern und heute sind Kalendertage. Die letzten 7 und die letzten 30 Tage enden um Mitternacht vor dem Lauf, der heutige Tag ist also nicht enthalten.
- Die ausstehenden Beträge umfassen jeden offenen ausstehenden Beitrag, unabhängig von seinem Alter.
- Ein Beitrag ohne Quelle erscheint als „Unknown“.
- Beträge werden als Zahlen ohne Währungsumrechnung addiert. Bei mehreren Währungen sind die Summen bedeutungslos.

Bei der Aufschlüsselung nach Positionen stammen Summen, Anzahlen und ausstehende Beträge aus den Positionen. Ein gemischter Warenkorb wird auf seine Positionen aufgeteilt, und die Anzahlen sind Stückzahlen. Positionen mit einer Zeilensumme von null oder weniger bleiben außen vor. Der Filter auf den Finanztyp gilt dann für jede Position, nicht für den Beitrag. Nur die kombinierbare Blockvorlage gibt die Positionen aus, verwenden Sie für diese Ansicht also Blöcke.

Das Histogramm zeigt die abgeschlossenen Beiträge des Zeitraums, die zu den Filtern passen, und ignoriert Beträge von null oder weniger. Es verwendet immer ganze Beitragsbeträge, bei der Aufschlüsselung nach Positionen gilt sein Filter auf den Finanztyp also für den Beitrag. Gibt es nichts anzuzeigen, steht dort „(no data)“.

Ohne feste Grenzen wählt herald Klassengrenzen, die zu den Beträgen passen. Feste Grenzen eignen sich für eine Kampagne, die in Stufen erzählt wird, etwa eine Großspendenkampagne. Geben Sie mindestens zwei Werte an:

```json
"bucket_edges": [0, 100, 1000, 10000, 100000, 1000000]
```

Beträge unterhalb der ersten oder oberhalb der letzten Grenze landen in zusätzlichen Klassen mit den Beschriftungen „<low“ und „≥high“.

Der Fortschritt einer Kampagne übernimmt Titel und Ziel aus dem Kampagnendatensatz. Er zählt jeden abgeschlossenen Beitrag der Kampagne, der zu den Filtern auf Finanztyp und Quelle passt, unabhängig vom Zeitraum. Bei der Aufschlüsselung nach Positionen zählt er stattdessen die Positionen der Kampagne. Eine Kampagne ohne Ziel zeigt keine Fortschrittszeile.

### Suchblöcke

Ein Suchblock zeigt die Zeilen einer gespeicherten SearchKit-Suche. Geben Sie die Suche mit ihrem Maschinennamen an. Um Anzahlen zu zeigen, gruppieren Sie die Suche, zum Beispiel Mitgliedschaften nach Typ:

```json
{"type": "search", "search": "memberships_by_type", "title": "Members"}
```

Die Standardvorlage gibt eine Zeile pro Ergebniszeile aus, mit den Spalten der Suche in ihrer Reihenfolge. Es zählt nur die Abfrage der Suche, ihre Anzeigen spielen keine Rolle. Geben Sie der Suche ein Limit, denn jede Zeile landet in der Nachricht, und nur Telegram kürzt eine zu lange Nachricht.

herald führt die Suche ohne Berechtigungsprüfung aus, so wie seine eigenen Beitragsabfragen. Wer einen Report konfigurieren darf, kann deshalb die Zeilen jeder gespeicherten Suche an einen Kanal schicken.

### Abschnitte aus anderen Extensions

Manche Zahlen kann keine Abfrage liefern, etwa gebuchte Plätze aus einer Ticketing-Extension oder die Länge einer Warteschlange. Die Extension, der diese Daten gehören, kann über den Hook `hook_civicrm_heraldDigestSections` einen Abschnitt liefern:

```php
function myext_civicrm_heraldDigestSections(array $keys, array $context, array &$sections): void {
  if (in_array('myext.seats', $keys, TRUE)) {
    $sections['myext.seats'] = "🪑 <b>Seats</b>\n138 booked";
  }
}
```

Der Block `{"type": "section", "key": "myext.seats"}` fügt die Antwort ein. `$context` enthält die Report-Einstellungen unter `params` und den Zeitraum des Blocks unter `period`, sodass der Listener zum Zeitraum des Reports passende Zahlen liefern kann.

Die Antwort wird als HTML eingefügt, der Listener maskiert seine eigenen Daten also selbst. Eine leere Antwort bedeutet, dass es nichts zu melden gibt, und der Block entfällt. Ein Schlüssel, auf den keine Extension antwortet, lässt den Report mit dem Fehler „no extension answers the digest section“ scheitern, damit ein Tippfehler nicht unbemerkt bleibt.

### Beispiel

Vier Blöcke: die Spenden einer Kampagne, Ticketverkäufe nach Position, Mitglieder nach Typ und ein Abschnitt aus einer anderen Extension.

```json
[
  {"title": "Donations", "financial_type_id": 1, "campaign_id": 2},
  {"title": "Tickets", "financial_type_id": 4, "group_by": "line_item", "show_histogram": false},
  {"type": "search", "search": "memberships_by_type", "title": "Members"},
  {"type": "section", "key": "myext.seats"}
]
```

## Quellen normalisieren

Beitragsquellen sind oft technisch, etwa die Referenz eines Zahlungsanbieters oder das Transaktionspräfix eines Bankimports. Die Einstellung `herald_source_normalization` ordnet ihnen lesbare Bezeichnungen zu. Die Bezeichnungen erscheinen in der Aufschlüsselung des Reports und als Quelle in Beitragsbenachrichtigungen. Standardmäßig ist die Einstellung leer, und die Quellen bleiben, wie sie sind.

```bash
cv api4 Setting.set +v 'herald_source_normalization=[
  {"match": "Bank TX", "type": "contains", "label": "Bank transfer"},
  {"match": "Ueberweisung", "type": "prefix", "label": "Bank transfer"},
  {"match": "/^pi_[A-Za-z0-9]+$/", "type": "regex", "label": "Stripe"}
]'
```

- Die Regeln werden der Reihe nach geprüft, und der erste Treffer gewinnt.
- Der Typ ist contains, prefix oder regex. Bei contains und prefix zählt die Groß- und Kleinschreibung.
- Ein regulärer Ausdruck verwendet die preg-Syntax von PHP, einschließlich der Begrenzungszeichen.
- Eine fehlerhafte Regel wird ignoriert, sodass ein Tippfehler keine Meldung kaputt machen kann.
- Im Report werden Quellen mit derselben Bezeichnung zu einer Zeile addiert.

## Vorlagenvariablen

Die Vorlagen für Beitragsblöcke sowie die Vorlagen für den täglichen und den wöchentlichen Report erhalten:

| Variable | Inhalt |
|---|---|
| period.label, period.start, period.end | Der Name des Zeitraums und seine Grenzen. Die Bezeichnung ist bei gestern und heute das Datum, sonst „last 7 days“ oder „last 30 days“. |
| totals.amount, totals.count | Die abgeschlossenen Beiträge des Zeitraums. Bei der Aufschlüsselung nach Positionen die Summen und Stückzahlen der Positionen. |
| by_source | Eine Liste aus source, total und count. |
| by_item | Bei der Aufschlüsselung nach Positionen eine Liste aus label, unit_price, qty und total. |
| pending.amount, pending.count | Die offenen ausstehenden Beiträge. |
| pending_items | Bei der Aufschlüsselung nach Positionen die ausstehenden Positionen, aufgebaut wie by_item. |
| campaign.title, campaign.goal, campaign.total, campaign.count, campaign.percent | Der Fortschritt der Kampagne, wenn der Block eine Kampagne nennt. Das Ziel ist 0, wenn die Kampagne keines hat. Der Prozentsatz ist auf eine Nachkommastelle gerundet. |
| histogram | Das Histogramm als vorformatierter Text. Es ist leer, wenn das Histogramm ausgeschaltet ist. |
| block_title | Der Titel des Blocks. |

Vorlagen für Suchblöcke erhalten block_title, count, also die Anzahl der Zeilen, und rows. Jede Zeile hat cells, die Spaltenwerte in ihrer Reihenfolge, und values, dieselben Werte nach Spaltennamen. In den Schlüsseln wird jedes Zeichen, das kein Buchstabe, keine Ziffer und kein Unterstrich ist, zu einem Unterstrich. Die Spalte `contact_type:label` wird zu `contact_type_label`, und `COUNT(id) AS n` wird zu `n`.

In einer Vorlage sind das Smarty-Variablen wie `{$totals.amount|crmMoney}`.

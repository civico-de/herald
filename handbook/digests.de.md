---
title: Reports
order: 40
routes:
  - civicrm/admin/herald
---

# Reports

Ein Report (im Code Digest genannt) fasst einen Zeitraum in einer Nachricht zusammen, zum Beispiel die Spenden von gestern. Jeder Report ist ein Datensatz mit einem Namen und einer Liste von Blöcken, die nacheinander gerendert werden:

| Blocktyp | Inhalt | Standardvorlage |
|---|---|---|
| Beiträge | Beiträge eines Zeitraums: die Summe, eine Aufschlüsselung nach Quelle, die offenen ausstehenden Beträge, der Fortschritt einer Kampagne und ein Histogramm der Beträge | Herald: Beitragsreport-Block (kombinierbar) |
| Gespeicherte Suche | Die Zeilen einer gespeicherten SearchKit-Suche | Herald: Report-Block aus einer gespeicherten Suche |
| Abschnitt aus einer Extension | Text, den eine andere Extension über einen Hook liefert | keine |

## Einen Report einrichten

Die Reports finden Sie unter Administrieren » Herald » Reports. „Report hinzufügen“ öffnet das Formular, und jede Zeile der Liste hat „Bearbeiten“ und „Löschen“.

- **Titel** bezeichnet den Report in Listen und in CiviRules.
- **Name** ist das, womit Regeln und Skripte den Report auswählen. Er besteht aus Kleinbuchstaben, Ziffern und Unterstrichen, und zwei Reports können nicht denselben haben.
- **Zeitraum** gilt für jeden Block, der keinen eigenen setzt: gestern, heute, die letzten 7 oder die letzten 30 Tage.
- **Kopfzeile** ist eine optionale Zeile über den Blöcken. Sie wird als HTML eingefügt, zum Beispiel `📊 <b>Daily report</b>`.

Darunter folgen die Blöcke, mindestens einer, in der Reihenfolge, in der sie in der Nachricht stehen. Jeder Block hat einen Typ, einen Titel und bei Bedarf einen eigenen Zeitraum. Beitrags- und Suchblöcke haben außerdem eine Vorlage. Bleibt sie leer, gilt die Standardvorlage des Blocktyps. Die übrigen Felder hängen vom Typ ab und sind unter [Blocktypen](#blocktypen) beschrieben.

Beim Speichern wird der ganze Report geprüft, bevor etwas geschrieben wird. Das Formular lehnt ab:

- einen Namen, der schon vergeben ist oder falsche Zeichen enthält,
- einen Suchblock ohne Suche,
- einen Abschnittsblock ohne Schlüssel,
- eine Klassenzahl unter 1,
- Histogrammgrenzen, die nicht aus mindestens zwei aufsteigenden Zahlen bestehen.

Eine Meldung zu einem Block nennt ihn, zum Beispiel „Block 2: ein Suchblock braucht eine gespeicherte Suche.“ Über APIv4 gelten dieselben Prüfungen für jeden Datensatz von `HeraldDigest` und `HeraldDigestBlock`. Ein dort ohne Blöcke gespeicherter Report scheitert beim Versand.

Skripte und Deployments können einen Report mit cv anlegen. Die Verkettung speichert die Blöcke in der angegebenen Reihenfolge. JSON über mehrere Zeilen muss über die Standardeingabe kommen, daher `--in=json`:

```bash
cv api4 --in=json HeraldDigest.create <<'JSON'
{
  "values": {"name": "weekly_report", "title": "Weekly report", "period": "last_7_days", "header": "📈 <b>Weekly report</b>"},
  "chain": {"blocks": ["HeraldDigestBlock", "save", {
    "defaults": {"digest_id": "$id"},
    "records": [
      {"type": "contribution", "title": "Donations", "financial_type_id": 1},
      {"type": "section", "section_key": "myext.seats"}
    ]
  }]}
}
JSON
```

### Was eine Änderung kaputt macht

Regeln und Skripte wählen einen Report über seinen Namen. Nachdem Sie den Namen eines Reports geändert oder den Report gelöscht haben, scheitern sie mit der Meldung `Den Report „weekly_report“ gibt es nicht.`, bis sie einen anderen nennen. Die Regelliste zeigt dieselbe Meldung anstelle des Reporttitels. Den Titel können Sie jederzeit ändern.

Eine Kampagne oder ein Finanztyp, nach dem ein Block filtert, lässt sich nicht löschen, solange der Block existiert, der Versuch endet mit einem Datenbankfehler. Ohne seinen Filter würde der Block jeden Beitrag zählen. Ändern oder entfernen Sie zuerst den Block.

Eine gespeicherte Suche lässt sich löschen. Der Block, der sie verwendet hat, steht dann ohne Suche da, und der Report scheitert beim Versand mit der Meldung, die auch das Formular gezeigt hätte. Wählen Sie eine andere Suche oder entfernen Sie den Block.

## Einen Report verschicken

- Eine CiviRules-Regel mit dem Trigger „Herald: Schedule“ und der Aktion „Herald: Send digest“ verschickt einen täglichen oder wöchentlichen Report zu einer festen Stunde. Die Einrichtung zeigt [Geplante Reports](recipes.md#geplante-reports).
- Die Aktion „Herald: Send digest“ funktioniert auch mit jedem anderen Trigger und verschickt den Report dann bei jedem Auslösen. In der Aktion wählen Sie den Report aus einer Liste und legen die Zustelleinstellungen fest.
- Skripte und die Crontab des Servers können die API-Aktion `Herald.notifyDigest` direkt aufrufen.

Alle drei Wege unterstützen jeden [Zustellmodus](delivery.md#zustellmodi).

`Herald.notifyDigest` nimmt entgegen:

| Parameter | Bedeutung |
|---|---|
| `digest` | Der Name des Reports. Pflicht. |
| `actionParams` | Die Zustelleinstellungen, etwa `channel`, `chat_id` oder `delivery_mode`. Ohne Angabe ist der Kanal `telegram`. |
| `sendHour` | Optional. Der Report wird nur in dieser Stunde verschickt, von 0 bis 23 in der Zeitzone von CiviCRM. In jeder anderen Stunde gibt der Aufruf `skipped` zurück und sendet nichts, und ein Wert außerhalb von 0 bis 23 ist ein Fehler. Lassen Sie den Parameter weg, wenn Sie einen Report von Hand auslösen. |

```bash
cv api4 Herald.notifyDigest '{"checkPermissions": false, "digest": "weekly_report", "actionParams": {"chat_id": "-1001234567890"}}'
```

Bei E-Mail verwendet ein Report mit einem Block den Vorlagenbetreff dieses Blocks. Ein Report mit mehreren Blöcken hat keinen gemeinsamen Betreff. Setzen Sie dafür `email_subject` in den Zustelleinstellungen, sonst lautet der Betreff „Notification“.

## Blocktypen

### Beitragsblöcke

| Feld | API-Name | Standard | Bedeutung |
|---|---|---|---|
| Kampagne | `campaign_id` | keiner | Nur die Beiträge dieser Kampagne zählen und ihren Fortschritt ergänzen. |
| Finanztyp | `financial_type_id` | keiner | Nur diesen Finanztyp zählen. |
| Quellmuster | `source_pattern` | keiner | Ein SQL-LIKE-Muster für die Beitragsquelle, zum Beispiel `%Bank TX%`. |
| Aufschlüsseln nach | `group_by` | Beitragsquelle | „Position und Einzelpreis“ schlüsselt die Zahlen nach Bezeichnung und Einzelpreis der Position auf. |
| Ausstehende Beträge anzeigen | `show_pending` | an | Die offenen ausstehenden Beträge anzeigen. |
| Histogramm anzeigen | `show_histogram` | an | Das Histogramm der Beträge anzeigen. |
| Histogramm-Klassen | `bucket_count` | 8 | Die angestrebte Anzahl der Histogramm-Klassen. |
| Klassengrenzen des Histogramms | `bucket_edges` | passend gewählt | Feste Klassengrenzen als JSON-Array, zum Beispiel `[0, 100, 1000, 10000]`. |

So zählt ein Beitragsblock:

- Abgeschlossene Beiträge zählen nach ihrem Eingangsdatum innerhalb des Zeitraums, nicht nach dem Tag, an dem sie abgeschlossen wurden. Testbeiträge und Vorlagen wiederkehrender Beiträge zählen nie.
- Gestern und heute sind Kalendertage. Die letzten 7 und die letzten 30 Tage enden um Mitternacht vor dem Lauf, der heutige Tag ist also nicht enthalten.
- Die ausstehenden Beträge umfassen jeden offenen ausstehenden Beitrag, unabhängig von seinem Alter.
- Ein Beitrag ohne Quelle erscheint als „Unknown“.
- Beträge werden als Zahlen ohne Währungsumrechnung addiert. Bei mehreren Währungen sind die Summen bedeutungslos.

Bei der Aufschlüsselung nach Positionen stammen Summen, Anzahlen und ausstehende Beträge aus den Positionen. Ein gemischter Warenkorb wird auf seine Positionen aufgeteilt, und die Anzahlen sind Stückzahlen. Positionen mit einer Zeilensumme von null oder weniger bleiben außen vor. Der Filter auf den Finanztyp gilt dann für jede Position, nicht für den Beitrag. Nur die kombinierbare Blockvorlage gibt die Positionen aus, lassen Sie die Vorlage für diese Ansicht also leer.

Das Histogramm zeigt die abgeschlossenen Beiträge des Zeitraums, die zu den Filtern passen, und ignoriert Beträge von null oder weniger. Es verwendet immer ganze Beitragsbeträge, bei der Aufschlüsselung nach Positionen gilt sein Filter auf den Finanztyp also für den Beitrag. Gibt es nichts anzuzeigen, steht dort „(no data)“.

Ohne feste Grenzen wählt herald Klassengrenzen, die zu den Beträgen passen. Feste Grenzen eignen sich für eine Kampagne, die in Stufen erzählt wird, etwa eine Großspendenkampagne. Geben Sie mindestens zwei Werte an, zum Beispiel `[0, 100, 1000, 10000, 100000, 1000000]`. Beträge unterhalb der ersten oder oberhalb der letzten Grenze landen in zusätzlichen Klassen mit den Beschriftungen „<low“ und „≥high“. Ist das Histogramm ausgeschaltet, werden die Grenzen nicht geprüft.

Der Fortschritt einer Kampagne übernimmt Titel und Ziel aus dem Kampagnendatensatz. Er zählt jeden abgeschlossenen Beitrag der Kampagne, der zu den Filtern auf Finanztyp und Quelle passt, unabhängig vom Zeitraum. Bei der Aufschlüsselung nach Positionen zählt er stattdessen die Positionen der Kampagne. Eine Kampagne ohne Ziel zeigt keine Fortschrittszeile.

### Blöcke aus einer gespeicherten Suche

Ein solcher Block zeigt die Zeilen einer gespeicherten SearchKit-Suche, die Sie im Feld „Gespeicherte Suche“ (`saved_search_id`) wählen. Um Anzahlen zu zeigen, gruppieren Sie die Suche, zum Beispiel Mitgliedschaften nach Typ.

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

Ein Block mit dem Abschnittsschlüssel `myext.seats` fügt die Antwort ein. `$context` enthält den Namen des Reports unter `digest` und den Zeitraum des Blocks unter `period`, sodass der Listener zum Zeitraum des Reports passende Zahlen liefern kann.

Die Antwort wird als HTML eingefügt, der Listener maskiert seine eigenen Daten also selbst. Eine leere Antwort bedeutet, dass es nichts zu melden gibt, und der Block entfällt. Ein Schlüssel, auf den keine Extension antwortet, lässt den Report mit dem Fehler „no extension answers the digest section“ scheitern, damit ein Tippfehler nicht unbemerkt bleibt.

## Reports aus älteren Versionen

Ältere Versionen haben einen Report in den Einstellungen seiner Regelaktion oder seines geplanten Jobs abgelegt. Das Upgrade macht aus jedem einen Datensatz und lässt in der Aktion nur die Zustelleinstellungen und den Namen des Reports stehen. Der Report einer Regelaktion heißt `rule_action_<id>` und übernimmt den Titel der Regel, der Report eines Jobs heißt `job_<id>` und übernimmt den Namen des Jobs. Sie können sie danach umbenennen. Wählen Sie den Report dann in jeder Regelaktion, die ihn verschickt hat, neu aus, und passen Sie die Jobs und Skripte an, die ihn nennen.

Das Upgrade behält bei, was der Report bisher verschickt hat. Einen Zeitraum, den herald nicht kannte, hat es als gestern gelesen, und so bleibt es. Ein Report, der nie laufen konnte, bleibt, wie er war, und das Upgrade-Protokoll enthält die Warnung „digest left inline, it cannot run until fixed“ mit dem Grund. Beispiele sind eine Blockliste, die kein JSON-Array aus Objekten ist, ein Block unbekannten Typs, ein Abschnittsblock ohne Schlüssel, eine gespeicherte Suche, Kampagne oder ein Finanztyp, die es nicht mehr gibt, und ein Filter auf den Beitragsstatus, den die Datensätze nicht mehr kennen. Eine solche Regel oder ein solcher Job scheitert, bis er einen Report nennt.

Das Report-Formular ist ein Afform, herald braucht deshalb die Extension Form Core (`org.civicrm.afform`). Aktivieren Sie sie vor dem Upgrade von herald auf einer Seite, auf der sie noch fehlt.

## Quellen normalisieren

Beitragsquellen sind oft technisch, etwa die Referenz eines Zahlungsanbieters oder das Transaktionspräfix eines Bankimports. Die Einstellung `herald_source_normalization` ordnet ihnen lesbare Bezeichnungen zu. Die Bezeichnungen erscheinen in der Aufschlüsselung des Reports und als Quelle in Beitragsbenachrichtigungen. Standardmäßig ist die Einstellung leer, und die Quellen bleiben, wie sie sind.

Die Regeln finden Sie unter Administrieren » Herald » Namen der Beitragsquellen. Jede Zeile enthält den Quelltext, die Art des Vergleichs und den anzuzeigenden Namen. Die Pfeil-Schaltflächen ändern die Reihenfolge, in der die Regeln geprüft werden. Beim Speichern werden eine unvollständige Regel und ein regulärer Ausdruck, der sich nicht kompilieren lässt, abgelehnt, mit der Nummer der Regel.

Mit cv:

```bash
cv api4 --in=json Setting.set <<'JSON'
{"values": {"herald_source_normalization": [
  {"match": "Bank TX", "type": "contains", "label": "Bank transfer"},
  {"match": "Ueberweisung", "type": "prefix", "label": "Bank transfer"},
  {"match": "/^pi_[A-Za-z0-9]+$/", "type": "regex", "label": "Stripe"}
]}}
JSON
```

- Die Regeln werden der Reihe nach geprüft, und der erste Treffer gewinnt.
- Der Typ ist contains, prefix oder regex. Bei contains und prefix zählt die Groß- und Kleinschreibung.
- Ein regulärer Ausdruck verwendet die preg-Syntax von PHP, einschließlich der Begrenzungszeichen.
- Eine fehlerhafte Regel, die in die Einstellung gelangt, wird ignoriert, sodass ein Tippfehler keine Meldung kaputt machen kann. Die Seite und `Herald.saveSourceRules` lehnen sie ab, bevor sie gespeichert wird.
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

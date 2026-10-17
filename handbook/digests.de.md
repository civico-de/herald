---
title: Reports
order: 40
routes:
  - civicrm/admin/herald
---

# Reports

Ein Report (im Code Digest genannt) fasst etwas in einer Nachricht zusammen, zum Beispiel die Spenden von gestern und die Aktivitäten der letzten Woche. Er besteht aus Betreff und Text, die Sie selbst schreiben, und den Quellen, die ihre Platzhalter füllen:

- eine **Suche** ist eine SearchKit-Anzeige. Ihre Spaltenüberschriften benennen die Werte, und ihre Filter bestimmen, was zählt, auch den Zeitraum;
- ein **Abschnitt** ist eine Reihe von Werten, die eine andere Extension über einen Hook liefert, für Zahlen, die keine Suche beantworten kann.

Eine Quelle namens `spenden`, die Summe und Anzahl der Beiträge von gestern zeigt, füllt `{$spenden.summe}` und `{$spenden.anzahl}`. Was die Nachricht sagt und wie sie aussieht, bestimmt der Text:

```smarty
📊 <b>Gestern</b>
Spenden: {$spenden.summe|crmMoney} ({$spenden.anzahl})
```

herald setzt Betreff und Text mit Smarty zusammen, so wie CiviCRM eine Nachrichtenvorlage.

Für eine Nachricht zu jedem neuen Beitrag braucht es keinen Report: Eine Regel verschickt jeden einzeln, sobald er eingeht, oder sammelt sie alle paar Minuten in einer Nachricht, siehe [Sammelnachrichten](delivery.md#sammelnachrichten).

## Einen Report einrichten

1. Wählen Sie unter Administrieren » Herald » Reports „Report hinzufügen“. Geben Sie einen Titel und einen Namen an.
2. Fügen Sie für jeden Teil der Nachricht eine Quelle hinzu und geben Sie ihr einen Namen wie `spenden`. Für eine fertige Auswertung wählen Sie die Zahlen und den Zeitraum, etwa „Beiträge: Summe und Anzahl“ für die letzte Woche. „Eigene Suche aus SearchKit“ nimmt stattdessen jede Anzeige vom Typ Tabelle, Liste oder Raster. Ein Abschnitt aus einer anderen Extension braucht seinen Schlüssel.
3. Unter jeder Quelle listet das Formular ihre Platzhalter und bietet einen Textbaustein an, der sie verwendet, mit bereits formatierten Beträgen und Daten. Kopieren Sie den Baustein in den Text und passen Sie ihn nach Belieben an. Dazwischen schreiben Sie, was Sie möchten.
4. Füllen Sie den Betreff aus. Bleibt er leer, ist der Titel des Reports der Betreff.
5. „Vorschau mit heutigen Daten“ setzt den Report so zusammen, wie das Formular ihn gerade enthält. Dabei wird nichts gespeichert und nichts verschickt.

Über den **Namen** wählen Regeln und Skripte den Report aus. Er besteht aus Kleinbuchstaben, Ziffern und Unterstrichen und muss eindeutig sein. Quellennamen beginnen mit einem Kleinbuchstaben, gefolgt von Kleinbuchstaben, Ziffern und Unterstrichen. Innerhalb eines Reports müssen sie verschieden sein, und `smarty` ist reserviert.

Beim Speichern prüft herald den ganzen Report, bevor es etwas schreibt: den Namen, den Text, jede Quelle und ob sich Betreff und Text mit den heutigen Werten zusammensetzen lassen. Das Formular listet jedes gefundene Problem auf. Über APIv4 bekommt jeder `HeraldDigest`- und `HeraldDigestSource`-Datensatz dieselben Feldprüfungen, und ein neuer Betreff oder Text eines gespeicherten Reports muss sich vor dem Speichern mit seinen Quellen rendern lassen. Eine Quelle, die in dem Moment nicht lädt, blockiert die Änderung nicht; sie schlägt erst beim Versand fehl.

Skripte und Deploys können einen Report mit cv anlegen. JSON über mehrere Zeilen muss über die Standardeingabe kommen, daher `--in=json`:

```bash
cv api4 --in=json HeraldDigest.create <<'JSON'
{
  "values": {"name": "weekly_report", "title": "Wochenbericht",
    "msg_subject": "Wochenbericht", "msg_html": "Spenden: {$spenden.summe|crmMoney}"},
  "chain": {"sources": ["HeraldDigestSource", "save", {
    "defaults": {"digest_id": "$id"},
    "records": [
      {"name": "spenden", "type": "search", "search_display_id": 12},
      {"name": "plaetze", "type": "section", "section_key": "myext.seats"}
    ]
  }]}
}
JSON
```

## Platzhalter

Eine Suchquelle gibt jede Wertspalte ihrer Anzeige an den Text weiter, benannt nach der Spaltenüberschrift. Der Name ist die Überschrift in Kleinbuchstaben, mit ausgeschriebenen Umlauten und `_` für jedes andere Zeichen: Aus „Plätze gesamt“ wird `{$quelle.plaetze_gesamt}`. Das Formular lehnt eine Überschrift ab, die den Namen `rows` ergibt, mit einer Ziffer beginnt oder zwei Spalten denselben Namen gibt. Benennen Sie die Überschrift dann in SearchKit um.

- `{$quelle.summe}` und die übrigen Namen enthalten die Werte der ersten Zeile. Für eine Suche mit einer einzigen Zeile, etwa eine Summe, genügt das.
- `{$quelle.rows}` enthält alle Zeilen, jede mit denselben Namen. Für eine Liste gehen Sie sie in einer Schleife durch:

  ```smarty
  {foreach from=$spenden.rows item=row}
  • {$row.formular}: {$row.summe|crmMoney}
  {/foreach}
  ```

- Ohne Zeilen ist jeder Wert leer und `rows` eine leere Liste. Eine Summe oder Anzahl ohne Zeilen ist 0.
- Die Werte kommen roh an, so wie die Suche sie liefert: Beträge als Zahlen, Daten als `2026-10-13 09:00:00`. Formatieren Sie sie im Text mit `|crmMoney` und `|crmDate`; der Textbaustein tut das bereits.

Welche Zeilen in die Nachricht kommen, entscheidet die Anzeige. Ihre Filter und ihre Sortierung gelten, ebenso ihr Limit. Eine Anzeige mit Seitenumbruch liefert nur ihre erste Seite. Geben Sie einer Anzeige, die Datensätze auflistet, ein Limit, denn jede Zeile landet in der Nachricht, und nur Telegram kürzt eine zu lange Nachricht. Wählbar sind nur Tabellen-, Listen- und Rasteranzeigen, weil die anderen Typen keine Zeilen haben. Links, Schaltflächen, Menüs, Bilder und Untersuchen liefern keine Werte.

herald führt die Anzeige ohne Berechtigungsprüfung aus. Wer einen Report einrichten darf, kann also die Zeilen jeder Anzeige an einen Kanal schicken.

### Den Text schreiben

Datum, Rechnen und Formatieren erledigt Smarty: `{"yesterday"|date_format:"%d.%m.%Y"}` gibt das Datum von gestern aus, und `{($teil * 100 / $gesamt)|round:1}` rechnet einen Prozentwert aus. Eine Division durch null bricht den Report ab, setzen Sie eine solche Rechnung daher in `{if $gesamt gt 0}…{/if}`.

Der Text ist HTML und wird genau so gespeichert, wie Sie ihn tippen. Zeilenumbrüche zählen: Telegram zeigt sie, wie sie sind, und E-Mail macht daraus `<br>`. Wie jeder Kanal das HTML darstellt, steht unter [Nachrichtenformat](channels.md#nachrichtenformat).

Ein Platzhalter mit Tippfehler wie `{$spenden.sume}` oder eine Quelle, die der Report nicht hat, ist ein Fehler und keine leere Zeile. Das prüfen das Speichern des Reports und der Versand. Lässt sich ein Report beim Versand nicht zusammensetzen, bekommen seine Empfänger eine kurze Nachricht mit dem Titel des Reports und dem Grund, und der Fehler landet zusätzlich im Protokoll.

## Mitgelieferte Suchen

herald bringt Suchen mit, die Sie direkt verwenden können. Das Formular bietet sie als fertige Auswertungen an, nach Zahlen und Zeitraum. Sie zählen keine Testbeiträge und keine Vorlagen wiederkehrender Beiträge, und sie geben keine Kontaktdaten aus:

| Anzeige | Werte |
|---|---|
| Report: Beiträge *Zeitraum* | `summe`, `anzahl` der abgeschlossenen Beiträge nach Eingangsdatum |
| … nach Formular | `formular`, `summe`, `anzahl` je Beitragsseite |
| … nach Finanztyp | `finanztyp`, `summe`, `anzahl` je Finanztyp |
| Report: Posten *Zeitraum* | `posten`, `preis`, `menge`, `summe` je Postenbezeichnung und Preis, ohne kostenlose Posten |
| Report: Beitragsbeträge *Zeitraum* | `betrag` je Beitrag |
| Report: ausstehende Beiträge | `summe`, `anzahl` aller ausstehenden Beiträge, gleich welchen Alters |
| Report: Aktivitäten *Zeitraum* nach Typ und Status | `typ`, `status`, `anzahl` |
| Report: überfällige Aktivitäten nach Typ | `typ`, `anzahl` der geplanten Aktivitäten, deren Datum verstrichen ist |

Die Beitragssuchen gibt es nur, solange CiviContribute aktiviert ist. Die Aktivitätssuchen lassen die Benachrichtigungen von herald aus, die sein Aktivitätskanal als Aktivitäten ablegt. Die Zeiträume sind heute, gestern, diese Woche, letzte Woche, letzte 7 Tage, diesen Monat, letzten Monat und dieses Jahr. Bis auf die letzten 7 Tage, die heute einschließen, sind es Kalenderzeiträume: Die letzte Woche ist die vorige volle Woche, beginnend mit dem ersten Wochentag aus den Datumseinstellungen von CiviCRM, „diese Woche“, „diesen Monat“ und „dieses Jahr“ zählen bis zum Zeitpunkt des Versands, und „heute“ ist der ganze Tag, mit den für später am Tag geplanten Aktivitäten. herald ersetzt diese Suchen bei jedem Upgrade. Für einen anderen Zeitraum oder einen Filter kopieren Sie eine davon in SearchKit und ändern die Kopie. Ihre Anzeigen behalten die Überschriften aus der Installation, in der Sprache der Seite, und die Namen folgen den Überschriften: Eine englische Seite hat `{$donations.sum}`. Ein späterer Sprachwechsel benennt sie nicht um, die Reports funktionieren also weiter. Das Formular listet die Namen jeder Anzeige auf.

## Diagramme

Zwei Modifikatoren zeichnen eine Spalte der Zeilen einer Quelle als Textdiagramm. Setzen Sie sie in `<pre>`, damit die Balken bündig stehen. Der Baustein neben einer Quelle mit mehreren Zeilen enthält schon den passenden: für eine gruppierte Suche nur die Balken, weil sie jede Gruppe benennen, für jede andere die Liste und darunter ein Histogramm. Er nimmt die letzte Betragsspalte, sonst die letzte Zahl.

### Balken

`herald_bars` zeichnet je Zeile einen Balken, bemessen nach einer Zahlenspalte und benannt nach einer oder mehreren anderen Spalten, durch Kommas getrennt. Das passt zu einer gruppierten Suche wie Beiträgen nach Formular:

```smarty
<pre>{$formulare.rows|herald_bars:"summe":"formular":"money"}</pre>
<pre>{$aktivitaeten.rows|herald_bars:"anzahl":"typ,status"}</pre>
```

Jede Zeile zeigt die Namen, jeden in einer eigenen bündigen Spalte, dann Wert und Balken, und der größte Wert bekommt den vollen Balken. Balken wachsen in Achteln eines Blocks, sodass auch ein kleiner Wert sichtbar bleibt. Ein drittes Argument `"money"` schreibt den Wert als Betrag in der Währung der Website, ohne es steht er als einfache Zahl. Die Zeilen behalten die Reihenfolge des Displays. Ein leerer Name wird zu „(keine Angabe)“, und ohne einen Wert über null zeigt das Diagramm „(keine Daten)“.

### Histogramme

`herald_histogram` zeigt, wie sich die Werte einer Spalte verteilen, etwa die Beträge einzelner Beiträge:

```smarty
<pre>{$betraege.rows|herald_histogram:"betrag"}</pre>
<pre>{$betraege.rows|herald_histogram:"betrag":5}</pre>
<pre>{$betraege.rows|herald_histogram:"betrag":"0,100,1000,10000"}</pre>
```

Ohne zweites Argument zielt er auf 8 Klassen mit Grenzen, die zu den Werten passen. Eine Zahl legt die Anzahl der Klassen fest. Feste Grenzen, durch Kommas getrennt und aufsteigend, passen zu einer Kampagne mit Spendenstufen. Werte unter der ersten Grenze landen in einer zusätzlichen Klasse ab 0, Werte über der letzten in einer mit „≥“ und der Grenze. Jede Zeile zeigt die Klasse, die Anzahl mit der Summe der Klasse in Klammern und einen Balken nach der Anzahl. Leere Werte und Werte von null oder weniger werden übersprungen, und ohne Daten zeigt das Diagramm „(keine Daten)“.

## Abschnitte aus anderen Extensions

Manche Zahlen kann keine Suche beantworten, etwa gebuchte Plätze aus einer Ticket-Extension oder die Länge einer Warteschlange. Die Extension, der sie gehören, antwortet für ihren eigenen Schlüssel über `hook_civicrm_heraldDigestSections`:

```php
function myext_civicrm_heraldDigestSections(array $keys, array $context, array &$sections): void {
  if (in_array('myext.seats', $keys, TRUE)) {
    $sections['myext.seats'] = ['booked' => 138, 'paid' => 120];
  }
}
```

Eine Quelle vom Typ Abschnitt mit dem Schlüssel `myext.seats` und dem Namen `plaetze` füllt dann `{$plaetze.booked}` und `{$plaetze.paid}`. Eine Antwort enthält benannte Werte und Listen von Zeilen mit benannten Werten, mit Namen wie bei Quellen. `$context` enthält unter `digest` den Namen des Reports. Listen gehen Sie in einer Schleife durch wie `rows`.

Die Antwort enthält Werte, keinen Text: Der Text des Reports formatiert sie, und herald maskiert sie wie jede andere Variable. Ein Schlüssel, auf den keine Extension antwortet, oder eine Antwort als Text lässt den Report mit einer Meldung scheitern, die den Schlüssel nennt. So bleibt ein Tippfehler nicht unbemerkt.

## Einen Report verschicken

- Eine CiviRules-Regel mit dem Trigger „Herald: Schedule“ und der Aktion „Herald: Send digest“ verschickt einen Report zu einer festen Stunde. Die Einrichtung zeigt [Geplante Reports](recipes.md#geplante-reports).
- Die Aktion „Herald: Send digest“ funktioniert auch mit jedem anderen Trigger und verschickt den Report dann bei jedem Auslösen. In der Aktion wählen Sie den Report aus einer Liste und legen die Zustelleinstellungen fest.
- Skripte und die Crontab des Servers können die API-Aktion `Herald.notifyDigest` direkt aufrufen.

Alle drei Wege unterstützen jeden [Zustellmodus](delivery.md#zustellmodi). Der Report hat einen eigenen Betreff; ist er leer, ist der Titel des Reports der Betreff. Die Zustelleinstellungen nehmen deshalb keinen Betreff-Schlüssel wie `email_subject` oder `activity_subject`.

`Herald.notifyDigest` nimmt entgegen:

| Parameter | Bedeutung |
|---|---|
| `digest` | Der Name des Reports. Pflicht. |
| `actionParams` | Die Zustelleinstellungen, etwa `channel`, `chat_id` oder `delivery_mode`. Ohne Angabe ist der Kanal `telegram`. |
| `sendHour` | Optional. Der Report wird nur in dieser Stunde verschickt, von 0 bis 23 in der Zeitzone von CiviCRM. In jeder anderen Stunde gibt der Aufruf `skipped` zurück und sendet nichts, und ein Wert außerhalb von 0 bis 23 ist ein Fehler. Lassen Sie den Parameter weg, wenn Sie einen Report von Hand auslösen. |

```bash
cv api4 Herald.notifyDigest '{"checkPermissions": false, "digest": "weekly_report", "actionParams": {"chat_id": "-1001234567890"}}'
```

### Was eine Änderung kaputt macht

Regeln und Skripte wählen einen Report über seinen Namen. Nachdem Sie den Namen eines Reports geändert oder den Report gelöscht haben, scheitern sie mit der Fehlermeldung `Den Report „weekly_report“ gibt es nicht.`, bis sie einen anderen nennen. Die Regelliste zeigt dieselbe Meldung anstelle des Reporttitels. Den Titel können Sie jederzeit ändern.

Wird die Anzeige einer Quelle gelöscht, fehlt sie dem Report. Er scheitert dann beim Versand mit der Meldung, die auch das Formular zeigen würde. Wählen Sie eine andere Anzeige oder entfernen Sie die Quelle.

## Reports aus älteren Versionen

Ältere Versionen haben einen Report als Liste von Blöcken in den Einstellungen seiner Regelaktion oder seines geplanten Jobs abgelegt. Das Upgrade macht aus jedem einen Report-Datensatz und lässt in der Aktion nur die Zustelleinstellungen und den Namen des Reports stehen. Der Report einer Regelaktion heißt `rule_action_<id>` und übernimmt den Titel der Regel, der Report eines Jobs heißt `job_<id>` und übernimmt den Namen des Jobs. Sie können sie danach umbenennen. Wählen Sie den Report dann in jeder Regelaktion, die ihn verschickt hat, neu aus, und passen Sie die Jobs und Skripte an, die ihn nennen.

Jeder alte Report bekommt einen Text mit der Kopfzeile, den Blocktiteln und dem, was die Blöcke bisher ausgegeben haben: Summen, Aufschlüsselung, ausstehende Beträge, Kampagnenfortschritt und Histogramm. Aus den Blöcken werden Quellen:

- Ein Beitragsblock verwendet die mitgelieferten Suchen. Mit einem Filter oder einem anderen Zeitraum als gestern bekommt er Kopien davon mit der Bezeichnung `Herald: <Titel>: …`. Ein Kampagnenfilter setzt voraus, dass CiviCampaign aktiviert ist.
- Ein Suchblock bekommt eine neue Tabellenanzeige seiner Suche, mit den Spalten, der Sortierung und dem Limit der Suche und ohne Seitenumbruch.
- Ein Abschnittsblock behält seinen Schlüssel. Seine Extension muss bis dahin mit Werten antworten, siehe [Abschnitte aus anderen Extensions](#abschnitte-aus-anderen-extensions).
- Ein E-Mail-Betreff aus den Zustelleinstellungen wird der Betreff des Reports.

Das Upgrade behält bei, was der Report bisher gezählt hat:

- Ein unbekannter Zeitraum zählte bisher als „gestern“ und tut das weiterhin. Heute, die letzten 7 und die letzten 30 Tage werden zu Filtern der Kopien; die letzten Tage enden um Mitternacht vor dem Lauf.
- Histogrammgrenzen, die keine aufsteigenden Zahlen sind, entfallen mit einer Warnung im Upgrade-Protokoll, und das Histogramm wählt seine Klassen selbst. Eine negative Klassenzahl wird zu 1, die Klassenzahl 0 zum Standardwert 8, mit einer Warnung.
- Ein Statusfilter „Completed“ entfällt, denn die Suchen zählen abgeschlossene Beiträge.
- Ein nach Posten aufgeschlüsselter Block zählt weiter Posten: Summen, ausstehende Beträge und Kampagnenfortschritt kommen aus Suchen auf Posten, die Anzahl ist die verkaufte Menge, und ein Finanztypfilter wählt die Posten dieses Typs, auch aus einem gemischten Warenkorb.
- Ein Block mit einer eigenen Vorlage bekommt stattdessen den Standardtext, mit einer Warnung im Upgrade-Protokoll. Übernehmen Sie Ihren Wortlaut in den Text des Reports.

Die alten Vorlagen „Herald: Daily contribution digest“, „Weekly contribution digest“, „Contribution digest block (composable)“ und „Digest block from a search display“ entfallen.

Ein Report, der sich nicht nachbilden lässt, bleibt, wie er war. Das Upgrade-Protokoll enthält die Warnung „digest left inline, it cannot run until fixed“ mit dem Grund, es wird nichts für ihn angelegt, und die Regel oder der Job scheitert, bis sie einen Report-Datensatz nennt. Die meisten dieser Reports hätten nie laufen können:

- Eine Blockliste, die kein JSON-Array von Objekten ist, oder ein Block unbekannten Typs.
- Ein Abschnittsblock ohne Schlüssel oder einer, dessen Extension noch mit Text oder gar nicht antwortet.
- Eine gespeicherte Suche, die es nicht mehr gibt oder deren Entität mit ihrer Extension verschwunden ist.
- Eine Kampagne oder ein Finanztyp, die es nicht mehr gibt.

Die übrigen verlangen etwas, das Report-Datensätze nicht können:

- Eine gespeicherte Suche, die ihre ersten Zeilen überspringt. Eine Anzeige beginnt immer mit der ersten Zeile.
- Ein anderer Statusfilter als „Completed“. Dazu gehört auch ein Filter „Completed“ in einem Report, der die ausstehenden Beträge ohne Aufschlüsselung nach Posten gezeigt hat, denn dort galt der Filter auch für die ausstehenden Beträge.

Eine verzögerte Aktion, die während des Upgrades schon in der CiviRules-Warteschlange stand, trägt noch die alten Einstellungen und scheitert einmal. Beim nächsten Auslösen der Regel verwendet sie die neuen Einstellungen.

Hatte ein Report Betreff und Text in einer Nachrichtenvorlage, kopiert das Upgrade beides in den Report. Die Vorlage bleibt unter Mailings » Nachrichtenvorlagen stehen; löschen Sie sie, sobald nichts anderes sie verwendet.

Das Formular für Reports ist ein Afform, herald braucht daher die Extension Form Core (`org.civicrm.afform`). Aktivieren Sie sie, bevor Sie herald auf einer Seite aktualisieren, die sie noch nicht hat.

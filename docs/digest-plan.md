# Plan: Digests aus Suchen und einer Vorlage

Entwurf vom 14.10.2026, am selben Tag umgesetzt; das Handbuch (`handbook/digests.md`) beschreibt den gebauten Stand. Gemessen auf CiviCRM 6.19 mit Smarty 5 im Dev-Stack.

## Worum es geht

Ein Digest ist eine Nachricht, die herald zu festen Zeiten verschickt, etwa jeden Morgen um sieben eine Übersicht über die Beiträge vom Vortag. Version 0.10.0 speichert den Inhalt eines Digests in den Parametern der CiviRules-Aktion oder des geplanten Jobs. Der Inhalt besteht aus Blöcken:

- ein **Beitragsblock** mit eigenen Filtern, Zeitraum, Aufteilung nach Quelle oder Position, offenen Beiträgen und Histogramm,
- ein **Suchblock**, der die Zeilen einer gespeicherten Suche ausgibt,
- ein **Abschnitt**, den eine andere Extension über `hook_civicrm_heraldDigestSections` als fertiges HTML liefert.

Jeder Block hat seine eigene Nachrichtenvorlage, dazu kommen eine Kopfzeile als HTML und ein Blocktitel. Der noch nicht veröffentlichte Stand auf `main` hat diese Blöcke in eigene Datensätze verschoben (`HeraldDigest`, `HeraldDigestBlock`) und ein Afform-Formular dazu gebaut.

Daran stören zwei Dinge:

1. **Text steht an zwei Orten.** Kopfzeile und Blocktitel stehen im Digest-Formular, der Rest in mehreren Vorlagen. Wer den Text eines Digests ändern will, muss beide Orte kennen.
2. **Der Beitragsblock baut nach, was SearchKit kann.** Summen, Anzahlen, Gruppierungen und Zeiträume sind rund 1000 Zeilen eigener Code in `Civi/Herald/Digest/`, mit eigenen Filtern und eigener Zeitraum-Logik.

## Das Ziel in einem Satz

Ein Digest ist eine Liste von Quellen (gespeicherte Suchen oder Abschnitte von Extensions) und genau eine Nachrichtenvorlage, die den ganzen Text enthält.

| | heute | künftig |
|---|---|---|
| Text | Kopfzeile, Blocktitel, eine Vorlage pro Block | eine Vorlage pro Digest, Betreff inklusive |
| Daten | Beitragsblock, Suchblock, Abschnitt | Suche oder Abschnitt |
| Zeitraum | Feld am Digest und am Block | Filter in der Suche |
| Reihenfolge | Reihenfolge der Blöcke | Reihenfolge in der Vorlage |
| Histogramm | Schalter am Beitragsblock | Modifier in der Vorlage |
| Quellnamen zusammenfassen | im Beitragsblock | entfällt, die Suche gruppiert nach Formular oder Finanztyp |

## Datenmodell

`HeraldDigest` behält Name und Titel und bekommt eine Vorlage. Kopfzeile und Zeitraum fallen weg.

| Feld | Typ | Bemerkung |
|---|---|---|
| `name` | varchar(64), eindeutig | Rules, Jobs und Skripte nennen den Digest beim Namen |
| `title` | varchar(255) | |
| `message_template_id` | FK auf `MessageTemplate`, ON DELETE SET NULL | beim Speichern Pflicht |

`HeraldDigestBlock` heißt künftig `HeraldDigestSource`. Eine Quelle hat keine Position mehr, die Reihenfolge steht in der Vorlage. Umbenennen kostet jetzt nichts, weil die Tabelle noch nirgends ausgeliefert ist.

| Feld | Typ | Bemerkung |
|---|---|---|
| `digest_id` | FK, ON DELETE CASCADE | |
| `name` | varchar(64) | Name in der Vorlage: `{$spenden…}`; `^[a-z][a-z0-9_]*$`, eindeutig pro Digest |
| `type` | `search` oder `section` | |
| `search_display_id` | FK auf `SearchDisplay`, ON DELETE SET NULL | bei `search` Pflicht |
| `section_key` | varchar(128) | bei `section` Pflicht, z. B. `myext.seats` |

Die Spalte heißt `name` und nicht `key`, weil `key` in MySQL ein reserviertes Wort ist.

Fehlt die Vorlage oder die Suchanzeige, weil jemand sie gelöscht hat, schlägt der nächste Versand mit einer Meldung fehl (siehe „Fehler beim Versand“).

Beide Entities bekommen den Trait `ManagedEntity`. Dann kann eine Extension einen Digest samt Quellen, Vorlage und Job in mgd-Dateien ausliefern, statt ihn nur in der Datenbank einer Seite zu haben. Eine Quelle zeigt dort mit `digest_id.name` auf ihren Digest, der Digest mit `message_template_id.msg_title` auf seine Vorlage (siehe „Messungen“).

## Die Vorlage

Die Vorlage ist eine normale, vom Benutzer angelegte Nachrichtenvorlage in CiviCRM, keine Systemvorlage. Man legt sie unter den Nachrichtenvorlagen an und wählt sie im Digest-Formular aus. herald liest Betreff (`msg_subject`) und HTML-Text (`msg_html`). Für Kanäle ohne HTML macht herald daraus wie bisher Klartext.

Für die Vorlage gelten die Regeln, die herald schon heute für alle Vorlagen hat: Zeichenketten aus den Quellen werden HTML-escaped, bevor sie in die Vorlage kommen. Eigenes HTML in der Vorlage bleibt HTML.

herald rendert jede Vorlage mit Cores `UserContentPolicy`, wie Core es bei Nachrichtenvorlagen tut (`CRM_Utils_String::parseOneOffStringThroughSmarty()`). Bisher rendert herald ohne sie. Gemessen: Ohne die Richtlinie führt `{crmAPI entity="Domain" action="getcount"}` in einer herald-Vorlage die Abfrage aus, mit ihr bricht Smarty ab („Tag 'crmAPI' is not allowed in secure mode“). Alle Fälle unter „Prüfen“ und eigene Modifier laufen auch mit der Richtlinie. Das gilt für alle herald-Vorlagen, nicht nur für Digests.

Ein `email_subject` in den Versandeinstellungen einer Digest-Aktion hat heute Vorrang vor dem Betreff der Vorlage (`ChannelConfig::withSubject()`). Für Digests fällt das Feld weg, damit auch der Betreff nur an einem Ort steht. Das Upgrade verschiebt einen gesetzten Wert in die Vorlage. Für Einzelbenachrichtigungen bleibt alles, wie es ist.

Ein Beispiel:

```
📊 <b>Tagesreport {"yesterday"|date_format:"%d.%m.%Y"}</b>

Eingang: <b>{$spenden.summe|crmMoney}</b> ({$spenden.anzahl})
{foreach from=$nach_formular.rows item=row}• {$row.formular|default:"ohne Formular"}: {$row.summe|crmMoney} ({$row.anzahl})
{/foreach}
🏦 Offen: {$offen.summe|crmMoney} ({$offen.anzahl})
<pre>{$betraege.rows|herald_histogram:"betrag"}</pre>
{if $kampagne.ziel gt 0}Kampagne: {($kampagne.summe * 100 / $kampagne.ziel)|round:1} % von {$kampagne.ziel|crmMoney}{/if}

Plätze: {$plaetze.gesamt} gebucht, davon {$plaetze.bezahlt} bezahlt
```

Datum, Rechnen und Formatieren erledigt Smarty selbst. Gemessen: `{"yesterday"|date_format:"%d.%m.%Y"}` ergibt das Datum von gestern, und `{($a * 100 / $b)|round:1}` rechnet. Ist `$b` 0, bricht Smarty mit `DivisionByZeroError` ab, deshalb steht die Rechnung hinter einem `{if}`. Vergleiche schreibt man als `gt`, `lt` und `and`: CKEditor macht aus `>` ein `&gt;` und aus `&&` ein `&amp;&amp;`, und daran scheitert Smarty. Gemessen mit CKEditor 4.22 im Vorlagenformular, im Quelltextmodus wie in der normalen Ansicht. Core scheitert an solchen Vorlagen genauso.

## Platzhalter

### Aus einer Suche

herald führt die Suchanzeige wie bisher mit `SearchDisplay::run` aus, als System und mit `acl_bypass`. Wer einen Digest einrichtet, kann damit jede Anzeige verschicken. Das ist dasselbe Vertrauen wie bei den Beitragsabfragen heute.

Die Namen der Werte kommen aus den Spaltenüberschriften der Anzeige. SearchKit vergibt die internen Spaltennamen selbst (`SUM_total_amount`) und bietet keine Möglichkeit, sie umzubenennen (`crmSearchFunction.component.js`, `makeAlias()`). Die Überschrift dagegen setzt man ohnehin.

Aus der Überschrift wird der Name so:

- klein geschrieben,
- ä, ö, ü und ß werden zu ae, oe, ue und ss,
- alles andere außer Buchstaben und Ziffern wird zu `_`,
- führende und doppelte `_` fallen weg.

„Summe“ wird `summe`, „Plätze gesamt“ wird `plaetze_gesamt`. Eine Spalte ohne Überschrift bekommt keinen Namen und erscheint nicht unter den Platzhaltern.

Eine Suchquelle liefert:

| Platzhalter | Inhalt |
|---|---|
| `{$spenden.summe}` | Wert der ersten Zeile. Gedacht für Suchen mit einer Zeile, etwa Summen. Ohne Zeilen ist jeder Name gesetzt und NULL. |
| `{$spenden.rows}` | alle Zeilen, jede mit denselben Namen: `{$row.summe}` |

Die Werte sind Rohwerte aus dem `data`-Teil des Ergebnisses, keine fertig formatierten Texte. Gemessen: Ein Auswahlfeld wie `financial_type_id:label` kommt dort schon als Text an, eine Summe als Zahl. Formatiert wird in der Vorlage, etwa mit `|crmMoney`. So kann man mit den Werten auch rechnen. Fehlende Werte setzt herald auf NULL statt auf einen leeren String: Gemessen bricht `{($x * 100)}` mit `TypeError` ab, wenn `$x` ein leerer String ist, mit NULL ergibt es 0.

Beim Speichern lehnt herald ab:

- zwei Spalten mit demselben Namen,
- eine Spalte, deren Name `rows` ist,
- einen Namen, der mit einer Ziffer beginnt, weil Smarty `.1_quartal` als Index liest.

Wer eine Überschrift ändert, ändert den Platzhalter. Das Digest-Formular zeigt deshalb zu jeder Quelle die aktuellen Namen.

Eine Anzeige mit Limit liefert nur so viele Zeilen. herald übernimmt das Limit der Anzeige. Die mitgelieferte Anzeige für Einzelbeträge hat kein Limit.

### Aus einer Extension

Der Hook bleibt, liefert aber Werte statt HTML:

```php
function myext_civicrm_heraldDigestSections(array $keys, array $context, array &$sections): void {
  if (in_array('myext.seats', $keys, TRUE)) {
    $sections['myext.seats'] = ['gesamt' => 138, 'bezahlt' => 120];
  }
}
```

Hat die Quelle den Namen `plaetze`, steht in der Vorlage `{$plaetze.gesamt}`. Erlaubt sind skalare Werte und Listen von Zeilen mit skalaren Werten. Eine Extension, die noch einen String liefert, ist ein Fehler mit klarer Meldung. Es gibt keine Übergangslösung, die beides annimmt. `$context` enthält nur noch `digest`, weil der Digest keinen Zeitraum mehr hat.

Für die Platzhalterliste im Formular fragt herald den Hook genauso und liest die Namen aus der Antwort.

## Histogramm

herald meldet vor dem Rendern einen Modifier `herald_histogram` bei Smarty an. Gemessen: `registerPlugin('modifier', …)` funktioniert. Die `UserContentPolicy` schränkt unter Smarty 5 keine Modifier ein (`createSmartyPolicy5()`).

```
<pre>{$betraege.rows|herald_histogram:"betrag"}</pre>
<pre>{$betraege.rows|herald_histogram:"betrag":8}</pre>
<pre>{$betraege.rows|herald_histogram:"betrag":"0,10,50,250"}</pre>
```

- **Eingabe:** die Zeilen einer Suche und der Name der Spalte mit den Beträgen.
- **Klassen- oder Grenzwahl:** Das zweite Argument ist entweder die Zahl der Klassen (Vorgabe 8) oder eine Liste fester Grenzen.
- **Berechnung:** Ohne feste Grenzen berechnet der Modifier runde Grenzen aus den Daten, wie der Beitragsblock heute. Dafür bleiben `HistogramEdges`, `HistogramBuckets`, `HistogramFormatter`, `NiceNumber` und `BucketEdges` erhalten.
- **Ausgabe:** Smarty escaped Modifier-Ausgaben nicht. Die Grafik enthält kein `<`, `>` oder `&`, die untere Klasse heißt `0–10` statt `<10`. So passt sie unverändert in HTML, Text und Betreff.
- **Fehler:** Eine unbekannte Spalte ist ein Fehler, keine leere Ausgabe.

## Prüfen beim Speichern und beim Versand

herald rendert die Vorlage einmal mit den echten Daten der Quellen. Smarty meldet Fehler auf zwei Wegen (gemessen):

| Fehler | Meldung |
|---|---|
| Syntaxfehler, z. B. `{if …}` ohne `{/if}` | `Smarty\CompilerException` mit Zeile |
| Tippfehler im Wertnamen, `{$spenden.sume}` | PHP-Warnung `Undefined array key "sume"` |
| unbekannte Quelle, `{$spende.summe}` | PHP-Warnung `Trying to access array offset on null`, ohne Namen |
| Rechenfehler, z. B. Division durch null | `DivisionByZeroError` oder `TypeError` |

herald fängt während des Renderns Warnungen und Notices ab (keine Deprecations) und macht aus jeder einen Fehler mit dieser Meldung. Dabei gilt (gemessen):

- **`error_reporting` setzen.** Core stellt Smarty nur bei Debug, auf `localhost` und in Unit-Tests auf `E_ALL` (`CRM_Core_Smarty`). Auf einer Produktivseite gilt sonst die globale Einstellung. herald setzt für die Dauer des Renderns `E_ALL`.
- **`error_reporting()` im Handler beachten.** Smarty schaltet beim Schreiben kompilierter Vorlagen Warnungen ab (`Smarty::writeFile()`), und `@` tut dasselbe. Was dabei entsteht, ist kein Fehler der Vorlage.
- **`error_unassigned` an.** Sonst bleibt `{$plaetze}` ohne passende Quelle stumm leer. Mit der Einstellung meldet Smarty „Undefined variable $plaetze“. `{if isset($x)}` und `{if empty($x)}` auf Unbekanntes melden auch dann nichts. `{$x|default:"…"}` auf eine unbekannte Variable meldet etwas, und das stimmt so, denn jede Quelle ist immer gesetzt.
- **Nur das Rendern.** Die Quellen lädt herald vorher, der Handler sieht nur Smarty und die Modifier. `parseOneOffStringThroughSmarty()` taugt dafür nicht: Es setzt einen eigenen Handler, und Warnungen gehen an ihm vorbei an PHP (gemessen).
- **CKEditor-Hinweis.** Ist der Fehler ein Syntaxfehler und steht in einem Smarty-Tag `&gt;`, `&lt;` oder `&amp;`, nennt die Meldung die Ursache und `gt`, `lt` und `and` als Abhilfe.

Damit das keinen falschen Alarm gibt, habe ich die gesunden Fälle gemessen. Keiner davon warnt:

- `{if !empty($x.y)}` und `{if isset($x.y)}` für optionale Werte,
- `{foreach}` über eine leere Liste,
- `|crmMoney`, `|date_format` und `|round` mit NULL oder leerem String,
- eine Suche ohne Zeilen, weil jeder Name trotzdem gesetzt ist.

Geprüft wird an drei Stellen:

1. **Digest speichern** im Formular (Afform-Submit): Vorlage gegen die Quellen. Über APIv4 und mgd entstehen Digest und Quellen als getrennte Datensätze, der Digest zuerst. Beim Speichern des Digests gibt es seine Quellen also noch nicht. APIv4-Validate prüft deshalb nur die Felder: Name, Vorlage gesetzt, Art und Pflichtfeld der Quelle, die Platzhalternamen der Anzeige.
2. **Vorlage speichern**: gegen jeden Digest, der sie nutzt. Eine Vorlage, die kein Digest nutzt, prüft herald nicht. Im Dev-Stack ist neben dem klassischen Formular die Extension `message_admin` installiert, die Vorlagen über APIv4 speichert. Eigene Vorlagen speichert das klassische Formular, Systemvorlagen `message_admin` über APIv4. herald hängt sich in beide ein (`hook_civicrm_validateForm` und APIv4-Validate auf `MessageTemplate`).
3. **Versand**: dieselbe Prüfung. Eine Überschrift, die jemand in SearchKit umbenennt, fällt erst hier auf.

Beim Speichern einer Vorlage hält herald nur Fehler der Vorlage auf. Lässt sich eine Quelle gerade nicht laden, etwa weil ihre Anzeige gelöscht ist, prüft herald diesen Digest nicht und lässt die Vorlage speichern. Sonst könnte man die Vorlage nicht bearbeiten, solange eine Suche kaputt ist. Der Versand meldet den Fehler dann.

### Fehler beim Versand

Heute landet ein fehlgeschlagener Digest nur im Log. CiviRules fängt die Exception in `CRM_Civirules_Engine` und schreibt sie weg. Wer auf den Bericht wartet, merkt nur, dass er fehlt.

Schlägt das Rendern fehl, schickt herald an dieselben Empfänger eine kurze Nachricht:

> ⚠️ Digest „Tagesreport“ konnte nicht erstellt werden: Platzhalter `{$spenden.sume}` gibt es nicht.

Danach wirft herald die Exception weiter, damit sie auch im CiviRules-Log steht. Die Nachricht enthält nur den Titel und den Grund, keine Daten. Scheitert schon der Versand selbst, bleibt es beim Log.

## Mitgelieferte Suchen

herald liefert Suchen und Anzeigen als mgd-Dateien mit (`managed/DigestSearches.mgd.php`). Suchen sind in CiviCRM echte verwaltete Einträge, also wirkt `update => unmodified`: Wer eine mitgelieferte Suche ändert, behält die Änderung auch nach einem Update von herald. Bei Nachrichtenvorlagen ist das nicht so. Deshalb liefert herald keine Beispielvorlage mit, sie würde bei jedem Update überschrieben.

| Suche | Filter | Spalten |
|---|---|---|
| Beiträge ‹Zeitraum› | abgeschlossen, Eingang im Zeitraum | Summe, Anzahl |
| Beiträge ‹Zeitraum› nach Formular | wie oben, gruppiert nach Spendenformular | Formular, Summe, Anzahl |
| Beiträge ‹Zeitraum› nach Finanztyp | wie oben, gruppiert nach Finanztyp | Finanztyp, Summe, Anzahl |
| Offene Beiträge | ausstehend, ohne Zeitraum | Summe, Anzahl |
| Positionen ‹Zeitraum› | Positionen abgeschlossener Beiträge, gruppiert nach Bezeichnung und Preis | Position, Preis, Menge, Summe |
| Einzelbeträge ‹Zeitraum› | eine Zeile pro Beitrag, kein Limit | Betrag |
| Aktivitäten ‹Zeitraum› nach Art und Status | Datum im Zeitraum, ohne gelöschte und Testaktivitäten | Art, Status, Anzahl |
| Überfällige Aktivitäten nach Art | geplant, Datum vor jetzt | Art, Anzahl |

‹Zeitraum› ist „gestern“ (`previous.day`) und „letzte Woche“ (`previous.week`). Die Woche beginnt mit der CiviCRM-Einstellung „Wochenbeginn“. Gemessen mit der Vorgabe Sonntag: Am Mittwoch, 14.10.2026, ergibt `previous.week` den 4. bis 10.10. Die mgd-Datei erzeugt die Suchen aus dieser Liste, ein weiterer Zeitraum ist ein Eintrag mehr. Die Überschriften sind in beiden Zeiträumen gleich, ein Wochenbericht hat also dieselben Platzhalter wie ein Tagesbericht.

- **Testbeiträge und Vorlagen:** Alle Suchen lassen Testbeiträge und Vorlagen für wiederkehrende Beiträge weg, wie die Abfragen heute.
- **CiviContribute:** Die Beitragssuchen sind nur deklariert, solange CiviContribute aktiv ist, so wie die CiviRules-Handler nur mit CiviRules. Die Aktivitätensuchen gibt es immer.
- **Keine Kontaktdaten:** Keine mitgelieferte Suche gibt Namen oder Adressen aus, nur Zahlen, Arten und Status. Was ein Digest darüber hinaus verschickt, entscheidet, wer ihn einrichtet.
- **Kampagnenstand:** Dafür liefert herald keine Suche mit, weil CiviCampaign nicht auf jeder Seite aktiv ist. Das Beispiel dafür steht im Handbuch.
- **Übersetzung:** Die Überschriften sind übersetzt (`E::ts`). Auf einer deutschen Seite heißen die Platzhalter `summe`, auf einer englischen `sum`. Formular und Handbuch zeigen jeweils die passenden Namen.
- **Andere Zeiträume:** Für jeden anderen Zeitraum kopiert man eine Suche in SearchKit und ändert den Filter. Der Digest selbst hat kein Zeitraumfeld: Nur die Suche weiß, auf welchem Datumsfeld der Zeitraum liegt (`receive_date`, `activity_date_time`). Gemessen: APIv4 löst `previous.day` beim Ausführen in den Bereich von gestern auf (`FormattingUtil::formatDateValue()`).

## Formular

Das Afform-Formular `afformHeraldDigest` wird kleiner:

- **Oben:** Name, Titel und die Vorlage, eine Auswahl über die eigenen Nachrichtenvorlagen.
- **Darunter die Quellen:** Name, Art, dazu Suchanzeige oder Abschnitt.
- **Neben jeder Quelle:** ihre Platzhalter und ein fertiger Textbaustein zum Kopieren, etwa `Eingang: <b>{$spenden.summe|crmMoney}</b> ({$spenden.anzahl})`. Eine neue APIv4-Action liefert beides aus den Spalten der Anzeige oder aus der Antwort des Hooks.
- **Ganz unten** eine Vorschau mit den heutigen Daten. Sie nutzt dasselbe Rendern wie Prüfung und Versand.

Die Direktiven `heraldDigestBlock` und `heraldDigestBlockType` werden umbenannt und verlieren alles, was den Beitragsblock betrifft. `FormValidator` bleibt, weil Afform Exceptions beim Speichern verschluckt.

## Rechte

- **Daten:** Quellen festlegen und Digests bearbeiten bleibt bei „CiviCRM verwalten“.
- **Text:** Die Vorlage darf bearbeiten, wer eigene Nachrichtenvorlagen bearbeiten darf.

Die Vorlage holt sich keine Daten selbst. Ein Tag wie `{herald_search name="…"}` würde jedem, der Vorlagen bearbeitet, Zugriff auf jede Suche geben, auch auf Adressen. Core sperrt aus demselben Grund `{crmAPI}` in Vorlagen (`CRM_Core_Smarty_UserContentPolicy::$disabled_tags`).

## Upgrade von 0.10.0

Upgrade 1011 liest die Parameter weiter so, wie 0.10.0 sie gelesen hat: CiviRules-Aktionen auf den CiviRules-Tabellen, auch abgelöste Digest-Handler, und Jobs so, wie der Job-Manager sie parst. Pro Digest legt es an:

1. einen `HeraldDigest` mit Name und Titel wie bisher,
2. eine eigene Nachrichtenvorlage „Herald: ‹Titel›“. Ihr Text ist die alte Kopfzeile, danach pro Block der Titel fett und die Zeilen des Blocks mit den neuen Platzhaltern. Ihr Betreff ist ein gesetztes `email_subject`, sonst der Betreff, den der einzige Block hatte, sonst der Titel.
3. Quellen und Suchen:

| alter Block | wird zu |
|---|---|
| Beitragsblock ohne Filter, Zeitraum gestern | die mitgelieferten Suchen (vorher `CRM_Herald_Upgrader_Reconcile::run()`, damit sie existieren) |
| Beitragsblock mit Kampagne, Finanztyp, Quellmuster oder anderem Zeitraum | eigene Kopien der Suchen mit diesen Filtern |
| Aufteilung nach Quelle | Aufteilung nach Formular |
| Aufteilung nach Position | Positionen, mit offenen Positionen, wenn offene Beiträge an waren |
| offene Beiträge an | Suche „Offene Beiträge“ mit den Filtern des Blocks |
| Histogramm an | Suche „Einzelbeträge“ und eine Zeile mit `herald_histogram`, Klassenzahl oder feste Grenzen übernommen |
| Kampagne gesetzt | Suche auf den Kampagnenstand und die Zeile mit dem Prozentwert |
| Suchblock | eine neue Tabellenanzeige wie heute (`CRM_Herald_Upgrader_InlineSearch`), die Zeilen mit den Spaltennamen in der Vorlage |
| Abschnitt | eine Quelle `section`. herald fragt den Hook beim Upgrade und schreibt eine Zeile pro gelieferten Wert. Liefert die Extension noch einen String, bleibt der Digest mit Warnung inline. |

Zeiträume:

| alt | neu |
|---|---|
| `yesterday` | `previous.day` |
| `today` | `this.day` |
| `last_7_days` | `DATEDIFF(CURDATE(), receive_date) BETWEEN 1 AND 7` |
| `last_30_days` | `DATEDIFF(CURDATE(), receive_date) BETWEEN 1 AND 30` |

0.10.0 zählte bei `last_7_days` von heute vor 7 Tagen, 0 Uhr, bis heute, 0 Uhr. Dafür hat Core keinen festen Begriff: `ending.week` schließt heute ein, und `previous_7.day` gibt es nicht. `>= "today - 7 days"` rechnet APIv4 richtig, SearchKit zeigt den Filter aber als leeres Datumsfeld an (gemessen, mit Fehler in der Browser-Konsole), und wer ihn dort ausfüllt, ersetzt ihn. `DATEDIFF` zeigt SearchKit als „Days between two dates“ mit den Grenzen 1 und 7 an, und Speichern erhält ihn. Die mitgelieferten Wochensuchen passen nicht: `previous.week` ist die Kalenderwoche, `last_7_days` zählt vom Versandtag zurück. Das Upgrade legt dafür Kopien an, damit sich kein Bericht unbemerkt ändert.

Was das Upgrade nicht übernimmt:

- **Eigene Blockvorlagen:** Hatte ein Block eine eigene Vorlage, nimmt das Upgrade den Standardtext und schreibt eine Warnung mit dem Namen der alten Vorlage ins Log. Die Variablen der alten Vorlagen (`$totals`, `$by_source`) gibt es nicht mehr, umschreiben lässt sich das nicht zuverlässig.
- **Quellnamen:** Die Zusammenfassung nach Quellname wird zur Aufteilung nach Formular.

Ein Digest, der sich danach nicht zusammensetzen lässt, bleibt mit Warnung inline; die Transaktion nimmt alles zurück, was für ihn angelegt wurde. Ein Kampagnenfilter braucht CiviCampaign, weil APIv4 `Contribution.campaign_id` sonst ausblendet (gemessen).

Die bisherigen Vorlagen `herald_contribution_digest_daily`, `…_weekly`, `…_block` und `herald_digest_search_block` fallen aus `managed/MessageTemplate.mgd.php` heraus. Mit `cleanup => unused` löscht der nächste Abgleich sie.

## Code

Fällt weg, rund 500 Zeilen in `Civi/Herald/Digest/`:

- `DigestVars`, `ContributionBlock`, `DateRange`, `SourceBreakdown`, `LineItemBreakdown`,
- die Zeiträume und Aufteilungen in `Options`,
- die Abfragen für Zeitraum, Quelle, Positionen und Verteilung in `ContributionStatsService`,
- die Felder des Beitragsblocks im Schema, im Formular und in `BlockType`.

Bleibt:

- **`SourceNormalizer` und die Quellregeln.** Die Benachrichtigungen zu einzelnen Beiträgen nutzen sie (`ContributionLookups`).
- **Die Kampagnensumme.** Der Kampagnenstand in Einzelbenachrichtigungen braucht sie (`ContributionVars`). Sie wird eine Abfrage in `Civi\Herald\Trigger\CampaignProgress`, `ContributionStatsService` und `ContributionFilters` fallen weg. `SourceNormalizer` zieht ebenfalls nach `Civi\Herald\Trigger`.
- **Die Histogramm-Klassen**, für den Modifier.
- **Weitere Klassen**, die umgebaut werden: `Composer`, `Definition`, `SearchBlock` (wird `SearchSource`, `SearchRows` geht darin auf), `ExtraSections`, `DigestRules`, `Validator` und `FormValidator`.

Neu:

- **`Civi\Herald\Digest\Placeholders`**: Namen aus Überschriften, Werte pro Quelle, Textbausteine.
- **`Civi\Herald\Digest\TemplateCheck`**: Rendern mit Abfangen der Warnungen.
- **der Modifier**,
- **die APIv4-Actions** für Platzhalter und Vorschau,
- **die mitgelieferten Suchen**,
- **die Prüfung beim Speichern einer Vorlage**,
- **der Trait `ManagedEntity`** an `HeraldDigest` und `HeraldDigestSource`.

## Bewusst nicht gebaut

- **Zwei Modi für Einsteiger und Experten.** Der einfache Weg sind die mitgelieferten Suchen und die Textbausteine im Formular. Ein zweiter Modus hieße zweiter Code, zweite Tests, zweite Doku, und wer einmal umschaltet, kommt nicht zurück.
- **Quellnamen zusammenfassen im Digest.** Ginge als eigene SQL-Funktion, die die Regeln in `CASE` übersetzt. Regex-Regeln verhalten sich aber in PHP und MySQL unterschiedlich. Formular und Finanztyp sind feste Felder und brauchen das nicht.
- **Eine Spalte für den Kampagnen-Prozentwert.** Gemessen: APIv4-Gleichungen lehnen Aggregate im Inneren ab, `(SUM(total_amount) * 100 / …)` ergibt `Illegal sql expression`. Die Vorlage rechnet.
- **Strenges Smarty für fehlende Variablen.** Die Prüfung beim Speichern und beim Versand fängt dieselben Fälle ab, ohne Smarty umzustellen.

## Messungen

Bereits gemessen (14.10.2026, Dev-Stack):

- **Zeitraum:** relative Zeiträume in `where` und `today - 7 days`,
- **`SearchDisplay::run`:** Rohwerte in `data`, Auswahlfelder als Text,
- **Smarty:**
  - Modifier anmelden,
  - Rechnen in der Vorlage, `date_format` mit `"yesterday"`,
  - Meldungen bei Tippfehlern, Syntaxfehlern und Division durch null,
  - die gesunden Fälle ohne Warnung,
- **APIv4-Gleichungen:** lehnen Aggregate im Inneren ab.

Im Container und im Browser gemessen (14.10.2026):

- **CKEditor:** `>`, `<` und `&&` werden zu Entities, Anführungszeichen bleiben. Siehe „Die Vorlage“.
- **Verweis auf die Vorlage:** `DAOActionTrait::resolveFKValues()` löst `message_template_id.msg_title` für jedes FK-Feld auf und verlangt genau einen Treffer. Das mgd-php-Mixin legt die Einträge in der Reihenfolge der sortierten Dateien und darin in Array-Reihenfolge an, die Vorlage steht also vor dem Digest. Ein Headless-Test legt so einen Digest über `CRM_Core_ManagedEntities::reconcileDeclarations()` an.
- **Zeiträume in SearchKit:** siehe „Upgrade von 0.10.0“.
- **Abfangen der Warnungen:** siehe „Prüfen beim Speichern und beim Versand“.
- **Doppeltes Anmelden:** Ein zweites `registerPlugin()` wirft `Smarty\Exception`. herald meldet den Modifier nur an, wenn `getRegisteredPlugin()` ihn noch nicht kennt.

## Reihenfolge

1. Schema, Entities, `Composer`, Platzhalter, Modifier, Richtlinie und Prüfung, mit Tests.
2. Mitgelieferte Suchen, mit einem Test, der jede ausführt.
3. Formular mit Platzhaltern, Textbausteinen und Vorschau.
4. Upgrade 1011, mit Tests aus Parametern von 0.10.0 für jede Zeile der Tabellen oben.
5. Handbuch anpassen: `digests.md`, `digests.de.md` und `reference*.md`.
6. `ck ci` mit Coverage, danach Review-Runden.

Extensions, die `hook_civicrm_heraldDigestSections` beantworten, müssen im selben Release auf Werte umgestellt werden.

## Entscheidungen

- **Fehler beim Versand:** herald schickt den Empfängern die kurze Meldung und wirft danach weiter, für jeden Digest.
- **Aufteilung beim Upgrade:** nach Formular. Bei Online-Beiträgen stand das Formular meist in der Quelle, das kommt der alten Aufteilung am nächsten.
- **Zeiträume:** herald liefert die Suchen für „gestern“ und „letzte Woche“ mit. Der Digest hat kein Zeitraumfeld, andere Zeiträume sind Kopien einer Suche.
- **Allgemein:** Der Digest-Code kennt keine Entity. Was für Beiträge gilt, gilt für Aktivitäten und jede andere Suche genauso.

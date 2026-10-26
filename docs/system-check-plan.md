# Plan: Der Check-Monitor liest System.check

Entwurf vom 25.10.2026, noch nicht umgesetzt. Gemessen auf CiviCRM 6.19.2 Standalone (civikitchen, PHP 8.4, Extension-Verzeichnisse als Bind-Mounts) mit herald 0.12.0 und elf weiteren Extensions, die Checks liefern.

## Worum es geht

herald hat einen Check-Monitor. Er ruft bei jedem Cron-Lauf Checks auf, merkt sich deren Zustand und meldet Wechsel (ok → fail, fail → ok) an ein Topic, standardmäßig `ops`. Die Checks kommen über herald's eigenen Hook `hook_civicrm_heraldChecks`.

CiviCRM hat für Checks schon ein eigenes Framework: `hook_civicrm_check`, die API `System.check`, die Statusseite und das Stummschalten über `StatusPreference`. Der Monitor läuft daran vorbei. Daraus folgen drei Probleme:

1. **Doppelte Buchführung.** outpost, shuttle, signet, sunset und warden implementieren beide Hooks. Ihr herald-Adapter liest die eigenen Core-Meldungen noch einmal, filtert sie nach Stufe und fasst sie zu einem einzigen herald-Check zusammen.
2. **Checks ohne Statusseite.** Einige Checks gibt es nur im Monitor, Admins sehen sie nicht auf der Statusseite und können sie dort nicht stummschalten:
   - die Checks von pulse, sentinel und matomo,
   - herald's eigene Puffer-Checks,
   - `signet.queue` (Tiefe der Render-Queue),
   - warden's Abruf der JWKS-Schlüssel.

   pulse hat deshalb eine eigene Ignorierliste für Updates gebaut.
3. **Core-Checks nur als Sammelmeldung.** Andere Core-Checks erreichen den Monitor nur, wenn pulse aktiv ist. Das gilt auch für die Checks von flare, logpolicy und mailrouter. Selbst dann kommen sie nur zusammengefasst als `pulse.system` an, standardmäßig erst ab Stufe critical.

## Das Ziel in einem Satz

Extensions liefern Checks nur noch über `hook_civicrm_check`, und der Monitor beobachtet die Meldungen von `System.check` pro Name.

| | heute | künftig |
|---|---|---|
| Liste der Checks | `hook_civicrm_heraldChecks` | `hook_civicrm_check` (Core) |
| Ergebnis | `ok`, `fail` oder `skipped` und ein Detailtext | Core-Meldung mit Name, Stufe, Titel und HTML-Text |
| Granularität | ein herald-Check fasst oft mehrere Meldungen zusammen | eine Meldung ist ein Check |
| ganzen Check stummschalten | `herald_monitor_snooze` | Core-`StatusPreference` (Statusseite und herald) oder `herald_monitor_snooze` (nur herald) |
| einzelnen Punkt stummschalten | nur in pulse | im Check, der die Punkte kennt |
| Statusseite | nur Checks, die beide Hooks haben | alle Checks |

## Was Core schon kann

Im Quelltext von 6.19 nachgesehen und im Stack ausprobiert:

- **Namen.** Jede Meldung hat einen Namen. Core nimmt dafür `__FUNCTION__`, teils mit festem Suffix (`checkExtensionsUpdates`) oder einer Objekt-ID (`checkCaseTypeNameConsistency<Typ>`). Die Namen hängen nicht von der Sprache ab: Im Stack lieferten `en_US` und `de_DE` dieselben Namen.
- **Stufen.** `severity_id` reicht von 0 (debug) über 3 (warning) bis 7 (emergency). Gesunde Checks melden oft gar nichts. Manche melden `info`, etwa „PHP Up-to-Date“.
- **Stummschalten.** Mit `StatusPreference` lässt sich ein Name stummschalten:
  - bis zu einem Datum (`hush_until`),
  - oder solange die Stufe nicht über `ignore_severity` steigt.

  `System.check` liefert die Meldung weiter, aber mit `is_visible = 0`. Für Extension-Checks funktioniert das genauso, im Stack geprüft an `signet_typst_missing` und `warden_redirect_uri`. Meldungen auf Stufe debug und info lassen sich nicht stummschalten. Mit `is_active = 0` lässt sich eine ganze Check-Methode abschalten, dann liefert sie keine Meldung mehr.
- **Filter.** `System.check` mit `where name IN [...]` gibt die Namen an `CRM_Utils_Check::checkStatus()` weiter. Core bricht ab, sobald alle angefragten **Methoden** gelaufen oder übersprungen sind. Ein Name wie `checkExtensionsUpdates` ist aber keine Methode, für ihn greift der frühe Abbruch nicht. Der Hook `hook_civicrm_check` bekommt die Namen als `$statusNames`, aber keine der eigenen Extensions wertet ihn aus.

Was Core nicht kann:

- **Einzelne Punkte stummschalten.** Alle verfügbaren Extension-Updates stehen in *einer* Meldung `checkExtensionsUpdates`, immer auf Stufe warning, mit einer HTML-Liste. Schaltet man sie stumm, bis die Stufe steigt, bleiben alle künftigen Updates stumm, denn die Stufe steigt nie.
- **Zustandswechsel.** Core kennt nur den Zustand jetzt. Erkennung von Wechseln, Wartezeit, Erinnerung und Zustellung bleiben die Aufgabe von herald.

## Messungen

| Messung | Ergebnis |
|---|---|
| `System.check` voll, frischer Prozess, 5 Läufe | 2,0–2,4 s |
| davon `LegacyEntityScanner::check` (Core) | etwa 2,0 s |
| `System.check` voll, im selben Prozess ein zweites Mal | 180–270 ms |
| langsamste Core-Komponente | `checkTokens`, 66 ms |
| alle Core-Komponenten zusammen | etwa 400 ms (kalt) |
| `hook_civicrm_check` aller eigenen Extensions zusammen | etwa 31 ms |
| herald's heutiger Collector (23 Checks) | 14–350 ms |
| `System.check` gefiltert auf einen Core-Methodennamen | 85–120 ms |
| Debug-Modus aus statt an | kein messbarer Unterschied |

**Woher die 2 Sekunden kommen.** `LegacyEntityScanner::check` ruft `ClassScanner::get()` für alle APIv4-Entitäten auf. In 6.19 fehlt in `ClassScanner::cache()` ein `break`, sodass der persistente Index-Cache sofort durch einen `ArrayCache` ersetzt wird. Der Index wird deshalb in jedem PHP-Prozess neu gebaut. Upstream ist das in `b56a068918` behoben („Make the classscanner cache work“, 30.09.2026). Der Fix ist im Zweig 6.20 enthalten, nicht in 6.19. Mit Bind-Mounts ist die Zahl ein oberer Wert für das Scannen selbst.

**Laufzeit pro Monitor-Lauf.** Auf 6.19 kostet ein Monitor-Lauf etwa 2 s zusätzlich, im Cron-Prozess und nicht bei einem Seitenaufruf. Bei einem Cron-Lauf alle 5 Minuten sind das etwa 10 Minuten am Tag. Ab 6.20 sollte nur noch ein kalter oder abgelaufener Cache den Index neu bauen.

Den schlimmsten Fall bestimmen nicht die Core-Checks, sondern Checks, die externe Prozesse oder Dienste aufrufen. Der Check von signet startet typst, mit bis zu 10 s Timeout, und matomo fragt einen HTTP-Dienst ab. Das trifft heute schon den Monitor und künftig zusätzlich die Statusseite (siehe „Offene Fragen“).

Ein eigener Takt für den Monitor ist nicht nötig.

## Entwurf

### 1. Quelle

Der Collector ruft `System.check` ohne Filter auf. Jede Meldung wird eine Beobachtung unter ihrem Namen:

- **fail**, wenn die Stufe die Schwelle erreicht (Abschnitt 2);
- **ok** sonst.

Ein Name, der im gespeicherten Zustand steht, aber in diesem Lauf keine Meldung liefert, gilt als ok. So meldet der Monitor die Erholung von Checks, die im gesunden Zustand schweigen. Zustand führt herald nur für Namen, die schon einmal gescheitert sind.

**Schlüssel und Detail.** Manche Namen tragen hinter einem Doppelpunkt ein Detail, das sich ändern kann, ohne dass es ein anderer Befund wird. Core baut so `checkExtensionsRequired:<fehlende Extensions>`. herald teilt jeden Namen am ersten `:`. Der Teil davor ist der Schlüssel, der Rest das Detail; ein Name ohne Doppelpunkt ist sein eigener Schlüssel. Zustand, Schwellen und Stummschalten hängen am Schlüssel. Ändert sich nur das Detail eines gemeldeten Fehlers, geht eine Meldung „geändert“ mit dem neuen Inhalt raus statt einer Entwarnung und eines neuen Fehlers. Liefert ein Lauf mehrere Meldungen mit demselben Schlüssel, zählt die höchste Stufe, und das Detail ist die sortierte Liste ihrer Details.

Den Meldungstext vergleicht herald nicht. Texte wie der von `checkLastCron` enthalten Zeitangaben, das ergäbe in jedem Lauf eine Meldung.

Wird eine Extension deinstalliert oder eine Check-Methode über `is_active = 0` abgeschaltet, kommt höchstens eine Entwarnung für ihre offenen Fehler. Das ist hinnehmbar.

**Regeln für die eigenen Extensions:**

- **Nicht prüfen können ist ein Fehler.** Ein Check, der nicht prüfen konnte, liefert eine Meldung auf Stufe error („konnte nicht prüfen: …“). Er schweigt nicht und verschluckt keine Exception. warden verschluckt heute Exceptions einzelner Prüfungen (`Civi/Warden/StatusCheck.php`) und wird dabei umgestellt.
- **Befund vor dem Doppelpunkt.** Was den Befund benennt, steht im Namen vor dem Doppelpunkt, was sich an ihm ändert, dahinter. Eine ID, die einen eigenen Befund bezeichnet, bleibt Teil des Schlüssels (`warden_discovery_<id>`).
- **Letztes Ergebnis behalten.** Hängt ein Check von einem Abruf ab, etwa dem Extension-Feed, liefert er bei einem gescheiterten Abruf das letzte bekannte Ergebnis weiter, unter demselben Namen. Den gescheiterten Abruf meldet er als eigene Meldung. Sonst würde ein Ausfall des Abrufs einen offenen Fehler als erholt melden.

Core-Checks halten sich nicht an diese Regeln. Fällt ein Abruf von Core aus, kann eine Meldung verschwinden. Der Monitor meldet dann eine Erholung und später denselben Fehler neu. Das sind zwei überflüssige Meldungen pro Ausfall, das ist hinnehmbar. Die wichtigste Meldung dieser Art, `checkExtensionsUpdates`, ersetzt ohnehin pulse (Abschnitt 4).

Die Zustandsmaschine (`TransitionPlanner`) bleibt, mit einer Erweiterung: **Eine sichtbare Eskalation oder Änderung wird sofort gemeldet.** Steigt die Stufe eines schon gemeldeten Fehlers, etwa von warning auf error, oder ändert sich sein Detail, und ist die Meldung sichtbar (`is_visible = 1`), geht eine Meldung raus, ohne auf die Erinnerung zu warten. Eine stummgeschaltete Meldung bleibt still, solange Core sie verbirgt oder herald sie stummschaltet (Abschnitt 3). Dafür merkt sich der Zustand Stufe und Detail der letzten Meldung; verglichen wird immer mit diesen.

### 2. Schwelle

- `herald_monitor_min_severity`: globale Schwelle, Standard `warning`.
- `herald_monitor_severity`: Ausnahmen als Map Schlüssel → Stufe (Abschnitt 1). Ein `*` am Ende passt auf ein Präfix, weil manche Namen eine ID tragen (`warden_discovery_<id>`, `sunset_stuck_<policy>`). Der längste passende Eintrag gewinnt.

Die niedrigste erlaubte Schwelle ist `notice`. Meldungen auf info und debug zeigen gesunde Zustände an („PHP Up-to-Date“), lassen sich nicht stummschalten und wären sonst Dauerfehler. Beim Speichern lehnt herald niedrigere Werte ab.

Heute gelten die Schwellen pro Extension: warden ab error, outpost, shuttle, signet und sunset ab warning, `pulse.system` ab critical. Mit `warden_*: error` bleibt warden gleich. Die übrigen Core-Checks melden künftig ab warning statt ab critical. Das ist gewollt, die Vorschau (Abschnitt 8) macht es vor der Umstellung sichtbar.

### 3. Ganze Checks stummschalten

Es gibt zwei Ebenen:

- **Core (Statusseite und herald):** Eine `StatusPreference` blendet die Meldung auf der Statusseite aus, `System.check` liefert sie mit `is_visible = 0`.
- **Nur herald:** `herald_monitor_snooze` schaltet einen Namen nur für den Monitor stumm. Die Statusseite zeigt die Meldung weiter. Die Einstellung bleibt, verwendet aber die Schlüssel der Core-Namen: Schlüssel → Datum, ohne Datum dauerhaft. Ein Snooze gilt also für jedes Detail. Ein `*` am Ende passt auf ein Präfix wie in Abschnitt 2. Abgelaufene Einträge räumt der Lauf weg.

Für herald ist eine Meldung stumm, wenn eine der beiden Ebenen sie stummschaltet. Dann wechselt der Zustand, aber ohne Meldung. Läuft das Stummschalten ab, wird der Fehler gemeldet. Eine Erholung nach einem nie gemeldeten Fehler bleibt still. Eine Erholung nach einem gemeldeten Fehler wird auch dann gemeldet, wenn der Check inzwischen stummgeschaltet ist. Die Entwarnung schließt die Meldung, die schon draußen ist.

### 4. Einzelne Punkte stummschalten

Ein Check, der aus Punkten besteht, schaltet einzelne Punkte selbst stumm, über eigene Einstellungen. Beispiele sind Updates, Jobs und Queues. Nur er kennt die Punkte. herald kann das nicht generisch lösen, weil eine Core-Meldung Text ist und keine Liste.

Für Updates heißt das:

- **Meldung:** pulse liefert eine eigene Meldung für **Extension**-Updates, mit Ignorierliste und Zurückstellung bis zum nächsten Release.
- **Datenquelle:** pulse liest die verfügbaren Versionen direkt aus dem Extension-Mapper und dem Extension-Browser von Core. Über `System.check` geht das nicht mehr, sonst ruft der Check sich selbst auf (heute `CRM_Pulse_Check_Updates::coreMessages()`).
- **Core-Meldung ausblenden:** Die Core-Meldung `checkExtensionsUpdates` blendet pulse dauerhaft aus. Dafür sorgt eine `StatusPreference` für diesen Namen in der aktuellen Domain:
  - `ignore_severity` = 3 (warning; in APIv4 `ignore_severity:name = warning`). Die Meldung ist immer warning und bleibt damit stumm.
  - `hush_until` = NULL. Mit einem abgelaufenen Datum würde die Meldung wieder sichtbar.
  - Besteht schon eine Preference für den Namen, wird sie überschrieben.
- **Nicht die ganze Methode:** Die Methode `checkExtensions` darf pulse nicht abschalten, sonst gehen auch `checkExtensionsRequired` und `checkExtensionsError` verloren.
- **Nur zusammen:** Ausblenden und Ersatz gehören zusammen. Die Preference existiert genau dann, wenn der Update-Check von pulse aktiv ist, also nicht mit `pulse_updates_min_severity = off`. Beim Abschalten des Checks und beim Deaktivieren oder Deinstallieren von pulse verschwindet sie. Sonst meldet niemand mehr Extension-Updates.
- **Mechanismus:** eine Managed Entity für `StatusPreference`, die das `.mgd.php` nur deklariert, wenn `pulse_updates_min_severity` nicht `off` ist. Parameter: `match: [domain_id, name]`, `update: always`, `cleanup: always`, und in `values` ausdrücklich `is_active: TRUE`. Ein `post_change`-Callback der Einstellung ruft `Managed.reconcile` für pulse auf. `on_change` taugt dafür nicht, es läuft vor dem Speichern und sähe den alten Wert. Im Stack geprüft (6.19.2), Sichtbarkeit jeweils über `CRM_Utils_Check_Message::isVisible()`:

  | Schritt | Preference | Core-Meldung |
  |---|---|---|
  | pulse aktivieren, eine Admin-Preference besteht | übernommen und überschrieben (`ignore_severity` 3, `hush_until` NULL) | stumm |
  | Einstellung `off` | gelöscht | sichtbar |
  | Einstellung wieder `warning` | neu angelegt | stumm |
  | pulse deaktivieren | `is_active` 0, `ignore_severity` 0 | sichtbar |
  | pulse wieder aktivieren | `ignore_severity` 3, `is_active` 1 | stumm |
  | pulse deinstallieren | gelöscht | sichtbar |

  Ohne `is_active: TRUE` in `values` bleibt die Preference nach Deaktivieren und Aktivieren auf `is_active` 0 stehen. Für einen Namen, der keine Check-Methode ist, wertet Core `is_active` nicht aus, sauber ist es trotzdem. `post_change` vergleicht alten und neuen Wert mit `!=`: Eine Boolean-Einstellung mit Standard `TRUE`, die nie gespeichert war, löst beim ersten Setzen auf `FALSE` keinen Callback aus (`0 == NULL`). Die String-Einstellung von pulse ist davon nicht betroffen.
- **Core-Updates:** Updates von CiviCRM selbst (`checkVersion_*`) bleiben Core-Meldungen. Es gibt nur ein Core, da braucht es keine einzelnen Punkte, und Core stuft Sicherheitsupdates selbst höher ein.

### 5. Sprache

Meldungen laufen zur Laufzeit durch `ts()`. Der Monitor stellt vor `System.check` mit `\Civi\Core\Locale::negotiate(<Sprache der Seite>)->apply()` die Sprache der Seite ein und danach die alte wieder her. So ist die Sprache gleich, ob der Lauf aus dem Cron kommt oder ein Admin den Job von Hand startet. Im Stack geprüft: Core-Titel wechseln damit („Outbound Email Disabled“ → „Ausgehende E-Mails deaktiviert“).

Eine eigene Spracheinstellung für den Monitor gibt es erst, wenn jemand sie braucht. Die Namen, an denen der Zustand hängt, ändern sich mit der Sprache nicht.

Lücke: signet und warden haben kein `l10n/`. Ihre Titel bleiben auch auf deutschen Seiten englisch.

### 6. Text

Core-Meldungen enthalten HTML mit Links und Listen. herald escaped jede Variable in den HTML-Text der Nachricht, ein HTML-Text käme also als sichtbare Tags an. Der Collector wandelt den Text deshalb mit dem vorhandenen `PlainText::fromHtml()` in reinen Text um, bevor er ihn als Detail weitergibt.

Den Titel der Meldung führt der Planner als eigenes Feld mit. Die Vorlage `herald_monitor_alert` bekommt ihn als Variable. Links gehen verloren, die vollständige Meldung steht auf der Statusseite.

### 7. Erwartete Extensions

`herald_monitor_expected` listet heute Check-Namen und meldet sie als Fehler, wenn sie fehlen. Künftig ist ein fehlender Name nicht mehr von einem gesunden Check zu unterscheiden. Die Einstellung listet deshalb Extension-Schlüssel. Ist eine davon nicht installiert und aktiv, meldet der Monitor den Pseudo-Check `herald_expected_<key>` als Fehler.

### 8. Vorschau

Beim ersten Lauf mit der neuen Quelle gilt jeder bestehende Fehler als neu und geht als Meldung raus. Auf einer gewachsenen Seite können das ein Dutzend längst bekannter Warnungen sein.

Die API-Action `Herald.monitorPreview` zeigt deshalb, welche Schlüssel der nächste Lauf melden würde, mit Detail, Stufe und Titel. Sie sendet nichts und schreibt keinen Monitor-Zustand. `System.check` selbst kann schreiben: `checkLastCron` legt zum Beispiel eine `StatusPreference` an. Das ist Core-Verhalten und harmlos.

Der Betreiber schaltet bekannte Warnungen auf der Statusseite oder nur in herald stumm und aktiviert erst danach die neue Version.

### 9. Upgrade

Ein Upgrade-Schritt von herald:

- **Zustand:** `herald_monitor_state` wird geleert. Die alten Namen (`pulse.updates`, `signet.config`) gibt es nicht mehr. Ohne das Leeren kämen Entwarnungen für Checks, die nur umbenannt wurden.
- **Snoozes:** `herald_monitor_snooze` wird geleert. Alte Snoozes lassen sich nicht übertragen, weil die Namen nicht zueinander passen. Die Vorschau zeigt die neuen Namen, unter denen neu stummgeschaltet wird.
- **Erwartete Checks:** Die alten Namen in `herald_monitor_expected` werden in Extension-Schlüssel übersetzt. Dafür gibt es eine feste Tabelle der bekannten Präfixe: `pulse.` → `pulse`, `matomo.` → `matomo`, `warden.` → `warden` und so weiter für die neun Extensions. Ein unbekanntes Präfix wird unverändert als Extension-Schlüssel übernommen. Ist es keine installierte Extension, meldet der Monitor es als fehlend. Der Eintrag fällt also nicht still weg, und der Betreiber korrigiert ihn sichtbar.

Ablauf auf einer Seite:

1. Den Job „Herald: Run check monitor“ deaktivieren.
2. herald, pulse, sentinel, matomo, signet und warden gemeinsam aktualisieren, danach die übrigen Extensions.
3. `Herald.monitorPreview` ausführen und bekannte Warnungen auf der Statusseite oder nur in herald stummschalten.
4. Den Job wieder aktivieren.

Ohne Schritt 1 kann der Monitor zwischen Upgrade und Vorschau mit leerem Zustand alle bestehenden Fehler melden.

### 10. Verwaltungsoberfläche

Die Monitor-Seite von herald bearbeitet heute Check-Namen und Snoozes. Betroffen sind `Civi/Herald/Admin/MonitorChecks.php`, `MonitorSettings.php`, die Actions `getMonitorChecks`/`saveMonitorChecks` und `ang/heraldAdmin/monitor.js`. Künftig zeigt sie:

- die Schlüssel, die `System.check` liefert, und die Schlüssel im Zustand, mit Detail, Stufe, Sichtbarkeit und Monitor-Zustand;
- die Schwelle und die Ausnahmen (Abschnitt 2);
- das Stummschalten nur in herald pro Schlüssel oder Präfix, mit Datum oder dauerhaft (Abschnitt 3);
- die erwarteten Extensions;
- einen Link auf die Statusseite zum Stummschalten in Core.

## Was wegfällt

- `hook_civicrm_heraldChecks`, ohne Übergangsweg;
- die Adapter in neun Extensions und die Einträge `known_hooks: heraldChecks` in deren `civikitchen.yaml`;
- `pulse.system`, das ist `System.check` selbst;
- `pulse.cron` im Monitor. Der Monitor läuft im Cron, er kann einen stehenden Cron nicht melden. Diese Aufgabe hat der Endpunkt von pulse, der den Check weiter nutzt. `checkLastCron` von Core ist kein Ersatz: Es läuft nur in der Umgebung `Production` und kennt keine einstellbare Grenze.

Mit dem Code ändern sich auch die Doku-Stellen:

- die Scope-Regeln in `CLAUDE.md` von herald, pulse und sentinel;
- die Beschreibung des Jobs „Herald: Run check monitor“ in `managed/Job.mgd.php`;
- `handbook/monitor.md` und `handbook/reference.md` mit den deutschen Fassungen.

## Umstellung pro Extension

| Extension | heute im Monitor | Umbau |
|---|---|---|
| herald | `herald.buffer_backlog`, `herald.buffer_dead_letter` | als Core-Checks; `civirules_version_check` und `disabled_rules_check` sind es schon |
| pulse | 13 Checks | 11 Checks als Core-Checks; `pulse.system` und `pulse.cron` fallen weg (siehe oben); Updates nach Abschnitt 4; der Endpunkt bleibt |
| sentinel | eine Meldung pro Regel, nur im Monitor | jede Regel als Core-Meldung `sentinel_<regel>` |
| matomo | `matomo.reachable`, nur im Monitor | als Core-Check; macht einen HTTP-Aufruf, daher Ergebnis cachen, sonst kostet jeder Aufruf der Statusseite eine Anfrage an Matomo |
| signet | `signet.config`, `signet.queue`, `signet.jobs` | Adapter löschen; die Queue-Tiefe (über 500) als eigene Core-Meldung |
| warden | `warden.config` ab error, dazu `warden.provider.<name>` mit JWKS-Abruf | Adapter löschen; JWKS-Abruf und Schlüsselprüfung als Core-Meldungen; Ausnahme `warden_*: error`; verschluckte Exceptions als error-Meldung (Abschnitt 1) |
| outpost, shuttle, sunset | Adapter fasst eigene Core-Meldungen zusammen | Adapter löschen; shuttle prüft Profil-Maschinennamen auf `^[a-z0-9_]+$` wie sunset, damit kein Name einen Doppelpunkt trägt (Abschnitt 1) |
| flare, logpolicy, mailrouter | nur über `pulse.system` ab critical | nichts zu tun |

Dazu kommen die Tests, die die Adapter direkt aufrufen. Etwa `sentinel/tests/phpunit/Civi/Sentinel/HeraldChecksHeadlessTest.php`, `pulse/tests/phpunit/CRM/Pulse/Check/ChecksHeadlessTest.php`, `shuttle/tests/phpunit/DemoScenarioHeadlessTest.php` und `warden/tests/phpunit/Civi/Warden/StatusCheckHeadlessTest.php`. Sie prüfen künftig die Core-Meldungen.

Jede Extension bekommt einen Release. Bis eine Extension umgestellt ist, fehlen ihre Checks, die nur im Monitor existierten. Deshalb gehen herald, pulse, sentinel, matomo, signet und warden gemeinsam auf eine Seite.

## Tests

- **Gesund:** Ein Lauf ohne Meldungen oberhalb der Schwelle erzeugt keine Meldung. Das ist der Test gegen Fehlalarme.
- **Gesund mit info-Meldungen:** „PHP Up-to-Date“ und andere info-Meldungen erzeugen auch bei der niedrigsten Schwelle keinen Fehler.
- Eine Meldung oberhalb der Schwelle führt zu fail. Ihr Verschwinden führt zu ok.
- Eine stummgeschaltete Meldung (`is_visible = 0`) ändert den Zustand ohne Meldung.
- Ein Snooze nur in herald (Name, Präfix, dauerhaft) ändert den Zustand ohne Meldung; `System.check` liefert die Meldung weiter sichtbar.
- Eine sichtbare Eskalation von warning auf error bei gemeldetem Fehler meldet sofort. Eine stummgeschaltete meldet nicht.
- **Gesund mit Detail:** Ein gemeldeter Fehler mit gleichem Namen erzeugt über mehrere Läufe keine weitere Meldung, auch wenn sich sein Meldungstext ändert.
- Ändert sich das Detail eines gemeldeten Fehlers (`checkExtensionsRequired:a` → `checkExtensionsRequired:a,b`), geht genau eine Meldung „geändert“ raus, keine Entwarnung. Stummgeschaltet geht keine raus.
- Zwei Meldungen mit demselben Schlüssel ergeben die höchste Stufe und dasselbe Detail, unabhängig von ihrer Reihenfolge.
- Ein Snooze auf `checkExtensionsRequired` gilt für jedes Detail.
- pulse: Scheitert der Abruf des Extension-Feeds, bleibt die Update-Meldung mit dem letzten Ergebnis stehen, und der Abruf wird gesondert gemeldet.
- pulse: Die `StatusPreference` für `checkExtensionsUpdates` existiert genau dann, wenn der Update-Check aktiv ist.
- Präfix-Ausnahme: `warden_*: error` lässt eine warning von warden still.
- Sprache: Der Lauf liefert Titel in der Sprache der Seite, auch wenn der Prozess vorher eine andere hatte.
- Eine fehlende erwartete Extension meldet fail.
- Die Vorschau sendet nichts und schreibt keinen Monitor-Zustand.
- Upgrade: Alte erwartete Namen werden zu Extension-Schlüsseln, Zustand und Snoozes sind danach leer.
- pulse: Die Update-Meldung entsteht ohne Aufruf von `System.check`.

## Offene Fragen

- **Statusseite:** Wie teuer werden die Statusseite und der Status-Indikator in der Menüleiste, wenn mehr Extensions Core-Checks liefern? Kosten entstehen durch sentinel-Regeln (APIv4-Zählungen), signet (startet typst) und matomo (HTTP).

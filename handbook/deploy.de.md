---
title: Deployment
order: 70
---

# Deployment

Diese Seite erklärt, wie Sie herald auf einer laufenden Site aktualisieren, ohne dass während des Codewechsels etwas kaputtgeht.

Die CiviRules-Aktionen und -Bedingungen von herald laufen innerhalb des Speichervorgangs jedes Datensatzes, auf den ihre Regeln reagieren. Lässt sich eine ihrer Klassen nicht laden, schlägt dieser Speichervorgang fehl: Verloren geht dann der Beitrag, die Registrierung oder die Änderung am Event, nicht nur die Meldung. Die Skripte im Verzeichnis bin von herald schalten seine Regeln deshalb ab, während sich der Code ändert. Sie schalten die Regeln erst wieder ein, wenn sich jeder Handler laden lässt. Auf einer Site ohne CiviRules vermerkt jedes Skript, dass es nichts zu prüfen gibt, und läuft erfolgreich durch.

## Schritte

Ein Git-Checkout enthält kein Verzeichnis vendor. Erzeugen Sie es mit `composer install --no-dev`, bevor Sie den Code austauschen. Das Release-Archiv enthält es bereits.

Führen Sie die Skripte mit `cv scr` vor und nach dem Codewechsel aus und brechen Sie beim ersten fehlgeschlagenen Schritt ab:

1. Vor dem Austausch: rules-disable.php, dann `cv flush`.
2. Den Code der Extension austauschen.
3. Ausstehende Extension-Upgrades mit `cv ext:upgrade-db` ausführen.
4. preflight-rules.php.
5. Nur wenn der Preflight bestanden ist: rules-reenable.php.
6. Zum Schluss: note-foreign-rules.php.

```bash
HERALD=/var/www/example.org/ext/herald
cv scr $HERALD/bin/rules-disable.php && cv flush
# … Code der Extension austauschen …
cv ext:upgrade-db \
  && cv scr $HERALD/bin/preflight-rules.php \
  && cv scr $HERALD/bin/rules-reenable.php \
  && cv scr $HERALD/bin/note-foreign-rules.php
```

rules-reenable.php prüft nicht, ob der Preflight bestanden wurde. Führen Sie es also nie nach einem fehlgeschlagenen Preflight aus. Die Upgrades kommen vor dem Preflight, weil ein Upgrade Handler-Klassen umbenennen kann, die der Preflight anschließend lädt.

## Was die einzelnen Schritte tun

### Die Regeln abschalten

rules-disable.php sucht jede aktive Regel mit einem Trigger, einer Bedingung oder einer Aktion von herald. Es schreibt ihre IDs in die Datei herald_deploy_disabled_rules im privaten Verzeichnis von CiviCRM und schaltet diese Regeln dann ab. IDs, die ein früheres fehlgeschlagenes Deployment in der Datei hinterlassen hat, bleiben erhalten. Die Ausgabe lautet:

```
[herald deploy] disabled 4 herald rule(s) for the swap: 3,7,8,12 (marker now holds 4)
```

### Die Handler prüfen

preflight-rules.php lädt die Handler-Klassen so, wie CiviRules es tut. Es prüft, ob jede Klasse existiert, sich ohne Argumente erzeugen lässt und von der richtigen Basisklasse von CiviRules erbt. Ein Trigger ohne eigene Klasse muss ein Post-Trigger sein.

Es prüft die eigenen Handler von herald und jeden Handler, der an den abgeschalteten Regeln hängt: deren Trigger und alle ihre aktiven Bedingungen und Aktionen, auch solche anderer Extensions. Regeln, deren Trigger fehlt oder inaktiv ist, werden nur vermerkt, weil CiviRules sie nie auslöst. Ohne die Datei aus dem ersten Schritt prüft der Preflight nur die eigenen Handler von herald.

Bei Erfolg gibt es „[herald preflight] OK: N CiviRules handler(s) checked“ aus. Bei einem Fehler gibt es „[herald preflight] FAILED:“ aus und endet mit dem Exit-Code 1. Die Meldung nennt die Regel, die Art des Handlers, die Klasse und den Grund, gefolgt von dem Befehl, der die Regeln nach der Korrektur wieder einschaltet. Bis dahin bleiben die Regeln aus. Die möglichen Gründe sind:

- class not found (autoload failed)
- class failed to load, mit dem PHP-Fehler
- not instantiable
- does not extend the expected CiviRules class
- constructor requires arguments
- constructor threw an exception
- trigger names no class and is not a post trigger

### Die Regeln wieder einschalten

rules-reenable.php schaltet die vermerkten Regeln wieder ein, entfernt die Datei und gibt „[herald deploy] re-enabled N herald rule(s)“ aus.

### Andere Regeln prüfen

note-foreign-rules.php läuft, sobald die herald-Regeln wieder an sind. Es ist der einzige Schritt, der die Klassen von Regeln ohne herald-Handler lädt, und meldet fehlerhafte Handler auf stderr, ohne fehlzuschlagen. Ein Handler einer anderen Extension, der an einer herald-Regel hängt, gehört nicht zu diesem Schritt: Ihn prüft der Preflight, und er verhindert, dass die Regeln wieder eingeschaltet werden. Eine Klasse, die PHP beim Laden abstürzen lässt, etwa eine mit einer nicht implementierten abstrakten Methode, lässt dagegen diesen letzten Schritt fehlschlagen und markiert das Deployment als fehlgeschlagen. Die herald-Regeln sind dann bereits wieder an, und dieser Fehler schaltet sie nicht wieder ab.

## Wenn etwas schiefgeht

- Eine Klasse von herald, die sich überhaupt nicht laden lässt, bringt PHP spätestens im Preflight zum Absturz. Der Preflight endet dann mit einem Fehler, und die herald-Regeln bleiben aus. Bei kaltem Klassen-Cache kann schon ein früherer Schritt, der CiviCRM startet, auf den Absturz stoßen. `cv flush` zeigt die Klasse und die Datei.
- Solange die Datei aus dem ersten Schritt existiert, zeigt der Systemstatus von CiviCRM die Warnung „Herald: Regeln durch einen Deploy abgeschaltet“. Sie listet die IDs der Regeln und den Befehl auf, der sie wieder einschaltet.
- Das nächste erfolgreiche Deployment schaltet die Regeln aus einem fehlgeschlagenen Deployment wieder ein. Das gilt auch für eine Regel, die ein Admin in der Zwischenzeit absichtlich abgeschaltet hat.
- Ein Release, das einen Handler ausmustert, stellt die Regeln auf dessen Nachfolger um, nicht aber die Warteschlange der verzögerten Aktionen von CiviRules. Eine vor dem Deployment eingereihte Aktion, die einen ausgemusterten Handler nennt, schlägt bei ihrer Ausführung fehl, und CiviRules protokolliert das. Führen Sie das Deployment aus, wenn keine verzögerte Aktion von herald wartet.

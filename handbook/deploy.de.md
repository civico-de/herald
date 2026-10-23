---
title: Deployment
order: 70
---

# Deployment

Diese Seite erklärt, wie Sie herald auf einer laufenden Site aktualisieren, ohne dass während des Codewechsels etwas kaputtgeht.

Die CiviRules-Aktionen und -Bedingungen von herald laufen innerhalb des Speichervorgangs jedes Datensatzes, auf den ihre Regeln reagieren. Lässt sich eine ihrer Klassen nicht laden, schlägt dieser Speichervorgang fehl: Verloren geht dann der Beitrag, die Anmeldung oder die Änderung am Event, nicht nur die Meldung. Deshalb schalten die Skripte in `bin/` der Extension die herald-Regeln ab, solange der Code ausgetauscht wird. Sie schalten die Regeln erst wieder ein, wenn sich jeder Handler laden lässt. Auf einer Site ohne CiviRules vermerkt jedes Skript, dass es nichts zu prüfen gibt, und läuft erfolgreich durch.

## Schritte

Ein Git-Checkout enthält kein Verzeichnis vendor. Erzeugen Sie es mit `composer install --no-dev`, bevor Sie den Code austauschen. Das Release-Archiv enthält es bereits.

Führen Sie die Skripte mit `cv scr` vor und nach dem Codewechsel aus und brechen Sie beim ersten fehlgeschlagenen Schritt ab:

1. Vor dem Austausch: rules-disable.php, dann `cv flush`.
2. Den Code der Extension austauschen.
3. Ausstehende Extension-Upgrades mit `cv ext:upgrade-db` ausführen.
4. preflight-rules.php.
5. Nur nach erfolgreichem Preflight: rules-reenable.php.
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

rules-reenable.php prüft nicht, ob der Preflight erfolgreich war. Führen Sie es also nie nach einem fehlgeschlagenen Preflight aus. Die Upgrades kommen vor dem Preflight, weil ein Upgrade Handler-Klassen umbenennen kann, die der Preflight anschließend lädt.

Das Upgrade auf benannte Kanäle überführt die bisherigen Kanaleinstellungen, etwa das Telegram-Bot-Token, in Kanäle, die nach ihrem Typ benannt sind: `webhook`, `ntfy`, `email` und `activity`, dazu `telegram` und `pushover`, wo deren Token gesetzt war. Es richtet jede Regelaktion, jeden geplanten Job, jede Topic-Route und jede wartende Meldung, die sich auf eine Einstellung verlassen hat, auf diese Kanäle aus. Token und Header, die eine Regelaktion, ein Job, eine Route oder eine wartende Meldung mitgebracht hat, werden zu einem eigenen Webhook-Kanal, benannt mit `webhook_` und einem Suffix; Zustellungen mit gleichen teilen ihn sich. Andere Kanaleinstellungen in einer Zustellung, etwa `email_from`, wurden dort nie gelesen und entfallen. Prüfen Sie danach Administrieren » Herald » Kanäle. Ein Wert aus `civicrm.settings.php` hat Vorrang vor dem gespeicherten und geht ebenfalls in den Kanal; entfernen Sie diese Zeilen danach, denn herald liest sie nicht mehr. Auf einer Site mit mehreren Domains bekommt jede Domain Kanäle aus ihren eigenen Einstellungen, und die Webhook-Kanäle von Regelaktionen und wartenden Meldungen gibt es in jeder Domain, die eine URL zum Senden hatte: die eigene URL der Zustellung oder sonst die Default-URL der Domain. Den Kanal eines Jobs gibt es nur in der Domain des Jobs. Webhook-Header, die kein JSON-Objekt sind, ließen schon bisher jeden Versand scheitern; ihr Kanal entsteht ohne sie und ausgeschaltet, und das CiviCRM-Log nennt ihn. Setzen Sie die Header im Kanalformular und schalten Sie ihn ein.

Das Upgrade auf abonnierbare Topics macht aus jedem Abo-Typ ein gleichnamiges Topic, das Kontakte abonnieren können, in jeder Domain und mit der Bezeichnung des Typs. Das gilt für Ihre eigenen Typen, für jeden Typ, den ein Kontakt abonniert hat, und für jeden Typ, den eine Zustellung nennt; ein von herald mitgelieferter Typ, den niemand abonniert hat und nichts nennt, entfällt. Auch ein Typ, den Sie deaktiviert hatten, wird ein angebotenes Topic, damit Zustellungen an seine Abonnent:innen weiterlaufen; nehmen Sie den Haken „Kontakte dürfen dieses Topic abonnieren“ heraus, wenn Kontakte ihn nicht mehr sehen sollen. Ein bereits vorhandenes Topic dieses Namens behält seine Route und wird abonnierbar. Ein `subscription_type` an einer Zustellung, die nicht im Abonnent:innen-Modus war, wurde nie gelesen und entfällt. Jede Regelaktion, jeder geplante Job, jede Topic-Route und jede wartende Meldung, die einen Abo-Typ genannt hat, nennt stattdessen das Topic in `subscribers_of`; eine Route an die Abonnent:innen ihres eigenen Topics braucht keinen Schlüssel. Der `subscription_type` von `default` wird dessen `subscribers_of`, das Routen im Abonnent:innen-Modus weiter erben. Ein angebotenes Topic erreicht seine eigenen Abonnent:innen; eines, das bisher die von `default` genannten erreichte, nennt sie deshalb im eigenen Eintrag. Die gespeicherten Abonnements bleiben unverändert. Das CiviCRM-Log zählt die angelegten Topics und die umgeschriebenen Zustellungen. Prüfen Sie danach Administrieren » Herald » Topic-Routen: Ein neues Topic hat keine eigene Route und läuft über die Route default.

Das Upgrade auf mehrere Abos pro Kontakt macht aus den Benachrichtigungseinstellungen jedes Kontakts eine Zeile in „Benachrichtigungs-Abos“: den gewählten Kanal, das Ziel aus dem Feld zum Typ dieses Kanals, die Abonnements als Topics, den Bezug, und „Gruppennachrichten“ gesetzt, damit Zustellungen an Gruppen den Kontakt weiter auf demselben Weg erreichen. Ein Kontakt mit Kanal, aber ohne Abonnements erhält ebenfalls eine solche Zeile. Ein Kontakt mit Abonnements, aber ohne Kanal hat bisher nichts erhalten und bekommt keine Zeile. Ziele in den Feldern anderer Kanaltypen wurden nie gelesen und werden nicht übernommen; das CiviCRM-Protokoll zählt sie pro Feld, ohne Werte. Hat eine Installation mit mehreren Domains einen Kanalnamen mit verschiedenen Typen, entscheidet der Typ der Domain mit der kleinsten ID, welches Feld übernommen wird. Danach entfallen die alte Feldgruppe „Benachrichtigungseinstellungen“ und ihre Tabelle, und das Profil „My notification preferences“ stellt auf die neuen Zeilen um. Bis das Upgrade gelaufen ist, lesen Zustellungen die alten Einstellungen; zwischen dem Code-Update und dem Upgrade geht also keine Meldung verloren.

Am Tag des Upgrades kann ein Tagesreport, der schon verschickt wurde, ein zweites Mal kommen, und Meldungen, die vor dem Upgrade gepuffert wurden, kommen in einer eigenen Nachricht, getrennt von den danach gepufferten.

## Was die einzelnen Schritte tun

### Die Regeln abschalten

rules-disable.php sucht jede aktive Regel mit einem Trigger, einer Bedingung oder einer Aktion von herald. Es schreibt ihre IDs in die Datei herald_deploy_disabled_rules im privaten Verzeichnis von CiviCRM und schaltet diese Regeln dann ab. IDs, die ein früheres fehlgeschlagenes Deployment in der Datei hinterlassen hat, bleiben erhalten. Die Ausgabe lautet:

```
[herald deploy] disabled 4 herald rule(s) for the swap: 3,7,8,12 (marker now holds 4)
```

### Die Handler prüfen

preflight-rules.php lädt die Handler-Klassen so, wie CiviRules es tut. Es prüft, ob jede Klasse existiert, sich ohne Argumente erzeugen lässt und von der richtigen Basisklasse von CiviRules erbt. Ein Trigger ohne eigene Klasse muss ein Post-Trigger sein.

Es prüft die eigenen Handler von herald und jeden Handler, der an den abgeschalteten Regeln hängt: deren Trigger und alle ihre aktiven Bedingungen und Aktionen, auch solche anderer Extensions. Regeln, deren Trigger fehlt oder inaktiv ist, werden nur vermerkt, weil CiviRules sie nie auslöst. Ohne die Datei aus dem ersten Schritt prüft der Preflight nur die eigenen Handler von herald.

Bei Erfolg gibt das Skript „[herald preflight] OK: N CiviRules handler(s) checked“ aus. Bei einem Fehler gibt es „[herald preflight] FAILED:“ aus und endet mit dem Exit-Code 1. Die Meldung nennt die Regel, die Art des Handlers, die Klasse und den Grund, gefolgt von dem Befehl, der die Regeln nach der Korrektur wieder einschaltet. Bis dahin bleiben die Regeln aus. Die möglichen Gründe sind:

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

note-foreign-rules.php läuft, sobald die herald-Regeln wieder an sind. Es ist der einzige Schritt, der die Klassen von Regeln ohne herald-Handler lädt, und meldet fehlerhafte Handler auf stderr, ohne fehlzuschlagen. Ein Handler einer anderen Extension, der an einer herald-Regel hängt, gehört nicht zu diesem Schritt: Ihn prüft schon der Preflight, und ist er defekt, bleiben die Regeln aus. Eine Klasse, die PHP beim Laden abstürzen lässt, etwa eine mit einer nicht implementierten abstrakten Methode, lässt dagegen diesen letzten Schritt fehlschlagen und markiert das Deployment als fehlgeschlagen. Die herald-Regeln sind dann bereits wieder an, und dieser Fehler schaltet sie nicht wieder ab.

## Wenn etwas schiefgeht

- Eine Klasse von herald, die sich überhaupt nicht laden lässt, bringt PHP spätestens im Preflight zum Absturz. Der Preflight endet dann mit einem Fehler, und die herald-Regeln bleiben aus. Bei kaltem Klassen-Cache kann schon ein früherer Schritt, der CiviCRM startet, auf den Absturz stoßen. `cv flush` zeigt die Klasse und die Datei.
- Solange die Datei aus dem ersten Schritt existiert, zeigt der Systemstatus von CiviCRM die Warnung „Herald: Regeln durch einen Deploy abgeschaltet“. Sie listet die IDs der Regeln und den Befehl auf, der sie wieder einschaltet.
- Das nächste erfolgreiche Deployment schaltet die Regeln aus einem fehlgeschlagenen Deployment wieder ein. Das gilt auch für eine Regel, die ein Admin in der Zwischenzeit absichtlich abgeschaltet hat.
- Ein Release, das einen Handler ausmustert, stellt die Regeln auf dessen Nachfolger um, nicht aber die Warteschlange der verzögerten Aktionen von CiviRules. Eine vor dem Deployment eingereihte Aktion, die einen ausgemusterten Handler nennt, schlägt bei ihrer Ausführung fehl, und CiviRules protokolliert das. Führen Sie das Deployment aus, wenn keine verzögerte Aktion von herald wartet.

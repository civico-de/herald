---
title: Deployment
order: 70
---

# Deployment

Die CiviRules-Aktionen und -Bedingungen von herald laufen im Speichervorgang des Datensatzes, auf den ihre Regel reagiert. Lässt sich eine ihrer Klassen nicht laden, schlägt das Speichern fehl, und mit der Meldung geht der Beitrag oder die Anmeldung verloren. Deshalb schalten die Skripte in `bin/` die herald-Regeln ab, solange der Code wechselt, und erst wieder ein, wenn sich jeder Handler laden lässt. Auf einer Site ohne CiviRules gibt es nichts zu tun, und sie laufen erfolgreich durch.

## Schritte

Ein Git-Checkout braucht vor dem Austausch `composer install --no-dev`; das Release-Archiv enthält `vendor/` schon. Brechen Sie beim ersten fehlgeschlagenen Schritt ab:

```bash
HERALD=/var/www/example.org/ext/herald
cv scr $HERALD/bin/rules-disable.php && cv flush
# … Extension-Code austauschen …
cv ext:upgrade-db \
  && cv scr $HERALD/bin/preflight-rules.php \
  && cv scr $HERALD/bin/rules-reenable.php \
  && cv scr $HERALD/bin/note-foreign-rules.php
```

- **rules-disable.php** schaltet jede aktive Regel mit einem Trigger, einer Bedingung oder einer Aktion von herald ab und hält ihre IDs in `herald_deploy_disabled_rules` im privaten Verzeichnis von CiviCRM fest.
- **preflight-rules.php** lädt die Handler von herald und alle Handler dieser Regeln, auch die anderer Extensions, so wie CiviRules es tut. Scheitert es, nennt es Regel, Klasse und Grund, und die Regeln bleiben aus. Es läuft nach den Upgrades, weil ein Upgrade Handler-Klassen umbenennen kann.
- **rules-reenable.php** schaltet die festgehaltenen Regeln wieder ein. Es prüft den Preflight nicht; führen Sie es also nie nach einem fehlgeschlagenen aus.
- **note-foreign-rules.php** meldet defekte Handler anderer Regeln auf stderr, ohne fehlzuschlagen. Nur eine Klasse, die PHP abstürzen lässt, bringt es zum Scheitern; die herald-Regeln bleiben dann an.

## Wenn etwas schiefgeht

- Solange Regeln aus sind, zeigt der Systemstatus „Herald: rules disabled by a deploy“ mit den Regel-IDs und dem Befehl, der sie wieder einschaltet. Auch das nächste erfolgreiche Deployment schaltet sie wieder ein, einschließlich einer Regel, die jemand zwischendurch absichtlich abgeschaltet hat.
- Eine Klasse, die PHP abstürzen lässt, stoppt das Deployment spätestens im Preflight; `cv flush` nennt Klasse und Datei.
- Ein Release, das einen Handler ablöst, stellt die Regeln auf den Nachfolger um, nicht aber die Warteschlange verzögerter CiviRules-Aktionen. Deployen Sie, wenn keine verzögerte herald-Aktion wartet.

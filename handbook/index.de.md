---
title: herald
---

# herald

herald ist eine CiviCRM-Extension, die Benachrichtigungen und Reports verschickt. Eine CiviRules-Regel, ein geplanter Job, eine andere Extension oder ein Skript übergibt herald eine Nachricht oder eine Vorlage. herald rendert sie und stellt sie zu: in einem Chat, auf einem Smartphone, in einem Postfach oder als CiviCRM-Aktivität.

herald richtet sich an alle, die eine CiviCRM-Site betreiben und wissen möchten, was darin passiert: Eine Spende ist eingegangen, eine Überweisung ist angekommen, ein Event ist ausgebucht, ein geplanter Job ist ausgefallen. Die Meldungen richten Sie in der CiviCRM-Oberfläche ein. Extension-Entwickler:innen können herald als Zustellschicht nutzen und dort Health-Checks melden.

## Was herald kann

herald stellt über sechs Kanäle zu: Telegram, einen generischen Webhook für Slack, Discord, Mattermost oder einen eigenen Endpunkt, ntfy, Pushover, E-Mail über den ausgehenden Mailversand von CiviCRM und zugewiesene CiviCRM-Aktivitäten. Neue Kanäle kommen im Code von herald hinzu. Siehe [Kanäle](channels.md).

Die Nachrichten stammen aus CiviCRM-Nachrichtenvorlagen, sodass Admins den Wortlaut unter „Mailings, Message Templates“ (Mailings, Nachrichtenvorlagen) anpassen können. herald bringt Vorlagen für Beiträge, Ticketverkäufe, Events, Mitgliedschaften, Gruppenanmeldungen, Reports und Health-Checks mit. Die Vorlagen sind auf Englisch geschrieben, eine deutsche Übersetzung liegt bei.

Ist CiviRules installiert, ergänzt herald es um vier Bausteine:

- die Aktion „Herald: Send notification (generic)“, die eine Vorlage mit den Daten des Triggers rendert
- die Aktion „Herald: Send digest“, die einen Report verschickt
- den Trigger „Herald: Schedule“, der an gewählten Wochentagen einmal zu einer festen Stunde auslöst
- die Bedingung „Herald: Field transition“, die den Wert eines Feldes vor und nach einer Änderung vergleicht, etwa wenn ein Beitrag von Pending (ausstehend) zu Completed (abgeschlossen) wechselt

Eine Benachrichtigung kann an ein einzelnes Ziel gehen, an alle Kontakte, die diese Art von Benachrichtigung abonniert haben, an die Mitglieder einer Gruppe oder an eine Liste von E-Mail-Adressen. Jeder Kontakt kann seinen eigenen Kanal wählen. Meldungen, die gehäuft auftreten, lassen sich sammeln und als eine Nachricht pro Zeitfenster verschicken. Siehe [Zustellung](delivery.md).

Skripte und andere Extensions können in ein benanntes Topic wie ops veröffentlichen. Eine Einstellung legt fest, welchen Kanal und welches Ziel jedes Topic erreicht. Wer veröffentlicht, muss also nie eine Chat-ID oder eine URL kennen.

Ein Report fasst die Beiträge eines Zeitraums zusammen, listet die Zeilen einer gespeicherten SearchKit-Suche auf oder enthält Zahlen, die eine andere Extension liefert. Sie richten sie unter Administrieren » Herald » Reports ein, und sie gehen meist täglich oder wöchentlich hinaus, über eine Regel mit dem Trigger „Herald: Schedule“. Siehe [Reports](digests.md).

Der Check-Monitor führt Health-Checks aus, die Extensions beisteuern. Er meldet, wenn ein Check fehlschlägt und wenn er sich wieder erholt, und erinnert an Checks, die dauerhaft fehlschlagen. Auf einer gesunden Site bleibt es still. Siehe [Check-Monitor](monitor.md).

## Voraussetzungen

herald benötigt SearchKit und Form Core, die Teil von CiviCRM sind. CiviRules ist optional. herald registriert seine Regel-Bausteine, egal ob Sie CiviRules vor oder nach herald installieren. Ohne CiviRules funktionieren die API, die geplanten Jobs, die Topics und der Check-Monitor weiterhin. Die unterstützten Versionen von CiviCRM und PHP stehen in der `info.xml` der Extension.

## Installation

Laden Sie das Release-Archiv von der [Release-Seite](https://github.com/civico-de/herald/releases) herunter, entpacken Sie es in das Extension-Verzeichnis Ihrer CiviCRM-Installation und aktivieren Sie die Extension:

```bash
cv ext:enable herald
```

Ein Git-Checkout enthält kein Verzeichnis `vendor/`. Führen Sie darin `composer install --no-dev` aus, bevor Sie die Extension aktivieren. Ohne `vendor/` schlägt jede Seite der Site fehl, sobald herald aktiviert ist.

Beim Deinstallieren entfernt herald seine CiviRules-Bausteine, und CiviRules entfernt sie aus jeder Regel, die sie nutzt. Eine Regel ohne ihre herald-Bedingung würde danach öfter auslösen als vorher. Deshalb schaltet herald zuerst jede aktive Regel ab, die einen seiner Trigger, eine seiner Bedingungen oder Aktionen nutzt. Das CiviCRM-Log nennt die IDs dieser Regeln. Beim Deinstallieren löscht herald außerdem seine Tabellen und damit die Reports und jede Meldung, die noch im Puffer wartet, auch die geparkten.

## Erste Schritte

Legen Sie mit @BotFather einen Telegram-Bot an, fügen Sie ihn einem Chat hinzu und notieren Sie die Chat-ID. Speichern Sie dann beides unter Administrieren » Herald » Einstellungen oder mit cv:

```bash
cv setting:set herald_telegram_bot_token=<bot-token>
cv setting:set herald_telegram_default_chat_id=<chat-id>
```

Senden Sie eine Testnachricht unter Administrieren » Herald » Testnachricht: Wählen Sie „Einen Kanal“, dann Telegram, und senden Sie. Oder mit cv:

```bash
cv api4 Herald.testTelegram message='<b>herald works</b>'
```

Richten Sie nun eine Regel ein, die jeden abgeschlossenen Beitrag meldet:

1. Öffnen Sie „Administer, CiviRules, Manage Rules“ (Administrieren, CiviRules, Regeln verwalten), legen Sie eine Regel an und wählen Sie den Trigger „Contribution is added“ (Beitrag wird angelegt).
2. Fügen Sie die Bedingung „Contribution Status is (not) one of“ (Beitragsstatus ist (nicht) einer von) mit Completed hinzu.
3. Fügen Sie die Aktion „Herald: Send notification (generic)“ hinzu. Wählen Sie die Vorlage „Herald: Neuer abgeschlossener Beitrag“ und den Kanal Telegram. Lassen Sie die Chat-ID leer, dann gilt der Standard-Chat.

Die übrigen Kanäle richten Sie genauso ein. Ihre Einstellungen finden Sie unter [Kanäle](channels.md).

## Wie es weitergeht

- [Rezepte](recipes.md) zeigt vollständige Einrichtungen für Spenden, Ticketverkäufe, Events, Anmeldungen und geplante Reports.
- [Kanäle](channels.md) beschreibt die Einstellungen, Optionen und Grenzen jedes Kanals.
- [Zustellung](delivery.md) erklärt Empfänger:innen, Abonnements, Topics, das Sammeln von Meldungen und was passiert, wenn eine Zustellung fehlschlägt.
- [Reports](digests.md) beschreibt die Report-Blöcke, ihre Filter und ihre Vorlagenvariablen.
- [Check-Monitor](monitor.md) zeigt, wie Sie einen Health-Check beisteuern und die Meldungen feinjustieren.
- [Referenz](reference.md) listet die CiviRules-Aktionen, -Trigger und -Bedingungen, die API, die geplanten Jobs, die Vorlagen, die Einstellungen und die Hooks auf.
- [Deployment](deploy.md) erklärt, wie Sie herald aktualisieren, ohne die Schreibvorgänge zu stören, in denen seine Regeln laufen.

# Plan: Abos auf Topics

Entwurf vom 21.10.2026.

## Worum es geht

herald beschreibt heute mit zwei Listen, worum es in einer Nachricht geht:

- **Topics** (Setting `herald_topics`, pro Domain): Publisher, Monitor, Regelaktionen und Jobs nennen ein Topic, seine Route entscheidet über Kanal und Empfänger.
- **Abo-Typen** (Optionsgruppe `herald_subscription_type`, global): Kontakte haken im Profil Abo-Typen an (Custom-Feld `herald_preferences.subscriptions`, Mehrfachauswahl). Eine Zustellung im Modus `subscribers` nennt mit `subscription_type`, wessen Abonnenten sie erreicht.

Eine Route im Modus `subscribers` muss deshalb beides nennen, und praktisch steht dort pro Topic ein eigener Abo-Typ, eine 1:1-Abbildung, die von Hand gepflegt wird. Dazu liefert herald fünf Abo-Typen mit, die nur zu Spenden passen (`completed_alert`, `pending_alert`, `status_changed_alert`, `daily_digest`, `weekly_digest`); herald ist aber Transport, keine Fachlogik.

## Das Ziel in einem Satz

Ein Kontakt abonniert Topics; eine Zustellung an Abonnenten erreicht die Abonnenten eines Topics, und die Liste der Abo-Typen fällt weg.

Das Abo selbst bleibt: Es ist die Verbindung Kontakt → Topic, samt eingeschränktem Abo (`source_entity_type`/`source_entity_id`, etwa „nur Veranstaltung 42“).

## Datenmodell

### Topic

Ein Eintrag in `herald_topics` bekommt zwei Keys neben den Routen-Keys:

| Key | Bedeutung |
|---|---|
| `label` | Name im Profil und in den Admin-Screens; leer → der Topic-Name |
| `subscribable` | Kontakte dürfen das Topic abonnieren (Vorgabe: nein) |

Beide sind keine Routen-Felder: Der Router reicht sie nicht in die Zustellung, `default` vererbt sie nicht, und `RouteValidator` kennt sie als Topic-Keys. Ein eigenes Setting für Topic-Metadaten wäre ein zweiter Ort für denselben Namen; das ist genau das Problem, das hier wegfällt.

### Wessen Abonnenten

- In der Route eines Topics `T` heißt `delivery_mode: subscribers`: die Abonnenten von `T`. Kein weiterer Key.
- Eine Zustellung ohne Topic (eigene Zustellung einer Regelaktion oder eines Jobs) nennt das Topic mit `subscribers_of`. Der Key ersetzt `subscription_type`; er ist kein `topic`, weil `topic` heißt „die Route dieses Topics nehmen“.
- Eine Route darf `subscribers_of` ebenfalls setzen, wenn sie die Abonnenten eines anderen Topics erreichen soll. Das braucht nur das Upgrade für Routen, deren Abo-Typ anders hieß als ihr Topic; das Formular bietet es nicht an.
- `subscribers_of` muss ein Topic mit `subscribable` nennen; sonst lehnen Formular und `RouteValidator` ab, und beim Senden wirft die Zustellung `NoRecipientsException`.

### Optionen im Profil

Das Custom-Feld `subscriptions` bleibt, mit Topic-Namen als Werten. Seine Optionsgruppe heißt `herald_subscribable_topic` und wird wie `herald_preferred_channel` (`PreferredChannelOptions::sync()`) aus den abonnierbaren Topics aller Domains befüllt, die aktuelle Domain zuerst. `saveTopic` und `deleteTopic` rufen den Sync auf. Die Optionsgruppe ist damit nicht mehr von Hand erweiterbar; wer ein Abo anbieten will, legt ein Topic an.

## Wer was nennt

| Wer | heute | danach |
|---|---|---|
| Kontakt | Abo-Typen | Topics mit `subscribable` |
| Route von `T`, Modus `subscribers` | `subscription_type` | nichts (Abonnenten von `T`) |
| Regelaktion/Job über ein Topic | Topic | Topic |
| Regelaktion/Job mit eigener Zustellung, Modus `subscribers` | `subscription_type` | `subscribers_of` |
| Puffer | `subscription_type` in `delivery` | `subscribers_of` |

`SubscriberLookup::findBySubscriptionType()` wird `findByTopic()`; die Abfrage bleibt `herald_preferences.subscriptions CONTAINS <topic>`.

## Oberfläche

- Topic-Liste: Spalten Label, abonnierbar, Zahl der Abonnenten (Aggregat über das Custom-Feld, keine Kontaktdaten).
- Topic-Formular: Label, Checkbox „Kontakte dürfen dieses Topic abonnieren“. Im Modus „Abonnenten“ fällt das Feld „Abo-Typ“ weg; der Hinweis sagt „die Abonnenten dieses Topics“.
- Aktionsformular, eigene Zustellung, Modus „Abonnenten“: Auswahl der abonnierbaren Topics statt der Abo-Typen.
- Topic löschen: blockiert, solange eine Route, Aktion, ein Job oder eine Pufferzeile es nennt (`topic` oder `subscribers_of`). Hat es Abonnenten, nennt der Bestätigungsdialog ihre Zahl, wie beim Löschen eines gewählten Kanals; ihre Werte bleiben im Custom-Feld stehen und zählen nicht mehr.
- Handbuch: `delivery.md` (Abschnitte Subscribers und Subscription types) und `deploy.md` (Upgrade).

## Upgrade

Wie bei den Kanälen bekommen die neuen Datensätze die alten Namen, damit gespeicherte Werte gültig bleiben:

1. **Topics anlegen.** Für jeden Wert `X` der Optionsgruppe `herald_subscription_type`, aktiv oder nicht, bekommt jede Domain ein Topic `X` mit `label` = Label der Option und `subscribable: true`, sofern es dort noch kein Topic `X` gibt. Gibt es eines, bekommt es `label` (falls leer) und `subscribable: true`. Ein neues Topic hat keine eigenen Routen-Keys und erbt `default`; das ändert nichts, denn bisher hat niemand an `X` publiziert.
2. **Abos bleiben.** Die Werte in `herald_preferences.subscriptions` sind bereits die Topic-Namen; keine Kontaktzeile wird angefasst.
3. **Routen.** Eine Route von `T` mit `subscription_type: X`: bei `X = T` entfällt der Key, sonst wird er zu `subscribers_of: X`. In `default` wird er ebenfalls zu `subscribers_of`, damit Topics ohne eigenen Abo-Typ weiter dieselben Kontakte erreichen.
4. **Aktionen, Jobs, Puffer.** `subscription_type` wird zu `subscribers_of`, Wert unverändert. Derselbe Durchlauf wie `StoredDeliveries` (Aktionen aus den Tabellen, Jobs nur in ihrer Domain, Puffer per SQL).
5. **Optionsgruppe.** `herald_subscribable_topic` wird angelegt und per Sync befüllt; das Custom-Feld zeigt auf sie (wie `PreferredChannelField::repoint`). Danach wird `herald_subscription_type` gelöscht.
6. **Mitgelieferte Werte.** `managed/SubscriberPreferences.mgd.php` liefert keine Abo-Typen mehr. Eine frische Installation hat keine Topics und damit keine Abos, bis ein Admin ein Topic abonnierbar macht.

Die Reihenfolge 1 → 5 sorgt dafür, dass zu keinem Zeitpunkt ein Abo-Wert ohne Option dasteht.

## Vor dem Bau prüfen

1. **Reconcile gegen Upgrade.** Fallen die Optionswerte aus der mgd-Datei, löscht der Reconcile sie womöglich (Cleanup-Policy der Managed Entities), bevor Schritt 1 ihre Labels liest. Im civikitchen-Container messen: Läuft `upgrade-db` vor dem Reconcile, und was macht der Reconcile mit entfernten Managed-Optionen? Je nach Ergebnis liest Schritt 1 die Labels vorher oder hängt die Werte vorab ab.
2. **Feld umhängen.** Behält ein Mehrfachauswahl-Custom-Feld seine gespeicherten Werte, wenn seine `option_group_id` auf eine Gruppe mit denselben Werten wechselt? Im Container messen, nicht aus dem Code lesen.
3. **Installationen.** Pro Installation (nur Zählungen, keine Kontaktdaten): Werte der Optionsgruppe mit Zahl der Abonnenten, Routen mit `subscription_type ≠` Topic-Name, Aktionen und Jobs mit eigener Zustellung im Modus `subscribers`. Die Abfragen schreibe ich; ausführen ist deine Sache.

## Reihenfolge

1. Messungen 1 und 2.
2. Topic-Keys `label`/`subscribable` mit `RouteValidator`, `saveTopic`, Options-Sync und Tests.
3. `subscribers_of` in Dispatcher, `Delivery`, `SubscriberLookup::findByTopic()`, Aktionsformular, `RouteFields`; `subscription_type` raus.
4. Upgrade-Schritt mit Tests (inklusive zweiter Domain und zweitem Lauf ohne Änderung).
5. Admin-Screens, Übersetzung, Handbuch.
6. `ck ci`, Review-Runden, Browser-Test der Screens mit axe hell/dunkel.

## Offen

- Ob ein Kontakt nur einen Kanal für alle Abos hat (heute: ja, `preferred_channel`) oder pro Topic einen eigenen. Dieser Plan lässt es bei einem; ein Kanal pro Abo wäre ein eigener Schritt mit eigener Tabelle statt Mehrfachauswahl.

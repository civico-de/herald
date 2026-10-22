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
- Ein abonnierbares Topic erbt `subscribers_of` nicht von `default`: Seine Route erreicht die eigenen Abonnenten, solange sein eigener Eintrag keine anderen nennt. Sonst ginge ein neu angebotenes Topic still an die Abonnenten, die `default` nennt.
- Zurückziehen (Haken weg) lehnt ab, solange etwas das Topic in `subscribers_of` nennt oder seine Route danach die Abonnenten von `default` erreichen würde.
- Speichern von `default` prüft jedes Topic, das es erbt, mit denselben Regeln wie das Speichern des Topics selbst.
- `subscribers_of` muss ein Topic mit `subscribable` nennen; sonst lehnen Formular und `RouteValidator` ab, und beim Senden wirft die Zustellung `NoRecipientsException`.

### Optionen im Profil

Das Custom-Feld `subscriptions` bleibt, mit Topic-Namen als Werten. Seine Optionsgruppe heißt `herald_subscribable_topic` und wird wie `herald_preferred_channel` (`PreferredChannelOptions::sync()`) aus den abonnierbaren Topics aller Domains befüllt, die aktuelle Domain zuerst. Den Sync löst `post_change` an der Einstellung `herald_topics` aus, also auch `Setting.set`, nicht nur `saveTopic` und `deleteTopic`. Die Optionsgruppe ist damit nicht mehr von Hand erweiterbar; wer ein Abo anbieten will, legt ein Topic an.

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

1. **Topics anlegen.** Die Abo-Typen sind die Werte, die noch in der Optionsgruppe `herald_subscription_type` stehen, die Werte in `herald_preferences.subscriptions` (nur `DISTINCT` der Werte, ohne Kontaktbezug) und die Werte, die Routen, Aktionen, Jobs und Puffer als `subscription_type` nennen. Für jeden Wert `X` bekommt jede Domain ein Topic `X` mit `subscribable: true` und als `label` das Label der Option, sonst das mitgelieferte Label (Konstante im Upgrader), sonst `X`. Gibt es dort schon ein Topic `X`, bekommt es `subscribable: true` und ein leeres `label` gefüllt. Ein neues Topic hat keine eigenen Routen-Keys und erbt `default`. Erreichte ein Topic `X` bisher im Abonnenten-Modus die Abonnenten, die `default` nennt, schreibt das Upgrade diese in seinen eigenen Eintrag, weil ein abonnierbares Topic sonst seine eigenen erreicht. Mitgelieferte Typen, die niemand abonniert und nichts nennt, werden kein Topic.
2. **Abos bleiben.** Die Werte in `herald_preferences.subscriptions` sind bereits die Topic-Namen; keine Kontaktzeile wird angefasst.
3. **Routen.** Eine Route von `T` mit `subscription_type: X`: bei `X = T` entfällt der Key, sonst wird er zu `subscribers_of: X`. In `default` wird er unabhängig vom Modus von `default` zu `subscribers_of`, damit Routen im Abonnenten-Modus ohne eigenen Abo-Typ weiter dieselben Kontakte erreichen; `default` selbst wird nie angeboten.
4. **Aktionen, Jobs, Puffer.** `subscription_type` wird zu `subscribers_of`, Wert unverändert, aber nur im Modus `subscribers` (bei Routen der mit `default` zusammengeführte Modus); sonst wurde der Key nie gelesen und entfällt. Ein schon geschriebenes `subscribers_of` zählt beim Wiederanlauf nach einem Abbruch als genannter Typ, und die alte Gruppe wird erst nach dem Anlegen der Topics gelöscht. Derselbe Durchlauf wie `StoredDeliveries` (Aktionen aus den Tabellen, Jobs nur in ihrer Domain, Puffer per SQL).
5. **Optionsgruppe.** `herald_subscribable_topic` wird vor Schritt 1 angelegt, weil das Schreiben der Topics sie befüllt. Den Wechsel des Feldes macht der Reconcile im Schritt (`CRM_Herald_Upgrader_OptionField::move`), so dass das Feld unverändert bleibt und spätere mgd-Änderungen ankommen; nur ein vom Admin geändertes Feld wird per API umgehängt. Die alte Gruppe wird gelöscht, sobald ihre Labels gelesen sind. Bis dahin deklariert die mgd-Datei das Feld auf der alten Gruppe (`SubscriptionTypes::fieldGroup()`), damit ein Reconcile vor dem Upgrade sie nicht über `CustomField::fixOptionGroups()` samt Admin-Werten löscht.
6. **Mitgelieferte Werte.** `managed/SubscriberPreferences.mgd.php` liefert keine Abo-Typen mehr, sobald das Upgrade sie gelesen hat; bis dahin deklariert es sie wie bisher (`SubscriptionTypes::declarations()`), damit ein Kontakt, der sein Profil zwischen Deploy und Upgrade speichert, kein Abo verliert. Eine frische Installation hat keine Topics und damit keine Abos, bis ein Admin ein Topic abonnierbar macht.

Die Reihenfolge 1 → 5 sorgt dafür, dass zu keinem Zeitpunkt ein Abo-Wert ohne Option dasteht.

## Gemessen

Im Dev-Stack (civikitchen) am 21.10.2026:

1. **Reconcile vor dem Upgrade.** `cv ext:upgrade-db` gleicht die Managed Entities ab, bevor herald's Upgrade-Schritt läuft: Ein aus der mgd-Datei entfernter Wert war im Schritt schon gelöscht. Schon `cv flush` löscht ihn. Ein Abo zählt nicht als Referenz (`CoreUtil::getRefCount` liefert `[]`), also greift `cleanup: unused` nicht. Die fünf mitgelieferten Werte sind damit vor Schritt 1 weg; von Admins angelegte Werte sind nicht verwaltet und bleiben. Deshalb liest Schritt 1 auch die Kontaktwerte und die Referenzen und kennt die mitgelieferten Labels selbst.
2. **Feld umhängen.** Nach `CustomField.update` mit einer anderen `option_group_id` liest APIv4 dieselben Werte (Spalte `text`, unverändert). Core löscht die alte Gruppe dabei selbst (`CRM_Core_BAO_CustomField::checkOptionGroup`), wenn kein anderes Feld sie nutzt.
3. **Umhängen per API markiert das Feld als geändert.** `CustomField.update` setzt `entity_modified_date`; der Reconcile (Policy `unmodified`) überspringt das Feld danach für immer, neue Hilfetexte aus der mgd-Datei kämen nie an. Das traf auch das Umhängen von `preferred_channel` in 1014. Deshalb erst Reconcile, API nur als Rückfall. Gemessener Durchlauf mit `cv updb` auf einem 1014-Stand: Admin-Label „Members news“ bleibt, mitgelieferte Labels kommen aus der Konstante, das Feld zeigt auf die neue Gruppe mit neuem Hilfetext und bleibt unverändert, die alte Gruppe ist weg, Schema 1015.
4. **Reconcile zwischen Code-Tausch und Upgrade.** Mit fester neuer Gruppe in der mgd-Datei scheitert das erste `cv flush` noch (die neue Gruppe entsteht in derselben Runde erst nach dem Feld), das zweite hängt das Feld um, und Core löscht die alte Gruppe samt „Members news“. Mit `fieldGroup()` überstehen zwei Flushes die alte Gruppe, `cv updb` bringt danach Label, Feld und Schema 1015 wie in 3.

## Vor dem Bau prüfen

- **Installationen.** Pro Installation (nur Zählungen, keine Kontaktdaten): Werte der Optionsgruppe mit Zahl der Abonnenten, Routen mit `subscription_type ≠` Topic-Name, Aktionen und Jobs mit eigener Zustellung im Modus `subscribers`. Die Abfragen schreibe ich; ausführen ist deine Sache. Der Bau hängt nicht davon ab; sie zeigen, was das Upgrade auf den Installationen anlegt.

## Reihenfolge

1. Topic-Keys `label`/`subscribable` mit `RouteValidator`, `saveTopic`, Options-Sync und Tests.
2. `subscribers_of` in Dispatcher, `Delivery`, `SubscriberLookup::findByTopic()`, Aktionsformular, `RouteFields`; `subscription_type` raus.
3. Upgrade-Schritt mit Tests (inklusive zweiter Domain und zweitem Lauf ohne Änderung).
4. Admin-Screens, Übersetzung, Handbuch.
5. `ck ci`, Review-Runden, Browser-Test der Screens mit axe hell/dunkel.

## Offen

- Ob ein Kontakt nur einen Kanal für alle Abos hat (heute: ja, `preferred_channel`) oder pro Topic einen eigenen. Dieser Plan lässt es bei einem; ein Kanal pro Abo wäre ein eigener Schritt mit eigener Tabelle statt Mehrfachauswahl.

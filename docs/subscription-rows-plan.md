# Plan: Mehrere Abos pro Kontakt

Entwurf vom 23.10.2026.

## Worum es geht

Ein Kontakt hat heute genau einen Datensatz in der Feldgruppe `herald_preferences`:

| Feld | Inhalt |
|---|---|
| `preferred_channel` | ein Kanal, über den alles kommt |
| `telegram_chat_id`, `pushover_user_key`, `ntfy_topic`, `webhook_url` | ein Empfänger pro Kanaltyp, fest als Spalte |
| `subscriptions` | die abonnierten Topics (Mehrfachauswahl) |
| `source_entity_type`, `source_entity_id` | ein Bezug für alle Abos zusammen |

Daraus folgen drei Grenzen:

1. **Ein Kanal für alles.** „Dringende Meldungen per Pushover, den Tagesbericht per E-Mail“ geht nicht.
2. **Ein Bezug für alles.** Wer zwei Veranstaltungen verfolgen will oder eine Veranstaltung plus ein globales Topic, kann das nicht ausdrücken: Ein gesetzter Bezug schränkt jedes Abo des Kontakts ein.
3. **Ein Empfänger pro Typ, fest im Schema.** Zwei Telegram-Chats gehen nicht, und jeder neue Kanaltyp braucht eine neue Spalte, einen Eintrag in `ContactRows::RECIPIENT_FIELDS` und ein Profilfeld. Das widerspricht der Regel, dass `getConfigSchema()` die einzige Liste der Keys eines Kanals ist.

## Das Ziel in einem Satz

Ein Abo ist eine Zeile: ein Kanal, ein Empfänger, Topics und ein optionaler Bezug; ein Kontakt hat beliebig viele davon.

Leitbeispiele:

- Eine Kollegin bekommt `payments_failed` per Pushover und `daily_report` per E-Mail.
- Ein Kollege verfolgt die Anmeldungen zu den Veranstaltungen 42 und 57 per Telegram, jede als eigenes Abo mit Bezug.

## Datenmodell

Eine mehrzeilige Feldgruppe `herald_subscription` (`is_multiple`, `style` `Tab`) auf Contact:

| Feld | Typ | Inhalt |
|---|---|---|
| `channel` | Auswahl, Optionsgruppe `herald_preferred_channel` | ein abonnierbarer Kanal, Pflicht |
| `recipient` | Text | der Empfänger im Format des Kanaltyps; bei E-Mail leer = die Adresse des Kontakts |
| `topics` | Mehrfachauswahl, Optionsgruppe `herald_subscribable_topic` | Pflicht, mindestens eines |
| `source_entity_type`, `source_entity_id` | Text, Zahl | optionaler Bezug dieser Zeile |
| `for_groups` | Ja/Nein | diese Zeile gilt auch für Gruppennachrichten, siehe unten |

Warum eine mehrzeilige Feldgruppe statt einer eigenen Tabelle (wie im Plan „Abos auf Topics“ noch vermutet): CiviCRM bringt dafür den Tab am Kontakt mit Tabelle, Anlegen, Bearbeiten und Löschen mit, ein APIv4-Entity `Custom_herald_subscription`, SearchKit für Zählungen und die Listenansicht mehrzeiliger Gruppen im Profil für die Selbstverwaltung. Eine eigene Tabelle bräuchte Schema, DAO, Upgrade, Kontakt-Tab, Formular und ACL selbst. Die Optionsgruppen bleiben dieselben, also auch ihr Sync aus Kanälen und Topics.

### Empfänger ohne feste Spalte

Jeder Kanaltyp nennt in `getSemanticFields()` seinen Abonnenten-Empfänger: `recipient => chat_id` (Telegram), `pushover_user_key`, `ntfy_topic`, `webhook_url`, `email_to`. Ein Typ ohne diesen Eintrag ist nicht abonnierbar (heute `ContactRows::reachable()`). Der Lookup legt `recipient` unter diesen Key; `ContactRows::RECIPIENT_FIELDS` fällt weg.

Prüfen beim Speichern: Der Wert muss die Prüfungen bestehen, die beim Senden ohnehin gelten (etwa die Webhook-URL gegen die erlaubten Hosts), damit ein Fehler im Formular auffällt statt erst bei der Zustellung. Das hängt am `pre`-Hook der Feldgruppe, weil der Tab ein Core-Formular ist; ein ungültiger Wert ist ein Fehler im Formular, keine stille Korrektur.

### E-Mail

Leerer `recipient` bei einem E-Mail-Kanal heißt wie heute: die Adresse des Kontakts, nach Adresstyp und Sperren aus `EmailRecipient::of()`. Eine eingetragene Adresse gilt direkt; die Sperren des Kontakts (`do_not_email`, `is_opt_out`, `on_hold` der Hauptadresse) gelten trotzdem, weil sie dem Kontakt gehören, nicht der Adresse.

## Zustellung

### An Abonnenten eines Topics

`SubscriberLookup::findByTopic(T)` liest die Zeilen, deren `topics` `T` enthalten und deren Bezug passt (`SubscriptionScope::rowMatches()` pro Zeile statt pro Kontakt). Jede passende Zeile ist ein Empfänger. Zwei Zeilen eines Kontakts mit demselben Kanal und demselben Empfänger werden zu einer zusammengelegt; verschiedene Kanäle sind gewollt und bekommen die Nachricht jeweils.

Die Zählung der Übersprungenen (`suspended`, `scope_mismatch`, `no_channel`, `no_recipient`) zählt Zeilen statt Kontakte. Ein fehlschlagender Empfänger hält die anderen weiterhin nicht auf.

### An eine Gruppe

Der Gruppenmodus kennt keine Topics. Er erreicht jedes Mitglied über seine Zeilen mit `for_groups`, jede einmal; ohne solche Zeile bekommt das Mitglied wie heute eine E-Mail über `herald_contact_email_channel`. Ein Haken statt „die erste Zeile“, weil eine Reihenfolge der Zeilen nichts bedeutet und eine geratene Wahl Meldungen an einen Kanal schickt, den der Kontakt dafür nie vorgesehen hat.

### Zählungen in den Admin-Screens

Kanalliste (Abonnenten pro Kanal, `ang/heraldAdmin/channels.js`) und Topic-Liste (`SubscribableTopics`) zählen Kontakte mit mindestens einer Zeile über `Custom_herald_subscription` statt über `herald_preferences`. Das Löschen eines Kanals oder Topics nennt im Bestätigungsdialog weiterhin die Zahl.

## Selbstverwaltung

Das Profil `herald_my_preferences` bleibt, mit den Feldern der neuen Gruppe statt der alten. Core-Profile können mehrzeilige Feldgruppen: Im Bearbeiten-Modus listen sie die Zeilen des Kontakts mit Ansehen, Bearbeiten und Löschen und bieten „Add New Record“ im Dialog an (`CRM_Profile_Page_MultipleRecordFieldsListing`, `multiRecord=add|update|delete` in `CRM_Profile_Form_Edit`). Der Link `/civicrm/profile/edit?gid=<id>&reset=1` bleibt damit gültig. Die Prüfung des Empfängers läuft über denselben Hook wie im Tab.

Gemessen am 23.10.2026 im Dev-Stack mit einer mehrzeiligen Wegwerf-Gruppe (Kanal, Empfänger, Topics) und einem Nutzer mit „profile edit“, „edit my contact“, aber ohne „edit all contacts“:

- `profile/edit?gid=<id>&reset=1` ohne `id` listet die eigenen Zeilen mit Ansehen, Bearbeiten, Löschen und „Add New Record“. Anlegen, Bearbeiten und Löschen eigener Zeilen funktionieren.
- Die Liste zeigt nur Profilfelder mit `is_multi_summary`; ohne das Flag meldet sie „No records“, obwohl Zeilen bestehen. `PreferencesProfile::ensure()` setzt es.
- Ohne „access all custom data“ zeigt das Profil keine benutzerdefinierten Felder, die Seite bleibt leer. Das gilt für das heutige Profil genauso; das Handbuch nennt das Recht bisher nicht.
- Eine fremde `id` leitet auf die Startseite um. Eine fremde `recordId` mit eigener `id` findet die Zeile nicht („Record doesnot exist“, beim Speichern entstünde eine neue eigene Zeile), Löschen bietet keinen Knopf an. Fremde Zeilen sind also nicht erreichbar; die Seite wirft dabei PHP-Notices im Template, ein Core-Bug für `upstream-bugs.md`.

Den Link im Handbuch (`/civicrm/profile/edit?gid=<id>`) ersetzt der feste Pfad; die ID nachzuschlagen entfällt.

## Upgrade

Für jeden Kontakt mit einem Datensatz in `herald_preferences`, in SQL über die Custom-Tabellen, ohne Kontaktdaten zu lesen oder zu loggen:

1. **Feldgruppe anlegen.** Die mgd-Datei deklariert `herald_subscription` neben `herald_preferences`; der Reconcile vor dem Upgrade-Schritt legt sie an.
2. **Zeilen schreiben.** Hat der Kontakt `preferred_channel` und mindestens ein Topic, wird eine Zeile: Kanal, der Empfänger aus der Spalte des Kanaltyps, die Topics, der Bezug, `for_groups` = ja. Hat er einen Kanal ohne Topics, wird eine Zeile ohne Topics mit `for_groups` = ja, damit der Gruppenmodus ihn weiter erreicht; nur diese Zeilen dürfen ohne Topic bestehen.
3. **Übrige Empfänger.** Werte in den Spalten anderer Typen als dem gewählten wurden bisher nie benutzt. Sie werden nicht übernommen; das Upgrade schreibt ihre Zahl pro Spalte ins Log (eine Zahl, keine Werte).
4. **Profil umstellen, alte Feldgruppe entfernen.** `PreferencesProfile::ensure()` ersetzt die Felder des Profils durch die der neuen Gruppe, danach fällt `herald_preferences` samt Tabelle (aus der mgd-Datei genommen, `cleanup` greift erst, wenn der Schritt gelaufen ist, wie bei `SubscriptionTypes::fieldGroup()`).
5. **Zweiter Lauf.** Ein wiederholter Schritt legt keine Zeile doppelt an: Er überspringt Kontakte, die schon eine Zeile in `herald_subscription` haben.

Bis zum Upgrade-Schritt lesen Lookup und Zählungen weiter `herald_preferences`; der Code liest erst nach Schritt 2 die neue Gruppe. Dazu ein Schalter wie `fieldGroup()`, abgeleitet aus der Schema-Version, nicht aus einem Setting.

## Wer was ändert

| Stelle | Änderung |
|---|---|
| `managed/SubscriberPreferences.mgd.php` | neue Gruppe `herald_subscription`, alte erst nach dem Upgrade weg |
| `Channel*::getSemanticFields()` | `recipient` pro abonnierbarem Typ |
| `ContactRows`, `SubscriberLookup`, `GroupRecipients`, `SubscriptionScope` | Zeilen statt Kontakt, `RECIPIENT_FIELDS` weg |
| `SubscribableTopics`, `channels.js` | Zählungen über die neue Gruppe |
| neuer `pre`-Hook | Empfänger und Pflicht-Topics prüfen |
| `CRM_Herald_Upgrader_PreferencesProfile` | Felder der neuen Gruppe |
| Upgrade-Schritt | Migration wie oben, mit Tests (zweite Domain, zweiter Lauf, Kontakt ohne Topics, Kontakt mit Bezug) |
| Handbuch | `delivery.md` (Subscribers, Scoped subscriptions, Group mode, nötiges Recht „access all custom data“ für das Profil), `deploy.md` (Upgrade), Screenshots |
| Tour (`extension-tours`) | Tab mit zwei Zeilen, Profil mit Zeilenliste |

## Vor dem Bau messen

Im Dev-Stack (civikitchen), Ergebnis mit Zahl in diesen Plan:

1. **Tab und Formular.** Eine mehrzeilige Feldgruppe mit Mehrfachauswahl-Feld: zeigt der Tab die Topics lesbar, und lässt sich eine Zeile anlegen und bearbeiten?
2. **`pre`-Hook.** Kommt der Hook bei Tab-Formular, Profil und APIv4 mit denselben Werten an, und erscheint eine geworfene Ausnahme im Tab als Formularfehler?
3. **APIv4 `CONTAINS`** auf dem Mehrfachauswahl-Feld einer mehrzeiligen Gruppe, über `Contact.get` mit Join und direkt über `Custom_herald_subscription.get`.
4. **Profil.** Erledigt, Ergebnis unter „Selbstverwaltung“.

Auf den Installationen (nur Zählungen; die Abfragen schreibe ich, ausführen ist deine Sache): Kontakte mit Präferenzen, davon mit Bezug, mit Kanal ohne Topics, und Werte in Empfängerspalten, die nicht zum gewählten Kanal gehören.

## Reihenfolge

1. Messungen oben, Plan nachziehen.
2. `recipient` in `getSemanticFields()`, Lookup und Gruppenmodus auf Zeilen, mit Tests gegen beide Feldgruppen über den Schalter.
3. Feldgruppe, `pre`-Hook, Zählungen.
4. Profil auf die neue Gruppe umstellen.
5. Upgrade-Schritt mit Tests.
6. Handbuch EN/DE, Übersetzung, Tour, `ck ci`, Review-Runden, Browser-Test mit axe hell/dunkel.

## Offen

- Ob eine eingetragene E-Mail-Adresse erlaubt sein soll oder E-Mail immer an die Adresse des Kontakts geht. Der Plan erlaubt sie, weil Funktionspostfächer (`buchhaltung@…`) ein naheliegender Fall sind.
- Ob der Bezug mehrere IDs pro Zeile tragen soll. Der Plan sagt nein: zwei Veranstaltungen sind zwei Zeilen.

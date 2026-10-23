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

Dazu eine einzeilige Feldgruppe `herald_group_delivery` (`style` `Inline`) auf Contact mit `channel` (dieselbe Optionsgruppe, leer erlaubt) und `recipient`: wie Nachrichten an die Gruppen des Kontakts ankommen, siehe unten.

Warum eine mehrzeilige Feldgruppe statt einer eigenen Tabelle (wie im Plan „Abos auf Topics“ noch vermutet): CiviCRM bringt dafür den Tab am Kontakt mit Tabelle, Anlegen, Bearbeiten und Löschen mit, ein APIv4-Entity `Custom_herald_subscription`, SearchKit für Zählungen und die Listenansicht mehrzeiliger Gruppen im Profil für die Selbstverwaltung. Eine eigene Tabelle bräuchte Schema, DAO, Upgrade, Kontakt-Tab, Formular und ACL selbst. Die Optionsgruppen bleiben dieselben, also auch ihr Sync aus Kanälen und Topics.

### Empfänger ohne feste Spalte

Jeder Kanaltyp nennt in `getSemanticFields()` seinen Abonnenten-Empfänger: `recipient => chat_id` (Telegram), `pushover_user_key`, `ntfy_topic`, `webhook_url`, `email_to`. Ein Typ ohne diesen Eintrag ist nicht abonnierbar (`SubscriptionConfig::reachable()`). Der Lookup legt `recipient` unter diesen Key; `ContactRows::RECIPIENT_FIELDS` fällt weg.

Prüfen beim Speichern: Der Wert muss die Prüfungen bestehen, die beim Senden ohnehin gelten (etwa die Webhook-URL gegen die erlaubten Hosts), damit ein Fehler im Formular auffällt statt erst bei der Zustellung. Das hängt an `hook_civicrm_validateForm` für das Tab-Formular (`CRM_Contact_Form_CustomData`), die Kontaktübersicht (`CRM_Contact_Form_Inline_CustomData`, `CRM_Contact_Form_Contact`) und das Profil (`CRM_Profile_Form_Edit`), weil ein im `pre`-Hook geworfener Fehler kein Formularfehler wäre; ein ungültiger Wert ist ein Fehler im Formular, keine stille Korrektur. Schreibt jemand über APIv4 direkt, prüft niemand; der Versand prüft dieselben Regeln ohnehin.

### E-Mail

Leerer `recipient` bei einem E-Mail-Kanal heißt wie heute: die Adresse des Kontakts, nach Adresstyp und Sperren aus `EmailRecipient::of()`. Eine eingetragene Adresse gilt direkt; die Sperren des Kontakts (`do_not_email`, mit „Kommunikationssperren beachten“ auch `is_opt_out`) gelten trotzdem, weil sie dem Kontakt gehören, nicht der Adresse. `on_hold` gehört dagegen einer Adresse und gilt nur, wenn der Kanal die Adresse des Kontakts nimmt (`EmailRecipient::blocked()` gegen `EmailRecipient::of()`).

## Zustellung

### An Abonnenten eines Topics

`SubscriberLookup::findByTopic(T)` liest die Zeilen, deren `topics` `T` enthalten und deren Bezug passt (`SubscriptionScope::rowMatches()` pro Zeile statt pro Kontakt). Jede passende Zeile ist ein Empfänger. Zwei Zeilen eines Kontakts mit demselben Kanal und demselben Empfänger werden zu einer zusammengelegt; verschiedene Kanäle sind gewollt und bekommen die Nachricht jeweils.

Die Zählung der Übersprungenen (`suspended`, `scope_mismatch`, `no_channel`, `no_recipient`) zählt Zeilen statt Kontakte. Ein fehlschlagender Empfänger hält die anderen weiterhin nicht auf.

### An eine Gruppe

Der Gruppenmodus kennt keine Topics und keinen Bezug. Er erreicht jedes Mitglied über seinen Kanal aus `herald_group_delivery`; ohne einen brauchbaren bekommt das Mitglied wie heute eine E-Mail über `herald_contact_email_channel`. Eine eigene Einstellung pro Kontakt statt eines Hakens an den Abo-Zeilen, weil die Frage „über welchen Kanal erreichen mich Nachrichten an meine Gruppen“ nichts mit den Topics einer Zeile zu tun hat; ein Haken an der Zeile vermischte beides und ließe auch Zeilen mit Bezug Gruppennachrichten ohne Bezug empfangen. Die Einstellung trägt Kanal und Empfänger selbst statt auf eine Zeile zu verweisen, damit nichts ins Leere zeigt, wenn eine Zeile gelöscht wird. Ein Kanal pro Kontakt reicht; mehrere wären erst bei belegtem Bedarf nötig.

### Zählungen in den Admin-Screens

Kanalliste (Abonnenten pro Kanal, `ang/heraldAdmin/channels.js`) und Topic-Liste (`Subscriptions::subscribers()`) zählen Kontakte mit mindestens einer Zeile über `Custom_herald_subscription` statt über `herald_preferences`. Das Löschen eines Kanals oder Topics nennt im Bestätigungsdialog weiterhin die Zahl.

## Selbstverwaltung

Das Profil `herald_my_preferences` bleibt, mit den Feldern der neuen Gruppen statt der alten. Im Bearbeiten-Modus zeigt Core einzeilige Felder als Formular und mehrzeilige darunter als Liste (`CRM_Profile_Form::preProcess()`, `shiftMultiRecordFields()`), mit einem gemeinsamen Speichern-Knopf. Core-Profile können mehrzeilige Feldgruppen: Im Bearbeiten-Modus listen sie die Zeilen des Kontakts mit Ansehen, Bearbeiten und Löschen und bieten „Add New Record“ im Dialog an (`CRM_Profile_Page_MultipleRecordFieldsListing`, `multiRecord=add|update|delete` in `CRM_Profile_Form_Edit`). Der Link `/civicrm/profile/edit?gid=<id>&reset=1` bleibt damit gültig. Die Prüfung des Empfängers läuft über denselben Hook wie im Tab.

Gemessen am 23.10.2026 im Dev-Stack mit einer mehrzeiligen Wegwerf-Gruppe (Kanal, Empfänger, Topics) und einem Nutzer mit „profile edit“, „edit my contact“, aber ohne „edit all contacts“:

- `profile/edit?gid=<id>&reset=1` ohne `id` listet die eigenen Zeilen mit Ansehen, Bearbeiten, Löschen und „Add New Record“. Anlegen, Bearbeiten und Löschen eigener Zeilen funktionieren.
- Die Liste zeigt nur Profilfelder mit `is_multi_summary`; ohne das Flag meldet sie „No records“, obwohl Zeilen bestehen. `PreferencesProfile::ensure()` setzt es.
- Ohne „access all custom data“ zeigt das Profil keine benutzerdefinierten Felder, die Seite bleibt leer. Das gilt für das heutige Profil genauso; das Handbuch nennt das Recht bisher nicht.
- Eine fremde `id` leitet auf die Startseite um. Eine fremde `recordId` mit eigener `id` findet die Zeile nicht („Record doesnot exist“, beim Speichern entstünde eine neue eigene Zeile), Löschen bietet keinen Knopf an. Fremde Zeilen sind also nicht erreichbar; die Seite wirft dabei PHP-Notices im Template, ein Core-Bug für `upstream-bugs.md`.

Der Link im Handbuch bleibt `/civicrm/profile/edit?gid=<id>&reset=1`; ein fester Pfad bräuchte eine eigene Route für eine Seite, die Core schon hat.

## Upgrade

Für jeden Kontakt mit einem Datensatz in `herald_preferences`, in SQL über die Custom-Tabellen, ohne Kontaktdaten zu lesen oder zu loggen:

1. **Feldgruppe anlegen.** Die mgd-Datei deklariert `herald_subscription` neben `herald_preferences`; der Reconcile vor dem Upgrade-Schritt legt sie an.
2. **Zeilen und Gruppenkanal schreiben.** Hat der Kontakt `preferred_channel`, werden Kanal und der Empfänger aus der Spalte des Kanaltyps sein Gruppenkanal in `herald_group_delivery`, damit der Gruppenmodus ihn weiter auf demselben Weg erreicht. Hat er außerdem mindestens ein Topic, wird eine Zeile: Kanal, Empfänger, die Topics, der Bezug.
3. **Übrige Empfänger.** Werte in den Spalten anderer Typen als dem gewählten wurden bisher nie benutzt. Sie werden nicht übernommen; das Upgrade schreibt ihre Zahl pro Spalte ins Log (eine Zahl, keine Werte).
4. **Alte Feldgruppe entfernen, Profil umstellen.** `SubscriptionRows::retire()` löscht die Profilfelder auf die alten Felder und dann `herald_preferences` samt Tabelle; danach legt `PreferencesProfile::ensure()` die Felder der neuen Gruppen im Profil an.
5. **Zweiter Lauf.** Ein wiederholter Schritt legt nichts doppelt an: Er schreibt keine Zeile für Kontakte, die schon eine haben, und keinen Gruppenkanal für Kontakte, die schon einen haben.

Bis zum Upgrade-Schritt lesen Lookup und Zählungen weiter `herald_preferences` als eine Zeile pro Kontakt (`LegacyPreferences`), damit zwischen dem Austausch des Codes und `cv updb` keine Meldung verloren geht. Der Schalter ist `LegacyPreferences::pending()`: ob die alte Feldgruppe noch existiert. Die Schema-Version taugt nicht, weil ein abgebrochener Schritt sie stehen lässt, die Gruppe aber schon weg sein kann. Solange sie existiert, deklariert die mgd-Datei sie weiter (`CRM_Herald_Upgrader_PreferencesGroup`), damit der Reconcile sie nicht vorzeitig löscht.

Die Migration liest auch Kontakte im Papierkorb: Wer wiederhergestellt wird, hat seine Abos noch. Kanaltypen löst sie über alle Domains auf, weil ein Kanal nur in einer anderen Domain bekannt sein kann. Profilfelder auf die alten Felder löscht der Schritt selbst, weil Core sie mit dem Feld stehen lässt.

## Wer was ändert

| Stelle | Änderung |
|---|---|
| `managed/SubscriberPreferences.mgd.php` | neue Gruppen `herald_subscription` und `herald_group_delivery`, alte erst nach dem Upgrade weg |
| `Channel*::getSemanticFields()` | `recipient` pro abonnierbarem Typ |
| `ContactRows`, `SubscriberLookup`, `GroupRecipients`, `SubscriptionScope` | Zeilen statt Kontakt, `RECIPIENT_FIELDS` weg |
| `SubscribableTopics`, `channels.js` | Zählungen über die neue Gruppe |
| `hook_civicrm_validateForm` (`SubscriptionForm`, `SubscriptionRules`) | Empfänger und Pflicht-Topics prüfen |
| `CRM_Herald_Upgrader_PreferencesProfile` | Felder der neuen Gruppen |
| Upgrade-Schritt | Migration wie oben, mit Tests (zweite Domain, zweiter Lauf, Kontakt ohne Topics, Kontakt mit Bezug) |
| Handbuch | `delivery.md` (Subscribers, Scoped subscriptions, Group mode, nötiges Recht „access all custom data“ für das Profil), `deploy.md` (Upgrade), Screenshots |
| Tour (`extension-tours`) | Tab mit zwei Zeilen, Profil mit Zeilenliste |

## Vor dem Bau messen

Im Dev-Stack (civikitchen), Ergebnis mit Zahl in diesen Plan:

1. **Tab und Formular.** Ergebnis: Stil „Tab“ zeigt jede Zeile als Block mit lesbaren Topics, Bearbeiten und Löschen. „Tab with table“ rendert in 6.19 eine SearchKit-Anzeige mit Afform-Formularen aus `civicrm_admin_ui`; dort griffe die Prüfung über `validateForm` nicht, und die Ansicht hinge an einer optionalen Extension. herald bleibt bei „Tab“.
2. **Prüfung.** Ergebnis: Tab und Profil prüfen über `validateForm`; die Elemente heißen im Tab `custom_<Feld>_<Zeile>` (neue Zeilen negativ), im Profil `custom_<Feld>`.
3. **APIv4 `CONTAINS`.** Ergebnis: Direkt über `Custom_herald_subscription.get` funktioniert es, auch mit Sonderzeichen im Topic-Namen. `Contact.get` mit `herald_subscription.topics` liefert bei mehrzeiligen Gruppen doppelte Kontaktzeilen ohne die Feldwerte und taugt nicht; der Lookup liest deshalb die Zeilen und filtert lebende Kontakte über `entity_id.is_deleted`/`entity_id.is_deceased`.
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

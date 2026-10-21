# Plan: Kanäle als benannte Datensätze

Entwurf vom 21.10.2026, am selben Tag umgesetzt. Was beim Bau anders kam, steht unter „Abweichungen beim Bau“; Messung 1 unter „Vor dem Bau prüfen“ steht für die Installationen noch aus.

## Worum es geht

Ein Kanal ist heute ein Typ im Code (`telegram`, `webhook`, `ntfy`, `pushover`, `email`, `activity`). Zugangsdaten und Standard-Empfänger stehen in Settings, eins pro Typ: `herald_telegram_bot_token`, `herald_ntfy_server`, `herald_email_from` und so weiter. Aktionen, Topic-Routen und `Herald.send` nennen den Typ und überschreiben einzelne Keys.

Daran stören drei Dinge:

1. **Ein Konto pro Typ.** Zwei Telegram-Bots, zwei ntfy-Server oder zwei Pushover-Apps gehen nicht. Webhook kann es nur, weil URL und Token schon pro Aufruf erlaubt sind.
2. **Drei Orte für dieselbe Sache.** Ein Kanal setzt sich aus Settings, den Keys der Aktion und den Keys der Route zusammen. Welcher Wert gilt, steht nur im Code der einzelnen Kanäle.
3. **Geheimnisse liegen offen.** Die Tokens stehen als Klartext in `civicrm_setting`, `webhook_auth_token` zusätzlich in den Parametern jeder Aktion und in `civicrm_herald_buffer`.

## Das Ziel in einem Satz

Ein Kanal ist ein Datensatz mit Namen, Typ und Konfiguration; alles andere wählt ihn nur aus und nennt höchstens einen Empfänger.

| | heute | künftig |
|---|---|---|
| Zugangsdaten, Server, Absender | Setting pro Typ | im Kanal |
| Standard-Empfänger | Setting pro Typ | im Kanal |
| Empfänger einer Aktion oder Route | Key in den Parametern | unverändert |
| Darstellung (parse_mode, Titel, Priorität, …) | Key in den Parametern | im Kanal als Vorgabe, in Aktion und Route überschreibbar |
| Aktion, Route, `Herald.send` | nennen den Typ | nennen den Kanal |
| CiviRules-Aktion | bringt immer ihre eigene Zustellung mit | nennt ein Topic oder bringt ihre eigene Zustellung mit |
| Abo eines Kontakts | wählt einen Typ | wählt einen Kanal |

## Datenmodell

Ein Setting `herald_channels`, gebaut wie `herald_topics`: ein JSON-Objekt, Schlüssel ist der Name des Kanals.

```json
{"ops_bot": {"type": "telegram", "label": "Ops-Bot", "active": true, "subscribable": false,
             "config": {"bot_token": "CTK?k=…", "chat_id": "-100…"}}}
```

| Feld | Inhalt |
|---|---|
| Name (Schlüssel) | `[a-z][a-z0-9_]*`, nach dem Anlegen nicht änderbar |
| `type` | ein registrierter Typ aus `ChannelRegistry` |
| `label` | frei änderbar, erscheint in Formularen und der Abo-Auswahl |
| `active` | ein inaktiver Kanal lehnt jede Zustellung mit Fehler ab |
| `subscribable` | erscheint in der Abo-Auswahl der Kontakte |
| `config` | die Keys aus `getConfigSchema()` des Typs |

`Herald.saveChannel` und `Herald.deleteChannel` prüfen und speichern einen Kanal, wie `saveTopic`/`deleteTopic` es für Routen tun; `Setting.set` bleibt der ungeprüfte Weg. Eine eigene Tabelle brächte Schema, DAO und Tabellen-Upgrade für eine Handvoll Datensätze, die nur Admins schreiben.

Der Name ist unveränderlich, weil Aktionen, Routen, Abos und wartende Meldungen im Puffer ihn speichern. Eine Umbenennung wäre eine Datenmigration über CiviRules-Tabellen, Settings und Custom Fields; der Titel deckt den Wunsch nach einem anderen Namen ab. `deleteChannel` lehnt einen Kanal ab, den eine Route, eine Regel oder `herald_contact_email_channel` nennt.

### Keys mit Geltungsbereich

`getConfigSchema()` bekommt pro Key ein Feld `scope`:

- `channel`: nur im Kanal. Zugangsdaten und Server: `bot_token`, `webhook_auth_token`, `webhook_headers`, `ntfy_server`, `ntfy_token`, `pushover_app_token`, `email_from`.
- `delivery`: im Kanal als Vorgabe, in Aktion, Route und `Herald.send` überschreibbar. Empfänger und Darstellung.

Eine Aktion, Route oder ein `Herald.send` mit einem `channel`-Key ist ein Fehler, keine stille Übernahme. Damit gibt es genau einen Ort für Geheimnisse, und `ChannelConfig::SUBSCRIBER_SAFE_KEYS` wird zur Regel „delivery-Keys außer Empfängern“ statt einer Hand-Liste.

`webhook_url` ist beides: Empfänger und potenzielles Geheimnis (Slack-URLs enthalten das Token). Es bleibt `delivery`, weil Abonnenten ihre eigene URL brauchen.

### Geheimnisse

Keys mit `secret => TRUE` im Schema (`bot_token`, `webhook_auth_token`, `webhook_headers`, `ntfy_token`, `pushover_app_token`) verschlüsselt `saveChannel` über `Civi::service('crypto.token')->encrypt($value, 'CRED')`. Ohne `CIVICRM_CRED_KEYS` speichert Core sie im Klartext, wie das SMTP-Passwort (gemessen: `CryptoRegistry` registriert dann nur `plain` für `CRED`). `Herald.getChannels` gibt Geheimnisse nie zurück, nur `"set": true`. Ein leeres Feld beim Speichern lässt den gespeicherten Wert stehen, wie bei Passwortfeldern üblich.

Ein Geheimnis gehört zu seinem Endpunkt: Überschreibt eine Zustellung `webhook_url` (die eigene URL eines Abonnenten), gehen `webhook_auth_token` und `webhook_headers` des Kanals nicht mit. Das Schema sagt das mit `bound_to => 'webhook_url'`.

## Wer was nennt

- **CiviRules-Aktionen:** nennen entweder ein Topic oder eine eigene Zustellung, siehe unten.
- **Topic-Routen:** `channel` nennt einen Kanal, dazu Empfänger, Darstellung, Zustellmodus und Sammelfenster. Die Fallback-Route `default` bleibt.
- **`Herald.send`, `Herald.notifyDigest`:** `channel` ist Pflicht und nennt einen Kanal, oder `notifyDigest` nennt statt `actionParams` ein `topic`. Der stille Default `telegram` (`Router`, `Delivery::direct()`) entfällt.
- **`Herald.publish`:** unverändert, nennt ein Topic.
- **Abos:** `herald_preferences.preferred_channel` nennt einen Kanal. Die Empfängerfelder bleiben pro Typ (`telegram_chat_id`, `ntfy_topic`, …); `ContactRows::channelConfig()` wählt das Feld nach dem Typ des gewählten Kanals. Gruppenmitglieder ohne Wahl bekommen E-Mail über den Kanal aus `herald_contact_email_channel` (Vorgabe `email`). Der Adresslisten-Modus sendet über den Kanal der Aktion, der vom Typ `email` sein muss.
- **Check-Monitor:** unverändert, er nennt ein Topic.

### Aktionen nennen ein Topic

Drei Schichten mit je einer Aufgabe: Der **Kanal** weiß, wie gesendet wird (Typ, Zugang, Standard-Empfänger). Die **Topic-Route** weiß, wohin eine Art Nachricht geht (Kanal, Empfänger, Zustellmodus, Sammelfenster). Der **Publisher** nennt nur das Topic.

Die Aktionen „Send notification“ und „Send digest“ bekommen die Wahl „Zustellung: über ein Topic | eigene Zustellung“:

- **Über ein Topic:** Die Parameter enthalten nur `topic` (und Vorlage bzw. Report). Die Route wird beim Senden aufgelöst, nicht beim Speichern, so dass eine geänderte Route sofort für alle Regeln gilt, die sie nennen. Ein Topic ohne Eintrag nimmt die Route `default`, wie bei `Herald.publish`. Zustell-Keys neben `topic` sind ein Fehler: Eine Regel folgt der Route ganz oder gar nicht.
- **Eigene Zustellung:** wie bisher Kanal, Empfänger, Modus und Sammelfenster in der Aktion. Das Feld „Channel“ listet die aktiven Kanäle nach Titel; die Felder des gewählten Kanaltyps mit `scope: delivery` kommen aus `Herald.getChannels`, wie im Routen-Formular. Die festen Telegram-Felder und „Channel-specific config“ als JSON-Feld entfallen.

Sammelfenster und Puffer gelten für beide Aktionen gleich, weil Digests schon über `Dispatcher::dispatchRendered()` laufen. Bei `daily` zählt der Tagesversand je Topic, weil `SendWindow` nach den Parametern schlüsselt und die dann nur `topic` enthalten.

Die Regelliste zeigt eine Topic-Aktion als „Sends via topic ‚revenue‘“. Das Löschen eines Topics, das eine Regel nennt, lehnt `Herald.deleteTopic` mit der Liste der Regeln ab; der Topic-Screen zeigt pro Topic, welche Regeln es nennen.

## Oberfläche

- Administer » Herald » Channels: Liste mit Titel, Name, Typ, aktiv. Formular mit Typ-Auswahl und den Feldern aus `getConfigSchema()`, Geheimnisse als Passwortfelder. Gebaut nach dem Muster von `ang/heraldAdmin/topics.*`.
- „Test message“ (`ang/heraldAdmin/test.*`) sendet über `Herald.send` mit einem Kanalnamen und optionalem Empfänger; `Herald.testTelegram` entfällt ohne Ersatzaktion.
- Der Settings-Screen verliert alle Kanal-Settings; es bleiben Puffer, Monitor und `herald_webhook_allowed_hosts`. Die Host-Liste bleibt global, weil sie eine Sicherheitsregel der Site ist, kein Teil eines Kanals. Für `ntfy_server` gilt sie nicht: Den Server setzt nur ein Admin, und die Sperrliste privater Adressen würde ein selbst gehostetes ntfy im internen Netz blockieren.

## Upgrade

Ein Upgrade-Schritt legt pro Typ einen Kanal an, der **wie der Typ heißt**: `webhook`, `ntfy`, `email` und `activity` immer, `telegram` und `pushover` nur mit gesetztem Token. Damit bleiben die gespeicherten Kanalnamen gültig:

| Typ | Kanal-Config aus |
|---|---|
| telegram | `herald_telegram_bot_token`, `herald_telegram_default_chat_id` |
| webhook | `herald_webhook_default_url`, `herald_webhook_default_auth_token` |
| ntfy | `herald_ntfy_server`, `herald_ntfy_default_topic`, `herald_ntfy_token` |
| pushover | `herald_pushover_app_token`, `herald_pushover_default_user_key` |
| email | `herald_email_from`, `herald_email_default_to` |
| activity | leer |

Danach löscht der Schritt diese Settings. Der Kanal `email` wird immer angelegt, weil Gruppenmodus und Kontakte ohne Wahl ihn brauchen.

Dazu entfernt er Keys mit `scope: channel` aus den Parametern von Aktionen, Jobs, Routen und Pufferzeilen; die alten Kanäle lasen sie nur aus den Settings. Nur ein `webhook_auth_token` oder `webhook_headers` in einer Aktion, einem Job, einer Route oder einer Pufferzeile wird ein eigener Kanal `webhook_action_<id>`, `webhook_job_<id>`, `webhook_topic_<n>` oder `webhook_alert_<id>`, die Zustellung nennt danach diesen. Gleiche URL und gleiche Geheimnisse teilen sich einen Kanal, damit die gepufferten Meldungen einer Regel nicht je einen eigenen bekommen. Gibt es keine solchen Aktionen, entfällt der Teil.

Die Optionsliste von `preferred_channel` bleibt die Optionsgruppe des Feldes; `saveChannel` und `deleteChannel` halten ihre Werte gleich den Kanälen mit `subscribable`. Das Upgrade markiert die migrierten Kanäle als `subscribable`, so dass bestehende Werte (`telegram`, `ntfy`, …) gültig bleiben.

`channel_config` (JSON-Feld in Aktionen und Routen) entfällt: Das Upgrade schreibt seine Keys in die Parameter selbst.

## Vor dem Bau prüfen

Im civikitchen-Stack messen, bevor Code darauf baut:

1. **`CIVICRM_CRED_KEYS` auf den Installationen:** ob gesetzt, bestimmt, ob die Tokens dort verschlüsselt liegen. Lesen, nicht ändern.
2. **Puffer:** Eine wartende Meldung mit `channel: telegram` muss nach dem Upgrade über den Kanal `telegram` zugestellt werden. Belegt durch `NamedChannelsTest::testStoredDeliveriesAreRewritten`.

## Reihenfolge

1. Setting `herald_channels`, `saveChannel`/`deleteChannel`/`getChannels` mit Verschlüsselung, Tests.
2. `scope` und `secret` in allen `getConfigSchema()`, Auflösung „Kanal-Config, darüber delivery-Keys des Aufrufs“ an einer Stelle (`ChannelConfig`), Kanäle lesen keine Settings mehr.
3. Router, `Delivery`, `FanOut`, `Herald.send`/`publish`/`notifyDigest` auf Kanalnamen, ohne Default.
4. Abo-Feld und `ContactRows`.
5. Aktionen mit Topic: Parameter, Auflösung im `Dispatcher`, Regelliste, Löschschutz in `Herald.deleteTopic`.
6. Admin-Screens (Kanäle, Topic-Nutzung), Aktionsformular, Test-Screen.
7. Upgrade-Schritt mit Tests aus dem heutigen Stand jeder Tabellenzeile oben.
8. Handbuch: `channels.md` neu, `delivery.md`, `recipes.md` (Rezepte über Topics), `reference.md`, `deploy.md`, beide Sprachen.

Grobe Größe: 1000 bis 1300 Zeilen inklusive Tests, davon etwa ein Drittel Admin-Oberfläche.

## Abweichungen beim Bau

- Telegram: „Klartext“ war der leere `parse_mode`, der jetzt „nicht gesetzt, Kanal-Vorgabe gilt“ bedeutet. Klartext heißt deshalb `text`; das Upgrade schreibt `''` zu `text` um.
- Kontakte ohne eigenen Kanal im Gruppenmodus gehen über das Setting `herald_contact_email_channel` (Vorgabe `email`), nicht fest über den Kanal `email`.
- `Herald.getChannels` heißt für die Typen jetzt `Herald.getChannelTypes`; `getChannels` liefert die konfigurierten Kanäle.
- `deleteChannel` zählt auch geplante Jobs und wartende oder geparkte Meldungen im Puffer als Nutzer, `deleteTopic` geplante Jobs und den Monitor. Pufferzeilen nennen kein Topic, weil die Route vor dem Puffern aufgelöst wird.
- An Abonnenten, Gruppenmitglieder und die Adressen einer Adressliste gehen die im Kanal gespeicherten Empfänger-Keys (Standard-Chat, `email_cc`, `email_bcc`, …) nicht mit; sie gelten nur für direkte Zustellung.
- Ein Betreff aus Vorlage, Digest oder Sammelmeldung schlägt den Standardbetreff des Kanals.
- Upgrade: Ein Webhook-Token ohne eigene URL bekommt `herald_webhook_default_url`, an die es bisher ging. Eine Route bekommt URL, Token und Header, die sie von `default` erbte, in ihren eigenen Kanal; wie im alten Router ersetzt ihr eigenes `channel_config` das von `default`, Keys nur aus dem flachgeklopften `default` stehen in der Route leer. Jede Domain bekommt Kanäle aus ihren eigenen Settings und ihre Routen umgeschrieben; Webhook-Kanäle von Regelaktionen und Pufferzeilen entstehen in jeder Domain, mit der eigenen URL der Zustellung oder sonst der Default-URL der Domain; eine Domain ohne beides bekommt keinen, weil die Zustellung dort schon vorher scheiterte. Ein Job läuft nur in seiner Domain (`civicrm_job.domain_id`) und bekommt seinen Kanal nur dort. Lehnt das Webhook-Schema einen Wert ab (etwa Header ohne JSON-Objekt), entsteht der Kanal ohne ihn und ausgeschaltet, mit einer Warnung im Log; gleiche Zustellungen teilen sich auch diesen Kanal, und das Upgrade bricht nicht ab. Ein Token als Zahl wird Text, wie es der alte Kanal schickte. Ungetickte Checkboxen alter Regelaktionen (`FALSE`) entfallen, damit sie spätere Vorgaben des Kanals nicht überschreiben.
- Die Prüfung, dass ein Digest keinen Betreff-Key des Kanals setzt, gilt auch für die Route eines Topics.
- Ja/Nein-Felder des Kanals sind im Aktionsformular dreiwertig: „- Kanal-Vorgabe -“, Ja, Nein. Ein gewähltes Nein oder 0 überschreibt den Kanal, ein leeres Feld nicht.

## Offen

- nichts; entschieden am 21.10.2026: Name unveränderlich, Keys mit Geltungsbereich, `channel` Pflicht, Upgrade über Kanäle mit Typnamen, Abos nur für Kanäle mit `subscribable`.

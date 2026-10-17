---
title: Kanäle
order: 20
routes:
  - civicrm/admin/setting/herald
  - civicrm/admin/herald
---

# Kanäle

Ein Kanal stellt eine Nachricht an genau ein Ziel zu: einen Chat, ein Smartphone, ein Postfach oder eine CiviCRM-Aktivität. Aktionen, Topic-Routen und API-Aufrufe benennen den Kanal über seinen Schlüssel.

| Schlüssel | Ziel | Benötigt |
|---|---|---|
| `telegram` | Einen Telegram-Chat oder eine Gruppe, über einen Bot | Bot-Token |
| `webhook` | Einen HTTP-Endpunkt wie Slack, Discord, Mattermost oder einen eigenen | URL |
| `ntfy` | Ein ntfy-Topic auf ntfy.sh oder einem eigenen Server | Topic |
| `pushover` | Pushover-Benutzer:innen oder -Gruppen | App-Token und Benutzerschlüssel |
| `email` | Eine E-Mail-Adresse, über den ausgehenden Mailversand von CiviCRM | Nichts |
| `activity` | Eine zugewiesene CiviCRM-Aktivität | Kontakt, dem die Aktivität zugewiesen wird |

## Einen Kanal konfigurieren

Zugangsdaten und Standardziele sind Einstellungen. Die meisten davon finden Sie auf der Einstellungsseite von herald, Administrieren » Herald » Einstellungen. Sie können die Einstellungen auch mit cv setzen:

```bash
cv setting:set herald_telegram_bot_token=<bot-token>
```

Jede Zustellung kann ein eigenes Ziel und eigene Optionen angeben. Diese Schlüssel pro Aufruf haben Vorrang vor den Standardeinstellungen. Wohin sie gehören, hängt vom Aufrufer ab:

- Eine CiviRules-Aktion hat Formularfelder für die Telegram-Optionen. Alle anderen Schlüssel gehören als JSON-Objekt in das Feld „Kanalspezifische Konfiguration“.
- In einer Topic-Route stehen die Schlüssel direkt im Eintrag oder in `channel_config`.
- `Herald.send` nimmt sie in `config` entgegen.

Ist ein Schlüssel sowohl direkt als auch in `channel_config` gesetzt, gilt der Wert aus `channel_config`. Ist `channel_config` kein JSON-Objekt, schlägt der Versand fehl.

So listen Sie die Schlüssel eines Kanals mit Bezeichnungen und Typen auf:

```bash
cv api4 Herald.getChannels channel=ntfy
```

`Herald.send` reicht unbekannte Schlüssel unverändert durch und führt sie im Ergebnis unter `unknown_config_keys` auf. Ein Tippfehler zeigt sich dort und hält die Zustellung nicht auf.

`Herald.send` nimmt außerdem einen Betreff und ein Format entgegen, die für jeden Kanal funktionieren. herald setzt den Betreff als E-Mail-Betreff, ntfy-Titel, Pushover-Titel oder Betreff der Aktivität ein. Das Format, text oder html, setzt herald als Format der E-Mail und der Aktivität ein. Kanäle ohne ein solches Feld ignorieren beides. Aktionen und Topic-Routen kennen `subject` und `format` nicht und verwenden die eigenen Schlüssel des Kanals, etwa `email_subject`.

## Nachrichtenformat

Jede Nachricht beginnt als HTML. Vorlagen werden zu HTML gerendert, und eine als Klartext gesendete Nachricht wird zuerst in HTML umgewandelt und dabei maskiert. Ein Kanal, der kein HTML anzeigen kann, erhält den Klartext der Nachricht, nie das rohe Markup.

Klartext erhalten:

- Telegram ohne den Parse-Modus HTML
- die Webhook-Formate text, slack und discord
- ntfy
- Pushover, sofern `pushover_html` nicht gesetzt ist
- E-Mail und Aktivitäten im Format text

Das Webhook-Format json übergibt das HTML an den Endpunkt.

## Telegram

Legen Sie mit @BotFather einen Bot an und speichern Sie sein Token in `herald_telegram_bot_token`. Den Standard-Chat speichern Sie in `herald_telegram_default_chat_id`. Gruppenchats haben negative IDs. Jede Person kann ihre eigene Chat-ID über @userinfobot herausfinden.

| Schlüssel | Bedeutung |
|---|---|
| `chat_id` | Empfangender Chat oder empfangende Gruppe. Ohne Angabe gilt der Standard-Chat. |
| `parse_mode` | `HTML` ist der Standard. `MarkdownV2` und ein leerer Wert sind die Alternativen. |
| `disable_notification` | Ohne Ton zustellen. |
| `disable_web_page_preview` | Keine Link-Vorschauen anzeigen. |

Mit HTML zeigt Telegram die Formatierung der Vorlage. Mit leerem Parse-Modus erhält es den Klartext. Mit MarkdownV2 erhält es den Klartext, in dem jedes reservierte Zeichen maskiert ist, sodass keine Formatierung übrig bleibt.

Telegram lehnt Nachrichten mit mehr als 4096 Zeichen ab. herald kürzt eine längere Nachricht an einem Absatzumbruch, schließt offene HTML-Tags und fügt eine Zeile an, die angibt, wie lang die vollständige Nachricht war. Eine MarkdownV2-Nachricht kann trotzdem mitten in ihrer eigenen Formatierung abgeschnitten werden, und Telegram lehnt sie dann ab.

### Wenn aus einer Gruppe eine Supergruppe wird

Telegram gibt einer Gruppe eine neue Chat-ID, wenn es sie in eine Supergruppe umwandelt. Sendungen an die alte ID schlagen dann mit dem Fehler `400 group chat was upgraded to a supergroup chat` fehl. Die neue ID steht im Fehlertext unter `parameters.migrate_to_chat_id`.

Ersetzen Sie die alte ID überall, wo sie gespeichert ist:

1. In der Einstellung `herald_telegram_default_chat_id` und in jedem Eintrag von `herald_topics`.
2. In jeder CiviRules-Aktion, die sie nennt. Bearbeiten Sie diese Aktionen in den CiviRules-Formularen. CiviRules speichert Aktionsparameter als serialisiertes PHP, und ein Textersatz per SQL beschädigt sie, weil sich die Länge der Zeichenkette ändert.
3. In wartenden Meldungen in der Tabelle `civicrm_herald_buffer`. Ihre Spalte `delivery` enthält JSON.
4. In geplanten Report-Jobs. Die Chat-ID steht in `actionParams` in den JSON-Parametern des Jobs.

Meldungen, die auf das Sammeln warten, bleiben im Puffer, bis eine Sendung gelingt. Sobald jede Kopie der ID korrigiert ist, werden sie von selbst zugestellt. Meldungen, die in der Zwischenzeit geparkt wurden, müssen Sie wieder aktivieren, siehe [Geparkte Meldungen](delivery.md#geparkte-meldungen).

## Webhook

Der Webhook-Kanal sendet eine HTTP-Anfrage an eine URL. Die Einstellungen sind `herald_webhook_default_url`, `herald_webhook_default_auth_token` und `herald_webhook_allowed_hosts`.

| Schlüssel | Bedeutung |
|---|---|
| `webhook_url` | Endpunkt. Ohne Angabe gilt `herald_webhook_default_url`. |
| `webhook_method` | `POST` ist der Standard, `PUT` die Alternative. |
| `webhook_body_format` | Wie der Body aufgebaut wird, siehe unten. Der Standard ist `text`. |
| `webhook_body_key` | JSON-Schlüssel für das Format json. Der Standard ist `text`. |
| `webhook_headers` | Zusätzliche Request-Header als JSON-Objekt, das Namen auf Text oder Zahlen abbildet. Alles andere lässt den Versand scheitern. |
| `webhook_auth_token` | Wird als Bearer-Token im Authorization-Header gesendet. Ein Authorization-Header in `webhook_headers` hat Vorrang. |

Die Body-Formate:

| Format | Request-Body |
|---|---|
| `text` | Der Klartext der Nachricht |
| `json` | `{"text": "<the HTML message>"}` oder Ihr eigener Schlüssel aus `webhook_body_key` |
| `slack` | `{"text": "<the plain text>"}`, wobei die Zeichen & < > so maskiert sind, wie Slack es erwartet |
| `discord` | `{"content": "<the plain text>"}` |

Ein Slack-Incoming-Webhook im Feld „Kanalspezifische Konfiguration“ einer Aktion:

```json
{
  "webhook_url": "https://hooks.slack.com/services/T0/B0/secret",
  "webhook_body_format": "slack"
}
```

Das Standard-Token gehört zur Standard-URL. herald sendet es nur, wenn auch die URL aus `herald_webhook_default_url` stammt. Eine URL aus einer Aktion, einer Route oder aus den Einstellungen eines Kontakts erhält es nie.

herald prüft jede URL vor dem Senden:

- Sie muss http oder https verwenden und darf weder Benutzername noch Passwort enthalten.
- Standardmäßig darf sie nicht auf eine private, reservierte, link-lokale oder Cloud-Metadaten-Adresse auflösen.
- Anfragen folgen keinen Weiterleitungen, geben Sie also die endgültige URL an.

Um die Zustellung auf bekannte Dienste zu beschränken, tragen Sie deren Hostnamen unter Administrieren » Herald » Erlaubte Webhook-Hosts ein, einen pro Zeile. Beim Speichern werden ungültige Hostnamen und alle Einträge mit `https://`, einem Pfad oder einem Port abgelehnt. Mit cv setzen Sie `herald_webhook_allowed_hosts`, zum Beispiel `["hooks.slack.com"]`. Subdomains eines eingetragenen Hosts sind ebenfalls erlaubt. Sobald die Liste Einträge enthält, erreicht herald nur noch diese Hosts, und sie dürfen auch auf interne Adressen auflösen.

## ntfy

Die Einstellungen sind `herald_ntfy_server`, `herald_ntfy_default_topic` und `herald_ntfy_token`. Der Server ist standardmäßig `https://ntfy.sh`. Das Token ist nur für geschützte Topics nötig und wird als Bearer-Token gesendet.

| Schlüssel | Bedeutung |
|---|---|
| `ntfy_topic` | Topic. Ohne Angabe gilt `herald_ntfy_default_topic`. |
| `ntfy_title` | Titel der Benachrichtigung. Ohne ihn der Betreff der Vorlage oder des Reports. |
| `ntfy_priority` | 1 für die niedrigste bis 5 für die höchste Priorität, oder einer der Prioritätsnamen von ntfy. |
| `ntfy_tags` | Kommagetrennte Tags. Emoji-Tag-Namen erscheinen als Emoji. |
| `ntfy_click` | URL, die sich beim Antippen der Benachrichtigung öffnet. |
| `ntfy_markdown` | Lässt ntfy den Body als Markdown darstellen. |

Der Body ist der Klartext der Nachricht. Titel, Priorität, Tags und die Klick-URL werden als ntfy-Header übertragen.

## Pushover

Die Einstellungen sind `herald_pushover_app_token`, das Pflicht ist, und `herald_pushover_default_user_key`. Das App-Token legen Sie auf pushover.net an.

| Schlüssel | Bedeutung |
|---|---|
| `pushover_user_key` | Benutzer- oder Gruppenschlüssel. Ohne Angabe gilt der Standardschlüssel. |
| `pushover_title` | Titel der Benachrichtigung. Ohne ihn der Betreff der Vorlage oder des Reports. |
| `pushover_priority` | Von -2 für lautlos bis 2 für Notfall. |
| `pushover_retry` | Nur bei Notfall: Sekunden zwischen den Wiederholungen. Der Standard ist 60, das Minimum 30. |
| `pushover_expire` | Nur bei Notfall: Sekunden, bis die Wiederholungen enden. Der Standard ist 3600, das Maximum 10800. |
| `pushover_sound` | Ein Klangname aus der [Pushover-Klangliste](https://pushover.net/api#sounds). |
| `pushover_url` | Ein Link, der unter der Nachricht erscheint. |
| `pushover_url_title` | Die Beschriftung dieses Links. |
| `pushover_html` | Die HTML-Nachricht statt des Klartexts senden. Pushover unterstützt eine kleine Teilmenge von HTML. |

Pushover verlangt für Notfallnachrichten retry und expire. herald setzt die Standardwerte ein, wenn Sie sie weglassen, und setzt Werte außerhalb der Grenzen auf die nächstliegende Grenze.

## E-Mail

Der E-Mail-Kanal sendet über den ausgehenden Mailversand von CiviCRM. Ihre SMTP-Einstellungen, die Mail-Protokollierung und eine etwaige Umleitung für die Entwicklung gelten also.

Die Einstellungen sind `herald_email_from` und `herald_email_default_to`. Die Absenderadresse hat die Form `Name <address>`. Ist sie leer, verwendet herald die Standard-Absenderadresse der Site.

| Schlüssel | Bedeutung |
|---|---|
| `email_to` | Empfängeradresse. Ohne Angabe gilt `herald_email_default_to`. |
| `email_to_name` | Anzeigename zur Empfängeradresse. |
| `email_subject` | Betreff. Ohne ihn verwenden Aktionen und Topics den Betreff der Vorlage. Reports verwenden immer ihren eigenen. Der letzte Ersatz ist „Notification“. |
| `email_format` | `html` ist der Standard und erhält die Zeilenumbrüche der Vorlage. `text` sendet Klartext. |
| `email_cc` | Kommagetrennte CC-Adressen. |
| `email_bcc` | Kommagetrennte BCC-Adressen. |

Eine Sammelnachricht erhält die Kopfzeile mit der Anzahl als Betreff, zum Beispiel „📬 7 Meldungen, gesamt 385,00 € (gesammelt über 5 min)“. Wenn herald an Kontakte statt an feste Adressen sendet, verwendet es die eigene Adresse jedes Kontakts und überspringt Kontakte mit dem Vermerk „Do not email“ (keine E-Mails). Siehe [Zustellmodi](delivery.md#zustellmodi).

## Aktivität

Der Aktivitätskanal legt eine Benachrichtigung in CiviCRM als Aktivität an und weist sie einem Kontakt zu. Er braucht weder einen externen Dienst noch Einstellungen.

| Schlüssel | Bedeutung |
|---|---|
| `activity_assignee_contact_id` | Zugewiesener Kontakt. Pflicht. |
| `activity_source_contact_id` | Der Kontakt unter „Added by“ (hinzugefügt von). Standard ist der zugewiesene Kontakt. |
| `activity_target_contact_id` | Der Kontakt, um den es in der Aktivität geht. Optional. |
| `activity_type` | Name des Aktivitätstyps. Der Standard `herald_notification` wird mit herald ausgeliefert. |
| `activity_status` | Name des Aktivitätsstatus. Der Standard ist `Scheduled`. |
| `activity_subject` | Betreff. Ohne ihn der Betreff der Vorlage oder des Reports, sonst der Anfang der Nachricht, bis zu 255 Zeichen. |
| `activity_format` | `html` ist der Standard, `text` die Alternative. |

Typ und Status sind Optionsnamen, keine Zahlen, und müssen auf der Site aktiv sein.

## Testnachricht

Die Seite Administrieren » Herald » Testnachricht sendet eine echte Nachricht. „Einen Kanal“ sendet über einen Kanal, mit dem Ziel und den Optionen, die Sie auf der Seite eintragen. Leere Felder übernehmen die Standardwerte der Einstellungsseite. „Eine Topic-Route“ schickt die Nachricht über eine Route, wie eine echte Meldung. Hat die Route ein Sammelfenster, bleibt die Nachricht bis zum nächsten Leeren im Puffer, und die Seite weist darauf hin.

Eingabefehler listet die Seite auf. Einen Zustellfehler des Kanals selbst sehen Benutzer:innen mit der Berechtigung „view debug output“ vollständig. Alle anderen sehen eine Fehler-ID, unter der das CiviCRM-Protokoll die Fehlermeldung enthält.

Mit cv tun `Herald.send` und `Herald.publish` dasselbe:

```bash
cv api4 Herald.send channel=ntfy message='herald works' config='{"ntfy_topic": "my-topic"}'
cv api4 Herald.publish topic=ops message='herald works'
```

Die Seite Administrieren » Herald » Vorlagenvorschau rendert eine herald-Vorlage mit Beispielwerten als JSON-Objekt und sendet nichts, wie `Herald.renderTemplate`.

## Fehler

Diese Konfigurationsfehler melden die Kanäle. Bei einer direkten Zustellung erreichen sie den Aufrufer, also das API-Ergebnis oder das CiviRules-Protokoll. Bei gesammelten Zustellungen werden sie bei der wartenden Meldung gespeichert, siehe [Geparkte Meldungen](delivery.md#geparkte-meldungen).

| Fehler | Abhilfe |
|---|---|
| Telegram bot token is not configured | Setzen Sie `herald_telegram_bot_token`. |
| No chat_id supplied and no default chat ID is configured | Setzen Sie eine Chat-ID an der Aktion oder `herald_telegram_default_chat_id`. |
| Telegram API returned HTTP 400 | Der Fehlertext nennt den Grund. Die üblichen Ursachen sind eine falsche Chat-ID, ein Bot, der nicht im Chat ist, und die [Umwandlung in eine Supergruppe](#wenn-aus-einer-gruppe-eine-supergruppe-wird). |
| webhook channel requires webhook_url | Setzen Sie eine URL an der Aktion oder `herald_webhook_default_url`. |
| webhook host is not in herald_webhook_allowed_hosts | Ergänzen Sie den Host in der Liste oder verwenden Sie eine erlaubte URL. |
| webhook host resolves to a disallowed address | Die URL zeigt auf eine private oder interne Adresse. Tragen Sie den Host in `herald_webhook_allowed_hosts` ein, wenn das so gewollt ist. |
| webhook URL must use http or https | Korrigieren Sie die URL. |
| webhook URL must not contain credentials | Entfernen Sie Benutzername und Passwort aus der URL. Verwenden Sie stattdessen `webhook_auth_token` oder `webhook_headers`. |
| webhook_headers must map header names to text or numbers | Schreiben Sie die Header als JSON-Objekt aus Namen und Werten, zum Beispiel `{"X-Source": "civicrm"}`. `true`, `false`, `null`, Listen und verschachtelte Objekte sind keine Header-Werte. |
| could not resolve webhook host | Prüfen Sie den Hostnamen und das DNS des Servers. |
| ntfy channel requires ntfy_topic | Setzen Sie ein Topic an der Aktion oder `herald_ntfy_default_topic`. |
| Pushover app token not configured | Setzen Sie `herald_pushover_app_token`. |
| Pushover user/group key required | Setzen Sie einen Schlüssel an der Aktion oder `herald_pushover_default_user_key`. |
| email channel requires email_to | Setzen Sie eine Empfängeradresse an der Aktion oder `herald_email_default_to`. |
| email delivery failed | Der Mailversand von CiviCRM hat die Nachricht abgelehnt. Prüfen Sie die Einstellungen für ausgehende Mails. |
| activity channel requires activity_assignee_contact_id | Setzen Sie einen zugewiesenen Kontakt. |
| activity channel option does not exist | Der Name des Aktivitätstyps oder -status ist falsch oder deaktiviert. |
| activity_format must be "html" or "text" | Korrigieren Sie das Format. |
| Channel is not registered | Der Kanalschlüssel ist falsch geschrieben. |

## Einen Kanal hinzufügen

Kanäle sind Teil des Codes von herald. Implementieren Sie `Civi\Herald\Channel\ChannelInterface` und registrieren Sie die Klasse in `ChannelRegistry::default()`.

`getConfigSchema()` deklariert die Schlüssel des Kanals. `Herald.getChannels` zeigt sie an, und `Herald.send` ermittelt daraus `unknown_config_keys`. `getSemanticFields()` bildet den gemeinsamen Betreff und das Format auf die Schlüssel des Kanals ab, sodass Aufrufer sie nicht fest einprogrammieren müssen. Die Aktionsformulare haben eigene Felder nur für Telegram, die Schlüssel eines neuen Kanals gehören also in das Feld „Kanalspezifische Konfiguration“.

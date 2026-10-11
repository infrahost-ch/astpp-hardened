# Provisionierungs-API

Mit der Provisionierungs-API verwalten eigene Systeme (CRM, Kundenportal, Abrechnung) Kunden, SIP-Geräte und Pakete und lesen Guthaben, Paketverbrauch und Gesprächsdaten. Die API spricht JSON über HTTPS.

**Adresse:** `{{API_BASE}}`

**Maschinenlesbare Beschreibung:** [OpenAPI 3 (YAML)]({{OPENAPI_URL}}) - für Postman, Insomnia oder Client-Generatoren.

## Inhalt

1. [Zugang einrichten](#zugang-einrichten)
2. [Rollen und Sichtbarkeit](#rollen-und-sichtbarkeit)
3. [Anfragen und Antworten](#anfragen-und-antworten)
4. [Fehler](#fehler)
5. [Endpunkte im Überblick](#endpunkte-im-ueberblick)
6. [Konto und Nachschlagelisten](#konto-und-nachschlagelisten)
7. [Kunden](#kunden)
8. [Guthaben, Paketverbrauch und Gesprächsdaten](#guthaben-paketverbrauch-und-gespraechsdaten)
9. [Pakete](#pakete)
10. [SIP-Geräte](#sip-geraete)
11. [Ablauf: neuen Kunden einrichten](#ablauf-neuen-kunden-einrichten)
12. [Sicherheit](#sicherheit)

<a id="zugang-einrichten"></a>
## Zugang einrichten

1. Im Portal unter **API Access** einen neuen API-Schlüssel anlegen (Admin und Reseller: Menü *Configuration*, Kunden: Menü *My Account*).
2. Einen Namen vergeben, zum Beispiel den Namen des Systems, das den Schlüssel verwendet.
3. Die **freigegebenen IP-Adressen** eintragen, eine pro Zeile. Erlaubt sind einzelne IPv4- und IPv6-Adressen und Netze, zum Beispiel `203.0.113.10` oder `203.0.113.0/28`. Netze müssen mindestens `/8` (IPv4) bzw. `/32` (IPv6) lang sein.
4. Den Schlüssel nach dem Anlegen sofort kopieren. Er wird **nur einmal** angezeigt und lässt sich danach nicht mehr abrufen.

Der Schlüssel wird bei jeder Anfrage im HTTP-Header mitgeschickt:

```
Authorization: Bearer astpp_0123456789abcdef_Ab3...
```

Alternativ ist der Header `X-API-Key: <Schlüssel>` möglich.

Ein Schlüssel funktioniert nur von den freigegebenen Adressen. Schlüssel lassen sich im Portal jederzeit bearbeiten, sperren oder löschen. Ein gesperrter oder gelöschter Schlüssel wird sofort abgewiesen. Pro Konto sind höchstens 20 Schlüssel möglich.

Erster Test:

```
curl -s -H "Authorization: Bearer $ASTPP_API_KEY" {{API_BASE}}me
```

<a id="rollen-und-sichtbarkeit"></a>
## Rollen und Sichtbarkeit

Ein Schlüssel gehört zu einem Konto und hat dessen Rolle.

| Rolle | Sieht und verwaltet |
|---|---|
| Admin | alle Kunden, auch die der Reseller |
| Reseller | die eigenen Kunden |
| Kunde | das eigene Konto: Daten, Guthaben, Verbrauch, Gesprächsdaten, eigene SIP-Geräte |

Objekte ausserhalb der eigenen Sicht verhalten sich, als gäbe es sie nicht (`404 not_found`). Endpunkte, die eine Rolle nicht verwenden darf, antworten mit `403 forbidden`.

<a id="anfragen-und-antworten"></a>
## Anfragen und Antworten

**Anfragen**

- Nur HTTPS. Daten in Anfragen (`POST`, `PATCH`) als JSON-Objekt mit `Content-Type: application/json`, höchstens 64 KB.
- Unbekannte Felder und unbekannte Abfrageparameter werden abgewiesen (`400`), damit Tippfehler nicht unbemerkt bleiben.
- Felder, die bei `PATCH` fehlen, bleiben unverändert.

**Antworten**

- Erfolgreiche Antworten enthalten die Daten unter `data`. Listen haben zusätzlich `meta` mit `total`, `limit` und `offset`.
- `201 Created` nach dem Anlegen, `204 No Content` nach dem Löschen (ohne Inhalt).
- Zeitpunkte im Format ISO 8601 in UTC, zum Beispiel `2026-10-10T08:15:00Z`. Fehlende Zeitpunkte sind `null`.
- Beträge als Zahl in der Währung des Kunden.
- Status-Felder haben die Werte `active` oder `inactive`.

**Seitenweise abrufen**

Listen mit `meta` unterstützen `limit` (1-500, Standard 50, bei Gesprächsdaten 100) und `offset` (ab 0).

```
GET {{API_BASE}}customers?limit=100&offset=200
```

**Ratenbegrenzung**

Höchstens 10 Anfragen pro Sekunde und Quelladresse, kurzzeitig bis 40. Darüber antwortet der Server mit `429`. Nach einer kurzen Pause wiederholen.

<a id="fehler"></a>
## Fehler

Fehler haben immer dieselbe Form:

```json
{
  "error": {
    "code": "validation_failed",
    "message": "Invalid input.",
    "fields": {
      "email": "Invalid e-mail address."
    }
  }
}
```

`fields` gibt es nur bei Eingabefehlern und nennt die betroffenen Felder.

| HTTP | `code` | Bedeutung |
|---|---|---|
| 400 | `invalid_body`, `unknown_fields`, `unknown_parameters`, `invalid_parameter` | Anfrage formal falsch |
| 401 | `unauthorized` | Schlüssel fehlt, ist falsch, gesperrt oder gelöscht |
| 402 | `insufficient_balance` | Guthaben des Kunden deckt den Paketpreis nicht |
| 403 | `ip_not_allowed` | Schlüssel von dieser Adresse nicht freigegeben, die Meldung nennt die erkannte Adresse |
| 403 | `forbidden` | Endpunkt für diese Rolle nicht erlaubt |
| 404 | `not_found` | Endpunkt oder Objekt nicht vorhanden bzw. nicht sichtbar |
| 405 | `method_not_allowed` | HTTP-Methode für diesen Endpunkt nicht erlaubt |
| 409 | `conflict`, `customer_inactive` | Benutzername bereits vergeben, Kunde gesperrt |
| 413 | `payload_too_large` | Anfrage grösser als 64 KB |
| 415 | `unsupported_media_type` | Daten nicht als `application/json` gesendet |
| 422 | `validation_failed` | Feldwerte ungültig, Details in `fields` |
| 429 | - | Ratenbegrenzung überschritten |
| 500 | `internal_error` | Fehler auf dem Server |

<a id="endpunkte-im-ueberblick"></a>
## Endpunkte im Überblick

| Methode | Pfad | Rollen | Zweck |
|---|---|---|---|
| GET | `/me` | alle | Schlüssel und eigenes Konto |
| GET | `/rate-groups` | Admin, Reseller | Tarifgruppen |
| GET | `/products` | Admin, Reseller | zuweisbare Pakete |
| GET | `/customers` | Admin, Reseller | Kunden auflisten und suchen |
| POST | `/customers` | Admin, Reseller | Kunden anlegen |
| GET | `/customers/{id}` | alle | Kunde lesen |
| PATCH | `/customers/{id}` | Admin, Reseller | Kunde ändern, sperren, entsperren |
| GET | `/customers/{id}/balance` | alle | Guthaben und Kreditlimit |
| GET | `/customers/{id}/usage` | alle | Paketminuten: frei, verbraucht, übrig |
| GET | `/customers/{id}/cdrs` | alle | Gesprächsdaten |
| GET | `/customers/{id}/products` | alle | aktive Pakete |
| POST | `/customers/{id}/products` | Admin, Reseller | Paket zuweisen |
| DELETE | `/customers/{id}/products/{item_id}` | Admin, Reseller | Paket kündigen |
| GET | `/customers/{id}/sip-devices` | alle | SIP-Geräte eines Kunden |
| POST | `/customers/{id}/sip-devices` | Admin, Reseller | SIP-Gerät anlegen |
| GET | `/sip-devices/{id}` | alle | SIP-Gerät lesen |
| PATCH | `/sip-devices/{id}` | alle | Rufnummer, Codecs, Status ändern |
| POST | `/sip-devices/{id}/reset-password` | alle | neues SIP-Passwort |
| DELETE | `/sip-devices/{id}` | Admin, Reseller | SIP-Gerät löschen |

Alle Pfade beziehen sich auf `{{API_BASE}}`, zum Beispiel `{{API_BASE}}customers`.

<a id="konto-und-nachschlagelisten"></a>
## Konto und Nachschlagelisten

### GET /me

Zeigt, zu welchem Schlüssel und Konto die Anfrage gehört. Geeignet als Verbindungstest.

```json
{
  "data": {
    "key": { "id": "0123456789abcdef", "name": "CRM", "allowed_ips": ["203.0.113.10"], "created_at": "2026-10-10T08:00:00Z" },
    "account": { "id": 13, "number": "4000000013", "role": "reseller", "company_name": "Beispiel Reseller AG", "currency": "CHF", "timezone": "Europe/Zurich" }
  }
}
```

### GET /rate-groups

Tarifgruppen, die neuen Kunden zugewiesen werden können. Reseller sehen ihre eigenen, Admins alle (Filter `reseller_id`).

```json
{ "data": [ { "id": 5, "name": "Reseller-Flatrate", "reseller_id": 13, "status": "active" } ] }
```

### GET /products

Pakete, die Kunden zugewiesen werden können. Reseller sehen die Pakete, die sie im Portal aktiviert haben, mit ihrem Verkaufspreis.

```json
{
  "data": [
    { "id": 1, "name": "Flatrate - 1 Kanal", "description": "...", "type": "package",
      "price": 0.0, "setup_fee": 0.0, "free_minutes": 1500, "recurring": true, "billing_days": 30 }
  ]
}
```

<a id="kunden"></a>
## Kunden

### Felder

| Feld | Typ | Anlegen | Ändern | Beschreibung |
|---|---|---|---|---|
| `id` | Zahl | - | - | Kunden-ID |
| `number` | Text | - | - | Kontonummer, zugleich Login im Kundenportal |
| `reseller_id` | Zahl oder `null` | nur Admin | - | Reseller des Kunden, `null` für Kunden des Admins |
| `status` | `active` / `inactive` | - | ja | `inactive` sperrt Anrufe, SIP-Anmeldung und Portal-Login |
| `company_name` | Text, max. 40 | ja¹ | ja | Firma |
| `first_name`, `last_name` | Text, max. 40 | ja¹ | ja | Name |
| `email` | E-Mail, max. 80 | ja | ja | E-Mail-Adresse |
| `phone` | Text, max. 20 | ja | ja | Telefonnummer |
| `address` | Objekt | ja | ja | `line1`, `line2`, `postal_code`, `city`, `province`, `country` |
| `country` | ISO-Code, z. B. `CH` | ja | ja | Standard: Land der Systemeinstellungen |
| `currency` | Code, z. B. `CHF` | ja | - | Standard: Währung des Schlüssel-Kontos |
| `timezone` | Name, z. B. `Europe/Zurich` | ja | ja | Standard: Zeitzone des Schlüssel-Kontos |
| `billing_type` | `prepaid` / `postpaid` | ja | ja | Standard: `prepaid` |
| `credit_limit` | Zahl | ja | ja | nur bei `postpaid` wirksam |
| `max_channels` | Zahl 0-1000 | ja | ja | gleichzeitige Gespräche, `0` = unbegrenzt |
| `rate_group_id` | Zahl | **Pflicht** | ja | Tarifgruppe aus `GET /rate-groups` |
| `localization_id` | Zahl oder `null` | ja | ja | Nummernumschreibung (Wahlformat) |
| `send_welcome_email` | `true` / `false` | ja | - | Willkommensmail senden (Kontonummer und Link zum Setzen des Passworts, kein Passwort), nur mit `email` |
| `created_at`, `expires_at` | Zeitpunkt | - | - | Anlage, Ablauf des Kontos |

¹ Beim Anlegen ist `company_name` oder `first_name` nötig.

Die Tarifgruppe muss zum Reseller des Kunden gehören, bei Kunden des Admins zum Admin. Für eine Lokalisierung gilt dasselbe, zusätzlich sind Lokalisierungen des Admins erlaubt.

### GET /customers

Abfrageparameter: `limit`, `offset`, `q` (Suche in Kontonummer, Firma, Vorname, Nachname, E-Mail), `status` (`active` oder `inactive`), für Admins zusätzlich `reseller_id`.

```
GET {{API_BASE}}customers?q=muster&status=active
```

### POST /customers

```
curl -s -X POST {{API_BASE}}customers \
  -H "Authorization: Bearer $ASTPP_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
        "company_name": "Muster AG",
        "first_name": "Anna",
        "last_name": "Muster",
        "email": "anna.muster@example.com",
        "address": { "line1": "Bahnhofstrasse 1", "postal_code": "8001", "city": "Zürich", "country": "CH" },
        "rate_group_id": 5,
        "localization_id": 1,
        "max_channels": 3
      }'
```

Antwort `201` mit dem Kunden und zusätzlich `portal_password`, dem Passwort für das Kundenportal. Es wird nur in dieser Antwort geliefert; gespeichert ist nur ein Hash (Argon2id), niemand kann es später auslesen. Ohne Weitergabe setzt der Kunde sein Passwort selbst über „Passwort vergessen“ (Kontonummer und E-Mail-Adresse).

```json
{
  "data": {
    "id": 22, "number": "4000000022", "reseller_id": 13, "status": "active",
    "company_name": "Muster AG", "first_name": "Anna", "last_name": "Muster",
    "email": "anna.muster@example.com", "phone": "",
    "address": { "line1": "Bahnhofstrasse 1", "line2": "", "postal_code": "8001", "city": "Zürich", "province": "", "country": "CH" },
    "currency": "CHF", "timezone": "Europe/Zurich", "billing_type": "prepaid", "credit_limit": 0.0,
    "max_channels": 3, "rate_group_id": 5, "localization_id": 1,
    "created_at": "2026-10-10T08:20:00Z", "expires_at": "2036-10-07T08:20:00Z",
    "portal_password": "..."
  }
}
```

Ohne `send_welcome_email: true` verschickt das System keine Willkommensmail. Die Willkommensmail enthält nie ein Passwort, sondern den Link zur Seite „Passwort vergessen“. SIP-Geräte werden nicht automatisch angelegt, siehe [SIP-Geräte](#sip-geraete).

### PATCH /customers/{id}

Ändert die angegebenen Felder. Sperren und Entsperren:

```json
{ "status": "inactive" }
```

Ein gesperrter Kunde kann nicht telefonieren, seine SIP-Geräte melden sich nicht mehr an, und das Kundenportal ist gesperrt. Kunden werden über die API nicht gelöscht, Sperren ist der vorgesehene Weg.

<a id="guthaben-paketverbrauch-und-gespraechsdaten"></a>
## Guthaben, Paketverbrauch und Gesprächsdaten

### GET /customers/{id}/balance

```json
{ "data": { "customer_id": 22, "currency": "CHF", "billing_type": "prepaid", "balance": 0.0, "credit_limit": 0.0, "available": 0.0 } }
```

`available` ist bei Prepaid das Guthaben, bei Postpaid das Kreditlimit abzüglich des offenen Saldos.

### GET /customers/{id}/usage

Freiminuten aller aktiven Pakete in der laufenden Periode. Die Pakete eines Kunden bilden einen gemeinsamen Pool: Ein Gespräch kann so lange dauern, wie im Pool Minuten übrig sind.

```json
{
  "data": {
    "customer_id": 22, "free_minutes": 4500, "used_minutes": 312.0, "remaining_minutes": 4188.0,
    "packages": [
      { "id": 20, "product_id": 1, "name": "Flatrate - 1 Kanal", "free_minutes": 1500, "used_minutes": 312.0, "remaining_minutes": 1188.0,
        "period_start": "2026-10-10T00:00:01Z", "next_billing": "2026-11-08T23:59:59Z", "...": "..." }
    ]
  }
}
```

Paketminuten werden pro Gespräch auf die volle Minute aufgerundet.

### GET /customers/{id}/cdrs

Gesprächsdaten, neueste zuerst. Abfrageparameter: `from` und `to` (Datum `2026-10-01` oder Zeitpunkt `2026-10-01T12:00:00Z`, Standard: letzte 30 Tage), `limit` (Standard 100, höchstens 500), `offset`.

```json
{
  "data": [
    { "id": "8f1c...", "start": "2026-10-09T10:15:00Z", "end": "2026-10-09T10:15:24Z",
      "caller_id": "\"Buero\" <0041445550100>", "destination": "41795551234", "destination_name": "Schweiz",
      "duration": 24, "disposition": "NORMAL_CLEARING [16]", "direction": "outbound",
      "package_call": true, "charge": 0.0, "sip_user": "4000000022" }
  ],
  "meta": { "total": 1, "limit": 100, "offset": 0, "from": "2026-09-10T00:00:00Z", "to": "2026-10-10T08:30:00Z" }
}
```

`duration` ist die verrechnete Gesprächsdauer in Sekunden. `package_call` ist `true`, wenn das Gespräch über Paketminuten lief, `charge` ist dann `0`.

<a id="pakete"></a>
## Pakete

### GET /customers/{id}/products

Aktive Pakete des Kunden mit Verbrauch, gleiche Felder wie bei `usage`.

### POST /customers/{id}/products

Weist ein Paket zu. `quantity` (1-50, Standard 1) legt entsprechend viele einzelne Pakete an, zum Beispiel ein Paket pro Kanal.

```json
{ "product_id": 1, "quantity": 3 }
```

Antwort `201` mit den neu angelegten Paketen. Der Vorgang ist derselbe wie im Portal, inklusive Belegen und Reseller-Kette. Kostet das Paket etwas, muss das verfügbare Guthaben (`available`) den Preis decken, sonst `402 insufficient_balance`. Gesperrte Kunden: `409 customer_inactive`.

### DELETE /customers/{id}/products/{item_id}

Kündigt ein Paket sofort (`item_id` = `id` aus der Paketliste). Antwort `204`. Die Freiminuten des Pakets stehen danach nicht mehr zur Verfügung.

<a id="sip-geraete"></a>
## SIP-Geräte

### Felder

| Feld | Typ | Beschreibung |
|---|---|---|
| `id` | Zahl | Geräte-ID |
| `customer_id` | Zahl | Kunde |
| `username` | Text | SIP-Benutzername (Registrierung, Authentifizierung) |
| `password` | Text | nur in den Antworten auf Anlegen und Passwort-Reset |
| `status` | `active` / `inactive` | gesperrte Geräte können sich nicht anmelden |
| `caller_id_name`, `caller_id_number` | Text | feste Absenderkennung, leer = Absender der Telefonanlage wird übernommen |
| `codec` | Text | Codecs, z. B. `PCMA,PCMU`, leer = Standard |
| `created_at`, `updated_at` | Zeitpunkt | |

Registrar und Proxy ist der Server dieses Portals, Port 5060 (UDP/TCP). Bleiben `caller_id_name` und `caller_id_number` leer, wird die Rufnummer unverändert aus der Telefonanlage übernommen (Open CLI).

### POST /customers/{id}/sip-devices

Alle Felder sind optional:

```json
{ "username": "muster-pbx-01", "password": "Ein.langes-Passwort42", "caller_id_name": "", "caller_id_number": "", "codec": "PCMA,PCMU" }
```

- `username`: 4-40 Zeichen aus Buchstaben, Ziffern und `. _ -`. Fehlt er, vergibt das System eine zehnstellige Nummer. Bereits vergeben: `409 conflict`.
- `password`: 12-64 Zeichen aus Buchstaben, Ziffern und `. _ ~ ! @ # % ^ + = , : -`. Fehlt es, erzeugt das System ein zufälliges Passwort mit 24 Zeichen.
- `caller_id_number`: Ziffern, optional mit führendem `+`.

Antwort `201` mit dem Gerät und `password`.

### PATCH /sip-devices/{id}

Ändert `caller_id_name`, `caller_id_number`, `codec` und `status`. Kunden dürfen ihre eigenen Geräte ändern.

### POST /sip-devices/{id}/reset-password

Setzt ein neues Passwort. Ohne Inhalt erzeugt das System eines, alternativ `{ "password": "..." }`. Die Antwort enthält das neue `password`. Angemeldete Geräte müssen danach mit dem neuen Passwort neu registriert werden.

### DELETE /sip-devices/{id}

Löscht das Gerät. Antwort `204`.

<a id="ablauf-neuen-kunden-einrichten"></a>
## Ablauf: neuen Kunden einrichten

1. Tarifgruppe und Paket bestimmen: `GET /rate-groups`, `GET /products`.
2. Kunden anlegen: `POST /customers`. `id` und `portal_password` speichern.
3. SIP-Zugang anlegen: `POST /customers/{id}/sip-devices`. `username` und `password` in der Telefonanlage eintragen.
4. Pakete zuweisen: `POST /customers/{id}/products` mit `quantity` = Anzahl Kanäle.
5. Kontrollieren: `GET /customers/{id}/usage`.

Kündigung: Pakete mit `DELETE /customers/{id}/products/{item_id}` beenden und den Kunden mit `PATCH /customers/{id}` und `{"status": "inactive"}` sperren.

<a id="sicherheit"></a>
## Sicherheit

- API-Schlüssel wie Passwörter behandeln: nur serverseitig verwenden, nie in Webseiten, Apps oder Repositories einbauen.
- Pro System einen eigenen Schlüssel anlegen und nur die Adressen dieses Systems freigeben. Nicht mehr verwendete Schlüssel löschen.
- Bei Verdacht auf Missbrauch den Schlüssel sofort im Portal sperren oder löschen und einen neuen anlegen.
- Der Server speichert Schlüssel nur als Prüfsumme und protokolliert Anfragen ohne Schlüssel, Passwörter oder Inhalte. Im Portal sind Zeitpunkt und Adresse der letzten Verwendung jedes Schlüssels sichtbar.
- Antworten mit `portal_password` oder `password` enthalten Zugangsdaten. Diese gesichert ablegen und nicht protokollieren.

# ASTPP 6 auf Debian 13 – gehärtetes Installationspaket

Ziel: ASTPP Community Edition v6 auf einem dedizierten Debian-13-Server
betreiben – lauffähig, schneller und ohne die
Sicherheitslücken der Herstellerinstallation.

## Inhalt

| Pfad | Zweck |
|---|---|
| `install.sh` | Installer für Debian 13 (ersetzt das `install.sh` des Herstellers) |
| `install.conf.example` | Einstellungen (FQDN, E-Mail, FreeSWITCH-Token, Firewall) |
| `patches/astpp-v6-hardening.patch` | Sicherheits- und Kompatibilitätspatches für ASTPP |
| `patches/astpp-v6-php85.patch` | Portierung von ASTPP auf PHP 8.5 (wird nach dem Hardening-Patch angewendet) |
| `tools/astpp-postinstall.php` | schlüsselt Seed-Passwörter um, setzt Admin-Passwort, sperrt Demo-Konten |
| `tools/astpp-upgrade-php85.sh` | stellt eine bestehende PHP-7.4-Installation auf PHP 8.5 um (mit Sicherung) |
| `tools/astpp-smoketest.php` | lesender Funktionstest der Weboberfläche nach Änderungen (`php8.5 tools/astpp-smoketest.php`) |
| `tools/php85-port/` | Werkzeuge der Portierung (Umschreiber, Kompatibilitätstest, A/B-Testsuite) |
| `files/` | Konfigurationsvorlagen (nginx, PHP-FPM, MariaDB, FreeSWITCH, fail2ban, nftables, cron) |

## Wichtige Entscheidungen

| Thema | Hersteller | Dieses Paket | Grund |
|---|---|---|---|
| Betriebssystem | Debian 10/11, CentOS 7 | **nur Debian 13** | Herstellerskript erkennt Debian 13 gar nicht |
| PHP | 7.4 (Sury) | **8.5 (Sury, trixie-Build)** | PHP 7.4 hat seit 11/2022 keine Sicherheitsupdates mehr. ASTPP (CodeIgniter **2.1.0**, 2011) wurde dafür portiert – siehe [PHP 8.5](#php-85). |
| Datenbank | Oracle MySQL 8 (Fremd-Repo, `apt-key`) | **MariaDB 11.8 aus Debian** | MySQL-APT-Repo unterstützt trixie nicht, `apt-key` existiert in Debian 13 nicht mehr. Dump ist kompatibel (Kollation wird beim Import angepasst). |
| ODBC (FreeSWITCH-Lua) | mitgeliefertes Binary `libmyodbc8` | **`odbc-mariadb` aus Debian** | Fremd-Binary ohne Updates, gegen alte OpenSSL gelinkt |
| FreeSWITCH | `freeswitch-meta-all` (1.10, Token) | **1.11.x**: SignalWire-Pakete (Token) oder Quell-Build | trixie benötigt 1.11 (PCRE2); Quell-Build als Fallback ohne Token |
| ionCube | wird installiert | **entfällt** | nur für ein optionales Addon (`switch_monitoring`) nötig |
| Firewall | firewalld | **nftables** (+ fail2ban) | Debian-Standard, SIP-Rate-Limit pro IP |
| Telemetrie | aktiv | **entfernt** | Datenschutz (DSGVO/revDSG) |

**Blowfish/OpenSSL 3:** ASTPP speichert Passwörter mit `BF-ECB`. OpenSSL 3
(Debian 13) lädt Blowfish nur mit dem *legacy provider*. Ohne Gegenmassnahme
schlägt **jeder Login** fehl. Der Installer aktiviert den Provider ausschliesslich
für die PHP-Prozesse von ASTPP (`/etc/astpp/openssl-legacy.cnf` via
`OPENSSL_CONF`), die systemweite OpenSSL-Konfiguration bleibt unverändert.

## PHP 8.5

ASTPP 6 war nur unter PHP 7.4 lauffähig. `patches/astpp-v6-php85.patch` (464 Dateien) portiert es auf
PHP 8.5 mit dem Ziel **identischen Verhaltens**: Was unter 7.4 funktionierte, funktioniert gleich; wo
PHP 7.4 nur eine Warnung ausgab und weiterrechnete, rechnet ASTPP unter 8.5 genauso weiter.

**Vorgehen**

- Entfernte Sprachmittel ersetzt: `each()`, `create_function()`, `money_format()`, `{}`-String-Offsets,
  verschachtelte Ternäroperatoren ohne Klammern, optionale Parameter vor Pflichtparametern,
  undefinierte Konstanten (PHP 7: als String verwendet), dynamische Properties.
- **PHP-7-Semantik über `application/php7compat.php`** (identische Kopie in
  `freeswitch/fs/lib/astpp.php7compat.php`), mechanisch und tokengenau eingesetzt:
  - Vergleiche mit 0 bzw. negativen Zahlen (`'' == 0` ist unter PHP 8 `false`) → `astpp_eq0()`, `astpp_lt()` …
  - interne Funktionen, die unter PHP 8 einen TypeError werfen statt `NULL` zu liefern
    (`count(null)`, `in_array(x, null)`, `round('')`, `chr('')`, `date('Y', '')` …) → `astpp_count()` usw.
  - Arithmetik mit nicht-numerischen Strings (`'' + 1`, `'' / 60` → TypeError) und Division durch 0
    (→ `DivisionByZeroError`, PHP 7: INF/NAN): an 4 761 Stellen über `astpp_n()` / `astpp_div()`,
    AST-basiert umgeschrieben (nikic/php-parser). Jede Datei wurde danach erneut
    geparst und ihr Syntaxbaum ohne die Hilfsfunktionen **strukturell mit dem Original verglichen**.
- Alle Hilfsfunktionen sind gegen echtes PHP 7.4 verifiziert (`tools/php85-port/php7compat_test.php`,
  3 851 Fälle, 0 Abweichungen ausser `Error` vs. `TypeError` bei Arrays – beides ein Abbruch).
- PHP-8.5-Deprecations bereinigt (`curl_close()`/`imagedestroy()` …, `case X;`, `(double)`,
  `utf8_encode()`, `PDO::MYSQL_ATTR_*`, Float→Int im Captcha).

**Verifikation** (08.10.2026 auf dem Referenzserver, gegen eine Kopie der Produktionsdatenbank):
der unveränderte Code unter PHP 7.4 und der portierte unter PHP 8.5 liefen parallel auf derselben
Datenbank, jede Anfrage ging an beide (A/B).

| Bereich | Ergebnis |
|---|---|
| Admin-Oberfläche (Crawler, 512 URLs inkl. JSON-Grids) | 638 von 666 Antworten identisch, alle Abweichungen erklärt (Zufallswerte, Zeitstempel, bewusste Korrekturen) |
| Kunden- und Reseller-Portal | 65/67 bzw. 174/181 identisch, Rest wie oben |
| 65 Formulare (Anlegen/Bearbeiten) | kein Fatal Error |
| FreeSWITCH xml_curl (Directory, sofia.conf, acl.conf) + 25 CDR-Fälle (Reseller-Kette, DID, Local, Callingcard, leere/kaputte Tarifwerte, SQL-Sonderzeichen) | alle Antworten identisch, danach **alle 69 Tabellen bitgleich** |
| Cronjobs (Saldo, Gebühren, Rechnungslauf, Low-Balance, Archiv, Purge, Broadcast, Scheduler) | gleiche Ausgabe, gleiche Datenbankinhalte, Rechnungen mit identischen Positionen |
| Rechnungs-PDF (html2pdf/TCPDF) | bytegleich (ohne Erstellungsdatum/Dokument-ID) |
| CSV-Tarifimport (Origination/Termination, Umlaute, Latin-1, leere Felder) | identische Vorschau und importierte Zeilen |
| öffentliche Seiten, Signup, Captcha | identisch |

Dabei gefundene und behobene Fehler, die erst unter PHP 8 auftraten: Rechnungslauf brach ab
(`error_reporting("E_ALL")`), PDF-Erzeugung brach ab (`chr('')` in TCPDF), Cron endete mit Fatal Error
(`CRON_FLUSH_BUFFERS`), Passwortänderung im Kundenportal (String-Offset).

**Unverändert übernommene Upstream-Fehler** (verhalten sich unter 7.4 gleich): Seiten, die ohne
Pflichtparameter aufgerufen werden, liefern einen 500er; *SIP Devices → Routing* im Kundenportal
setzt das nicht installierte Addon `siprouting` voraus; *SIP-Profil bearbeiten* und *CDR-Report*
des Resellers enden mit einem SQL-Fehler; `products_listing_json` mit Fehler 500.

## Voraussetzungen

- frisch installiertes **Debian 13 (amd64)**, Minimal-Installation mit SSH
- mind. 2 CPU-Kerne, **4 GB RAM** (2 GB funktionieren für Tests), 20 GB Disk
- DNS: `astpp.example.com` (A und ggf. AAAA) zeigt auf den Server
- beim Provider/Upstream offen: 22/tcp, 80/tcp, 443/tcp, 5060/udp+tcp, 16384–32768/udp
- optional: SignalWire *Personal Access Token* (kostenloses SignalWire-Konto) für die offiziellen
  FreeSWITCH-Pakete – ohne Token wird FreeSWITCH aus dem Quellcode gebaut (20–40 Minuten)

## Installation

```bash
scp -r astpp-debian13 root@astpp.example.com:/root/
```

Auf dem Server:

```bash
cd /root/astpp-debian13 && cp install.conf.example install.conf && nano install.conf
```

```bash
bash /root/astpp-debian13/install.sh
```

Das Protokoll liegt in `/var/log/astpp-install.log`, die Zugangsdaten in
`/root/astpp-credentials.txt` (nur root lesbar; nach Übernahme in den
Passwortmanager löschen). Passwörter werden **nie** auf dem Terminal ausgegeben.

## Was der Installer macht

1. Vorabprüfung (Debian 13, amd64, root, freie Installation, SSH-Port)
2. Systemupdate, Basispakete, `unattended-upgrades` (nur Debian-Security), chrony, sysctl-Härtung
3. MariaDB 11.8: nur `127.0.0.1`, `event_scheduler=ON` (ASTPP-Reports brauchen DB-Events),
   nicht-strikter `sql_mode`, Buffer-Pool = 25 % RAM, DB-User nur mit Rechten auf `astpp`, root nur per Unix-Socket
4. PHP 8.5 (Sury), Blowfish-Selbsttest, opcache, eigener `astpp.ini`
5. nginx mit Platzhalter-Zertifikat
6. FreeSWITCH 1.11 (Pakete oder Quell-Build), systemd-Abhängigkeit auf MariaDB/nginx/PHP
7. ASTPP-Quellcode, **gepinnt** auf Commit `d5108ec6…` (Branch V6.0, 26.06.2026), beide Patchsets
   anwenden (Hardening, PHP 8.5), Upstream-Datei `index.html` (Kopie der Google-Startseite) entfernen,
   PHP/Lua-Syntaxprüfung aller geänderten Dateien; Code gehört `root` und ist für PHP nur lesbar,
   schreibbar nur `upload/`, `attachments/`, `invoices/`, `database_backup/`, `language/`, `assets/Rates_File/`, Caches
8. Konfiguration mit **pro Installation zufälligen** Schlüsseln/Passwörtern (DB, Krypto-Keys, ESL, Admin)
9. DB-Import + `astpp-postinstall.php` (Seed-Passwörter umschlüsseln, Admin-Passwort, Demo-Konten sperren, ESL-Passwort)
10. FreeSWITCH: ASTPP-Module/xml_curl/Lua, Event-Socket nur `127.0.0.1` mit Zufallspasswort,
    Vanilla-Benutzer (Passwort `1234`) entfernt, RTP-Ports = Firewall
11. PHP-FPM: zwei Pools (Web, FreeSWITCH-Endpunkt), `open_basedir`, kein `www`-Pool
12. nginx: nur `/index.php` wird ausgeführt, interne Verzeichnisse gesperrt, HSTS/Security-Header,
    Login-Rate-Limit, FreeSWITCH-Endpunkt nur auf localhost:8735; Let's Encrypt mit Auto-Renew
13. Cron als `www-data` (`/etc/cron.d/astpp`), Logrotation
14. nftables (SSH, 80/443, SIP mit Rate-Limit pro Quell-IP, RTP), fail2ban (SSH, SIP-Auth-Fehler, Web-Login)
15. Funktionsprüfung (Web, xml_curl, ODBC, SIP-Profil, Event-Scheduler, Dienste)

## Nach der Installation

1. `https://astpp.example.com/` – Login `admin` mit dem Passwort aus `/root/astpp-credentials.txt`.
2. **Einstellungen → Konfiguration**: Firmenangaben, E-Mail (SMTP), Währung, Zeitzone.
   Der Installer richtet keinen lokalen Mailserver ein – ohne SMTP-Zugang verschickt ASTPP **keine**
   Mails (Rechnungen, Guthabenwarnungen, Passwort vergessen).
3. **SIP-Profil `default` prüfen** (Switch → SIP Profiles): `sip_ip`/`ext-sip-ip`/`ext-rtp-ip` stehen auf
   `$${local_ip_v4}`. Bei NAT oder mehreren IPs die öffentliche IP eintragen.
4. Gateways/Trunks anlegen; Carrier-IPs als IP-Map erfassen. Wenn alle SIP-Gegenstellen bekannt sind,
   `SIP_ALLOW_CIDRS` setzen (Abschnitt *Firewall anpassen*).
5. Testanruf intern / extern; CDR und Abrechnung prüfen (Reports → CDRs).
6. **Backups** einrichten (Datenbank + `/var/lib/astpp/` + `/etc/freeswitch/` + Aufnahmen),
   z. B. täglich `mariadb-dump --single-transaction astpp`, verschlüsselt und extern.
   Achtung: DB-Dump **plus** `astpp-config.conf` ergibt alle Kundenpasswörter (reversibel gespeichert).
7. Die beiden Demo-Konten (`4727985745`, `3673942894`) sind gesperrt – bei Bedarf löschen.
8. Monitoring (Dienste, Zertifikat, Disk, Anzahl fail2ban-Sperren).

## Betrieb

| Aufgabe | Befehl |
|---|---|
| Dienste | `systemctl status nginx php8.5-fpm mariadb freeswitch fail2ban` |
| PHP-Fehlerdetails | standardmässig werden nur Fehler geloggt; für Warnungen/Deprecations `env[ASTPP_DEBUG_LOG] = 1` in `/etc/php/8.5/fpm/pool.d/astpp.conf`, dann `systemctl reload php8.5-fpm` |
| FreeSWITCH-Konsole | `fs_cli` (Passwort aus `/etc/fs_cli.conf`) |
| SIP-Status | `fs_cli -x 'sofia status'` |
| Logs | `/var/log/astpp/`, `/var/log/freeswitch/freeswitch.log`, `/var/log/nginx/astpp_*.log` |
| fail2ban | `fail2ban-client status freeswitch-astpp` / `fail2ban-client set freeswitch-astpp unbanip <IP>` |
| Firewall | `nft list table inet astpp` |

**Updates**
- Debian-Sicherheitsupdates laufen automatisch (`unattended-upgrades`).
- **PHP 8.5 (Sury) und FreeSWITCH** werden *nicht* automatisch aktualisiert – monatlich
  `apt update && apt list --upgradable` prüfen und gezielt einspielen.
- **ASTPP-Updates:** neuen Commit holen, beide Patchsets neu anwenden/anpassen, auf einem Testsystem
  prüfen (A/B-Testsuite in `tools/php85-port/`). Neuer Upstream-Code muss wieder durch die Umschreiber
  (`tools/php85-port/README.md`). `git -C /opt/ASTPP status` zeigt jederzeit, ob Dateien vom geprüften
  Stand abweichen.

## Bestehende Installation von PHP 7.4 auf 8.5 umstellen

Für Server, die mit der früheren Version dieses Pakets (PHP 7.4) installiert wurden:

```bash
bash /root/astpp-debian13/tools/astpp-upgrade-php85.sh
```

Das Skript sichert Code, Konfiguration und Datenbank nach `/root/astpp-pre-php85-<Zeitstempel>/`,
installiert PHP 8.5, wendet den Patch auf `/opt/ASTPP` an (Syntaxprüfung unter 8.5), legt die
PHP-8.5-Pools mit denselben Werten an, stellt nginx, Cron und die FreeSWITCH-Abhängigkeit um, prüft
Weboberfläche und xml_curl und stoppt erst dann `php7.4-fpm`. Die PHP-7.4-Pakete bleiben für ein
Rollback installiert; die Rollback-Befehle gibt das Skript am Ende aus. Wenn alles läuft:
`apt purge 'php7.4*'`.

**Firewall anpassen:** `install.conf` ändern und nur den Firewall-Teil neu erzeugen ist nicht vorgesehen –
Regeln direkt in `/etc/nftables.conf` (Sets `ssh_allow4/6`, `sip_allow4/6`) pflegen und
`systemctl reload nftables` (nicht `restart`, sonst verliert fail2ban seine Tabelle bis zum Neustart).

## Bewusste Einschränkungen

- **Addons über die Weboberfläche installieren** funktioniert nicht mehr: Das Addon-Modul kopiert
  Code per `cp -rf` in die Anwendung, was einen beschreibbaren Code-Baum voraussetzt (= jede
  Upload-Lücke wird zur Code-Ausführung). Addons bei Bedarf als root installieren und den Patch prüfen.
- `/getbalance/<nummer>` (Guthabenabfrage ohne Login) ist nur noch vom Server selbst erreichbar.
- Mehrfach-Domains (Reseller-Branding mit eigener Domain) brauchen zusätzliche `server_name`-Einträge
  und Zertifikate in `/etc/nginx/sites-available/astpp.conf`.
- Session-Cookies sind `Secure; HttpOnly; SameSite=Lax`. Zahlungsanbieter, die per **POST** auf
  ASTPP zurückleiten, sehen danach evtl. keine Sitzung (Rückleitung per GET ist unproblematisch).
- Die Schnellsuche entfernt Anführungszeichen/Backslashes aus Suchbegriffen.

## Validierung / Testplan

Der Installer (damals mit PHP 7.4) lief am 08.10.2026 auf dem Referenzserver; der Server wurde
anschliessend mit `tools/astpp-upgrade-php85.sh` auf PHP 8.5 umgestellt (Ergebnisse siehe
[PHP 8.5](#php-85)). Ein kompletter Neuinstallationslauf direkt mit PHP 8.5 steht noch aus; die
Patch-Kette des Installers (Upstream-Commit → Hardening → PHP 8.5) wurde separat nachgestellt und
ergibt exakt den getesteten Stand, `astpp-postinstall.php` lief unter 8.5 gegen eine frische Datenbank.

- [x] Login als admin, Konten anlegen/bearbeiten (Formulartests), erneut anmelden
- [x] Rechnung erzeugen (PDF), Tarif-Import (CSV)
- [x] Cronjobs laufen (`/var/log/astpp/cron.log`, `cron_settings.last_execution_date`)
- [x] FreeSWITCH-Neustart lädt das SIP-Profil über xml_curl (PHP 8.5)
- [ ] Neuinstallation auf frischem Debian 13 direkt mit PHP 8.5
- [ ] Telefon registriert sich (`fs_cli -x 'sofia status profile default reg'`)
- [ ] ausgehender Anruf über Gateway, CDR erscheint mit korrekten Kosten, Guthaben sinkt
- [ ] eingehender Anruf auf DID
- [ ] Logo-Upload, Datenbank-Backup/-Restore über die Oberfläche
- [ ] Zahlungsanbieter (PayPal/Stripe) – nur mit Sandbox-Zugang testbar
- [ ] 10 falsche SIP-Passwörter innerhalb 5 Minuten → IP wird von fail2ban gesperrt
- [ ] `curl -k https://astpp.example.com/getstatus/reload_freeswitch/status` → 404
- [ ] `curl -k https://astpp.example.com/purge/ProcessPurge` → 404

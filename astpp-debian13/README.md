# ASTPP 6 auf Debian 13 – gehärtetes Installationspaket

Ziel: ASTPP Community Edition v6 auf einem dedizierten Debian-13-Server
betreiben – lauffähig, schneller und ohne die
Sicherheitslücken der Herstellerinstallation.

## Inhalt

| Pfad | Zweck |
|---|---|
| [`../astpp/`](../astpp) | **ASTPP-Quellcode (infrahost-Fork)** – wird von Installer und Deploy unverändert installiert, siehe [Fork](#fork-astpp) |
| `install.sh` | Installer für Debian 13 (ersetzt das `install.sh` des Herstellers) |
| `install.conf.example` | Einstellungen (FQDN, E-Mail, FreeSWITCH-Token, Firewall) |
| `lib/astpp-tree.sh` | gemeinsame Funktionen von Installer und Deploy (Syntaxprüfung, Abgleich, Rechte) |
| `tools/astpp-deploy.sh` | bringt eine bestehende Installation auf den Stand von `astpp/` (mit Sicherung und Prüfungen) |
| `tools/astpp-postinstall.php` | schlüsselt Seed-Passwörter um, setzt Admin-Passwort, sperrt Demo-Konten |
| `tools/astpp-smoketest.php` | lesender Funktionstest der Weboberfläche (`php8.5 tools/astpp-smoketest.php`) |
| `tools/npmfetch.sh`, `tools/gitfetch.sh` | Bibliotheken für `astpp/` herunterladen und prüfen (npm: SHA-512 der Registry, Composer: Commit laut Packagist) |
| `tools/php85-port/` | Werkzeuge der PHP-8.5-Portierung (Umschreiber, Kompatibilitätstest, A/B-Testsuite) – auch für Code, der später aus Upstream übernommen wird |
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

ASTPP 6 war nur unter PHP 7.4 lauffähig. Der Commit *PHP 8.5 port* im Fork (464 Dateien, früher
`patches/astpp-v6-php85.patch`) portiert es auf PHP 8.5 mit dem Ziel **identischen Verhaltens**: Was unter 7.4 funktionierte, funktioniert gleich; wo
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

## Fork (`astpp/`)

Seit 09.10.2026 installieren Installer und Deploy ASTPP aus dem Verzeichnis [`astpp/`](../astpp)
dieses Repositories statt aus dem Hersteller-Repository plus Patches. Ziel ist ein **sicherer und
stabiler Fork** – Kompatibilität mit künftigen Upstream-Versionen und neue Funktionen sind bewusst
kein Ziel; Upstream-Korrekturen werden bei Bedarf von Hand übernommen.

Die Git-Historie von `astpp/` beginnt mit dem unveränderten Upstream-Stand (V6.0 @ `d5108ec6`), danach
folgt jede Änderung als eigener Commit (`git log -- astpp`): Härtung, PHP-8.5-Port, dann:

**Datenschutz** (keine Verbindung zu Dritten mehr beim Seitenaufruf)

- Google-Analytics-Tag des Herstellers (`G-Y72F0B6ZN1`) aus allen Seiten entfernt – jeder
  Seitenaufruf (auch der Admin-Oberfläche) wurde an das Analytics-Konto des Herstellers gemeldet.
- Schriften (Montserrat, Roboto, Noto Sans), Glyphicons, `jquery.browser` werden selbst ausgeliefert
  statt von Google Fonts, BootstrapCDN und cdnjs.
- Newsfeed des Herstellers im Dashboard entfernt (TLS-Prüfung aus, Inhalt ungefiltert ausgegeben).
- Fest eingebaute Nexmo-Zugangsdaten eines Dritten entfernt; SMS gehen nur noch über die in den
  Einstellungen hinterlegten Zugangsdaten, mit TLS-Prüfung.
- Lizenzprüfung gegen `portal.inextrix.com` (WHMCS, ungenutzt) und Links ins Hersteller-LAN entfernt.
- Google-reCAPTCHA-Skript von der Signup-Seite entfernt (wurde immer geladen, aber nie benutzt –
  ASTPP verwendet ein eigenes Bild-Captcha); Formular-Icons, die von einer fremden Privat-Website
  geladen wurden (`popup.css`), entfernt. Externe Skripte gibt es nur noch auf der Bezahlseite für
  Reseller-Produkte (Stripe), wenn Stripe genutzt wird.

**Sicherheit**

- Anrufaufzeichnungen lagen über einen Symlink im Web-Root (`playlist/example/media/recordings`) und
  waren **ohne Anmeldung** abrufbar. ASTPP liest sie jetzt direkt aus
  `/var/lib/freeswitch/recordings` (`recordings_path` in `astpp-config.conf`) und gibt sie nur für
  CDRs heraus, die das angemeldete Konto sehen darf (Admin: alle, Reseller: seine Kunden, sonst eigene).
  Vorher konnte jedes angemeldete Konto jede Aufnahme laden. Die Abspiel-Knöpfe in den CDR-Listen
  funktionieren unter nginx erst jetzt (eigene Route in `files/nginx/astpp.conf`).
- Rechnungsversand per E-Mail kopierte die PDF per `exec("cp …")`; der Dateiname enthält das
  Rechnungspräfix, das Reseller im Firmenprofil selbst setzen → **Befehlsausführung** auf dem Server.
  Jetzt ohne Shell, nur Dateinamen ohne Pfad.
- Status-Schalter in den Listen (Audit M9): vorher konnte jedes angemeldete Konto jeden Datensatz
  jeder Tabelle sperren/entsperren (z. B. ein Kunde fremde Konten). Jetzt nur Tabellen mit Schalter
  und je Rolle: Admin alles, Reseller seine Kunden und eigenen Daten, Kunden nur eigene
  SIP-Geräte/IP-/ANI-Einträge.
- Hintergrundaufgaben (Mailversand nach Rechnungserstellung, Währungsupdate) starten mit der
  passenden PHP-CLI statt `screen … php` (= PHP 7.4 auf dem umgestellten Server); der Mailversand
  läuft nie doppelt parallel (Sperre), sonst hätten Sofort-Start und Minuten-Cron Mails doppelt
  verschickt.
- Bibliotheken mit bekannten Lücken ersetzt (Tabelle unten), CKEditor samt öffentlicher Beispielseiten
  entfernt.

**Bibliotheken** (alle selbst ausgeliefert unter `assets/vendor/<name>-<version>/`, Downloads gegen
npm-/Packagist-Prüfsummen verifiziert)

| Komponente | vorher | jetzt | Lizenz |
|---|---|---|---|
| jQuery | 1.12.4 (+ 1.7.1) | 3.7.1 + jQuery Migrate 3.6.0 | MIT |
| jQuery UI | 1.12.1 | 1.14.2 | MIT |
| Bootstrap | 4.1.3 (+ 3.3.4) | 4.6.2 | MIT |
| bootstrap-select | 1.13.2 / 1.13.5 / 1.13.10 (XSS, CVE-2019-20921) | 1.13.18 | MIT |
| Diagramme | Highcharts 4.2.5 (**kommerzielle Lizenz** nötig) | Chart.js 4.5.1 (`assets/js/module_js/astpp_charts.js`) | MIT |
| Editor | CKEditor 4.11.4 (EOL, XSS), TinyMCE 4.3.8, markItUp | TinyMCE 8.9.3 (`astpp_editor.js`) | GPL-2.0-or-later |
| Formularprüfung | jquery.validate 1.17.0 (ReDoS) | jquery.validate 1.22.1 | MIT |
| PDF | TCPDF 5.0.002 (2010) + html2pdf 4.03 | TCPDF 6.11.4 + html2pdf 4.6.1 (PHP-8.5-portiert) | LGPL-3.0 / LGPL |
| Schriften | Google Fonts | Fontsource-Dateien | OFL-1.1 |

- html2pdf 5.x wurde bewusst **nicht** genommen: Lizenz OSL-3.0, nicht mit der AGPL von ASTPP vereinbar.
  4.6.1 ist die letzte LGPL-Version und nutzt TCPDF 6. TCPDF setzt keinen versteckten Link mehr ins PDF.
- jQuery 3 führt `ready`-Handler asynchron aus; bootstrap-select initialisiert deshalb erst nach den
  `ready`-Handlern der Seiten (wie unter jQuery 1). jQuery Migrate ist stumm geschaltet und fängt die
  verbliebenen veralteten Aufrufe ab (nur Deprecations, keine entfernten APIs).
- Unverändert: FPDF (Belege), Flexigrid, Facebox, gijgo-Datepicker, bootstrap-fileinput 3.1.3 –
  teils von ASTPP angepasst.

**Entfernt** (toter Code, Beispielseiten): Wavesurfer-Demo `playlist/` (öffentlich erreichbar),
CKEditor-Beispiele, Demo-Addons, ungenutzte Views und Bibliotheken (Select2, daterangepicker,
bootstrap-datepicker, SyntaxHighlighter, excanvas, respond.js, Bootstrap 3, jQuery Migrate 1, …),
SQL-Dumps von ASTPP 1.7–4.0, 53-MB-Partner-Upgrade-Dump, MySQL-ODBC-Tarball, ungenutzte Bilder,
macOS-Dateien, Duplikate, die sich nur in Gross-/Kleinschreibung unterschieden.

**Erscheinungsbild (Version 6.1)**

- Produktname bleibt **ASTPP**, Betreiber ist infrahost: Logo und Favicon von infrahost
  (`assets/images/brand/`, Standardbilder in `upload/`), Farben in der infrahost-CI (Blau #347CCB,
  Navy #0E2638), Login/Signup mit CI-Verlauf statt der ASTPP-Illustration. Reseller können weiter
  eigene Logos und Fusszeilen hinterlegen (White Label).
- Herstellerwerbung entfernt: Social-Media-Links, „Powered by Inextrix“, Hersteller-Copyright,
  Dashboard-Kasten (News, Forum, Dienstleistungen, Spenden), Profilmenü-Links (Doku, Bugtracker,
  „Get Addons“), Werbung für die Hersteller-App, Enterprise-/Premium-Addon-Platzhalter,
  Slogans in Seitentiteln; Firmendaten des Herstellers im Seed (`database/astpp-fork-6.1.sql`).
- Lizenz: ASTPP steht unter der GNU AGPL v3 ohne Zusatzbedingungen. Jede Seite zeigt
  „© infrahost“, „Based on ASTPP © iNextrix Technologies Pvt. Ltd.“, Lizenz und Gewährleistungs-
  ausschluss (Login-Seite in Kurzform). Die Copyright-Zeilen der Originalautoren in den
  Quelldateien bleiben; `astpp/NOTICE` beschreibt Herkunft, Änderungen und dass die
  infrahost-Logos nicht unter die AGPL fallen.
- **Noch offen (AGPL §13):** Nutzer, die die veränderte Version über das Netz verwenden, müssen den
  Quellcode genau dieser Version beziehen können. Das Angebot (z. B. Download-Link in der
  Fusszeile) wird eingerichtet, bevor externe Kunden/Reseller das System nutzen – zusammen mit
  der Meldung der Sicherheitslücken an den Hersteller, da der Quellcode die Korrekturen zeigt.
- Statische eigene Dateien (CSS, JS, Logos) werden vom Browser bei jeder Nutzung revalidiert, damit
  ein Deploy sofort sichtbar ist; nach der Umstellung einmal hart neu laden (Strg+F5).

**Weitere Korrekturen:** Konfigurationsdatei per `ASTPP_CONFIG` umstellbar (Testinstanzen),
Signup-Seite (verirrte PHP-Ausgaben brachen die Vorbelegung von Land/Zeitzone/Währung),
DID-Listen und Kontrollkästchen unter jQuery 3.

**Verifikation** (09.10.2026, Testinstanz mit Kopie der Produktionsdatenbank, Browser + Server-Crawler):
45 Admin-Seiten, 12 Kundenprofil-Unterseiten, 18 Seiten des Kundenportals und die öffentlichen
Seiten ohne JavaScript-Fehler (ausser bekannten Upstream-Fehlern); Konfigurations- und
Vorlagen-Seiten funktionieren jetzt (im Original defekt). Interaktiv geprüft: Grid-Suche, Datepicker,
Dropdowns, Menüs, Popup-Formulare (SIP-Gerät anlegen, Passwort neu erzeugen, Validierung),
Dashboard-Diagramme mit Tooltips und Zeitraumwechsel, Editor (Umlaute und `#PLATZHALTER#` bleiben),
AGB-Text ↔ AGB-URL, Rechnungs-PDF (alt/neu optisch gleich), Aufnahmen-Download mit Rechteprüfung,
Status-Schalter (25 Fälle mit Admin, Kunde und Reseller inkl. Reseller-Produkten), Formularprüfung
mit jquery.validate 1.22.1 (Signup, Passwort vergessen, PIN, IP-/ANI-Map mit eigenen Regeln und
Server-Prüfung), Hintergrund-Mailversand samt Sperre.
Crawler über 318 URLs: nur bekannte Upstream-Fehler.

## Voraussetzungen

- frisch installiertes **Debian 13 (amd64)**, Minimal-Installation mit SSH
- mind. 2 CPU-Kerne, **4 GB RAM** (2 GB funktionieren für Tests), 20 GB Disk
- DNS: `astpp.example.com` (A und ggf. AAAA) zeigt auf den Server
- beim Provider/Upstream offen: 22/tcp, 80/tcp, 443/tcp, 5060/udp+tcp, 16384–32768/udp
- optional: SignalWire *Personal Access Token* (kostenloses SignalWire-Konto) für die offiziellen
  FreeSWITCH-Pakete – ohne Token wird FreeSWITCH aus dem Quellcode gebaut (20–40 Minuten)

## Installation

Das ganze Repository (mindestens `astpp/` und `astpp-debian13/` nebeneinander) auf den Server
bringen, z. B. als `git clone` oder per Bundle:

```bash
git bundle create astpp.bundle main
```

```bash
scp astpp.bundle root@astpp.example.com:/root/ && ssh root@astpp.example.com git clone -b main /root/astpp.bundle /root/astpp
```

Auf dem Server:

```bash
cd /root/astpp/astpp-debian13 && cp install.conf.example install.conf && nano install.conf
```

```bash
bash /root/astpp/astpp-debian13/install.sh
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
7. ASTPP-Quellcode aus `astpp/` (Fork), vorher PHP/Lua-Syntaxprüfung **aller** Dateien; Code gehört
   `root` und ist für PHP nur lesbar, schreibbar nur `upload/`, `attachments/`, `invoices/`,
   `database_backup/`, `language/`, `assets/Rates_File/`, `assets/captcha/`, Caches/Logs.
   Der installierte Stand wird in `/opt/ASTPP/.git` festgehalten
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
| Logs | `/var/log/astpp/`, `/var/log/freeswitch/freeswitch.log`, `/var/log/nginx/astpp_*.log`; Rotation täglich, 14 Tage, komprimiert (`/etc/logrotate.d/astpp`, FreeSWITCH selbst rotiert nicht: `files/freeswitch/logfile.conf.xml`) |
| fail2ban | `fail2ban-client status` (Jails `sshd`, `freeswitch-astpp`, `astpp-login`) / gesperrte IP freigeben: `fail2ban-client set astpp-login unbanip <IP>` (Web-Login, 10 Fehlversuche bzw. Limit-Treffer in 10 min) |
| Firewall | `nft list table inet astpp` |
| API-Zugriffe | `/var/log/astpp/api.log` (eine JSON-Zeile pro Anfrage: Zeit, IP, Schlüssel-ID, Konto, Pfad, Status; ohne Schlüssel, Passwörter, Inhalte) |

**Provisionierungs-API (`/api/v1/`)**
- JSON-API für Kunden, SIP-Geräte, Pakete, Guthaben, Paketverbrauch und Gesprächsdaten
  (`astpp/web_interface/astpp/application/controllers/api.php`). Ersetzt das unsichere API-Addon des
  Herstellers, das aus dem Fork entfernt ist.
- Schlüssel verwalten Admin, Reseller und Kunden selbst im Portal unter **API Access**
  (Admin/Reseller: *Configuration*, Kunden: *My Account*). Jeder Schlüssel braucht freigegebene
  IP-Adressen; Admins sehen und sperren alle Schlüssel. Ein Schlüssel wird nur einmal angezeigt und nur
  als SHA-256 gespeichert (Tabelle `api_keys`).
- Rechte: Admin alle Kunden, Reseller die eigenen, Kunden das eigene Konto.
- nginx begrenzt `/api/` auf 10 Anfragen/s pro Quell-IP (Spitze 40), Anfragen höchstens 1 MB.
- Dokumentation im Portal (API Access → API documentation), Quelle
  `application/modules/apikeys/docs/api.md`, OpenAPI-Beschreibung `docs/openapi.yaml` daneben.
- Neue Menüpunkte erscheinen erst nach dem nächsten Login (das Menü wird beim Login aufgebaut).

**Updates**
- Debian-Sicherheitsupdates laufen automatisch (`unattended-upgrades`).
- **PHP 8.5 (Sury) und FreeSWITCH** werden *nicht* automatisch aktualisiert – monatlich
  `apt update && apt list --upgradable` prüfen und gezielt einspielen.
- **ASTPP-Änderungen** werden in `astpp/` gemacht (ein Commit pro Änderung), auf einem Testsystem
  geprüft und dann per Deploy eingespielt. Code aus Upstream wird von Hand übernommen und muss durch
  die Umschreiber der PHP-8.5-Portierung (`tools/php85-port/README.md`).
- `git -C /opt/ASTPP status` zeigt jederzeit, ob am Server Dateien vom installierten Stand abweichen.

## Bestehende Installation aktualisieren (Deploy)

Im aktualisierten Checkout des Repositories auf dem Server:

```bash
DRY_RUN=yes bash /root/astpp/astpp-debian13/tools/astpp-deploy.sh
```

```bash
bash /root/astpp/astpp-debian13/tools/astpp-deploy.sh
```

Das Skript prüft die Syntax aller PHP-/Lua-Dateien, plant den Abgleich (`rsync --checksum`) und bricht
ab, wenn dabei am Server geänderte oder zusätzlich angelegte Dateien verloren gingen (Liste wird
ausgegeben; `FORCE=yes` verwirft sie). Danach: Sicherung nach `/var/backups/astpp/` (Code als tar,
Datenbank per `mariadb-dump`, nginx-Site), Abgleich nach `/opt/ASTPP`, Rechte, nginx-Site neu aus der
Vorlage (FQDN, Zertifikat und Socket bleiben), PHP-FPM neu laden, Funktionsprüfung (Weboberfläche,
xml_curl, Smoketest der Admin-Seiten, SIP-Profil, neue Einträge im PHP-Fehlerlog) und Eintrag in
`/opt/ASTPP/.git`. Laufzeitdaten (Uploads, Rechnungen, Captchas, Logs, Caches) werden nie gelöscht
oder überschrieben, fehlende mitgelieferte Dateien darin ergänzt. Der Rückweg steht am Ende der
Ausgabe. Probelauf auf eine Kopie: `ASTPP_SRC=/root/kopie` (dann ohne Dienste).

Installationen des alten Installers (Upstream-Checkout + Patches) übernimmt das Skript beim ersten
Lauf; deren `.git` landet in der Sicherung.

**Firewall anpassen:** `install.conf` ändern und nur den Firewall-Teil neu erzeugen ist nicht vorgesehen –
Regeln direkt in `/etc/nftables.conf` (Sets `ssh_allow4/6`, `sip_allow4/6`) pflegen und
`systemctl reload nftables` (nicht `restart`, sonst verliert fail2ban seine Tabelle bis zum Neustart).

## Bewusste Einschränkungen

- **Addons über die Weboberfläche installieren** funktioniert nicht mehr: Das Addon-Modul kopiert
  Code per `cp -rf` in die Anwendung, was einen beschreibbaren Code-Baum voraussetzt (= jede
  Upload-Lücke wird zur Code-Ausführung). Die Addons unter `addons/` werden im Fork nicht gepflegt
  (nicht auf PHP 8.5 getestet, teils noch alte Editor-/Bibliotheksaufrufe); ein benötigtes Addon
  gehört als Commit in `astpp/`, sonst löscht der nächste Deploy seine Dateien nicht – er bricht ab.
- Dashboard-Diagramme: das Export-Menü von Highcharts (PNG/PDF-Download) gibt es nicht mehr.
- `/getbalance/<nummer>` (Guthabenabfrage ohne Login) ist nur noch vom Server selbst erreichbar.
- Mehrfach-Domains (Reseller-Branding mit eigener Domain) brauchen zusätzliche `server_name`-Einträge
  und Zertifikate in `/etc/nginx/sites-available/astpp.conf`.
- Die öffentliche Selbstregistrierung (`/signup/`) ist ab Installation abgeschaltet; bei Bedarf unter Configuration einschalten.
- Session-Cookies sind `Secure; HttpOnly; SameSite=Lax`. Zahlungsanbieter, die per **POST** auf
  ASTPP zurückleiten, sehen danach evtl. keine Sitzung (Rückleitung per GET ist unproblematisch).
- Die Schnellsuche entfernt Anführungszeichen/Backslashes aus Suchbegriffen.

## Validierung / Testplan

Der Installer (damals mit PHP 7.4) lief am 08.10.2026 auf dem Referenzserver; der Server wurde
anschliessend auf PHP 8.5 umgestellt (Ergebnisse siehe [PHP 8.5](#php-85)); der Wechsel auf den Fork
erfolgte per `tools/astpp-deploy.sh`. Ein kompletter Neuinstallationslauf mit dem Fork steht
noch aus; die Funktionen für Abgleich, Rechte und Protokoll teilen sich Installer und Deploy
(`lib/astpp-tree.sh`), das Deploy wurde vorher auf einer Kopie des Produktionscodes geprobt
(Codebaum danach identisch mit `astpp/`, Laufzeitdaten und hochgeladene Logos erhalten, lokale
Änderungen führen zum Abbruch).

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
- [ ] 10 falsche SIP-Passwörter innerhalb 5 Minuten → IP wird von fail2ban gesperrt, auch für UDP
  (`nft list table inet f2b-table` zeigt `meta l4proto { tcp, udp }`)
- [ ] `curl -k https://astpp.example.com/getstatus/reload_freeswitch/status` → 404
- [ ] `curl -k https://astpp.example.com/purge/ProcessPurge` → 404

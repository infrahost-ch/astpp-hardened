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
| `tools/astpp-postinstall.php` | schlüsselt Seed-Passwörter um, setzt Admin-Passwort, sperrt Demo-Konten |
| `files/` | Konfigurationsvorlagen (nginx, PHP-FPM, MariaDB, FreeSWITCH, fail2ban, nftables, cron) |

## Wichtige Entscheidungen

| Thema | Hersteller | Dieses Paket | Grund |
|---|---|---|---|
| Betriebssystem | Debian 10/11, CentOS 7 | **nur Debian 13** | Herstellerskript erkennt Debian 13 gar nicht |
| PHP | 7.4 (Sury) | **7.4 (Sury, trixie-Build)** | ASTPP basiert auf CodeIgniter **2.1.0** (2011). Code nutzt `each()`, `create_function()`, `mysql_*`, `money_format()` … – unter PHP 8 nicht lauffähig. Portierung = eigenes Projekt. |
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
4. PHP 7.4 (Sury), Blowfish-Selbsttest, opcache, eigener `astpp.ini`
5. nginx mit Platzhalter-Zertifikat
6. FreeSWITCH 1.11 (Pakete oder Quell-Build), systemd-Abhängigkeit auf MariaDB/nginx/PHP
7. ASTPP-Quellcode, **gepinnt** auf Commit `d5108ec6…` (Branch V6.0, 26.06.2026), Patchset anwenden,
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
| Dienste | `systemctl status nginx php7.4-fpm mariadb freeswitch fail2ban` |
| FreeSWITCH-Konsole | `fs_cli` (Passwort aus `/etc/fs_cli.conf`) |
| SIP-Status | `fs_cli -x 'sofia status'` |
| Logs | `/var/log/astpp/`, `/var/log/freeswitch/freeswitch.log`, `/var/log/nginx/astpp_*.log` |
| fail2ban | `fail2ban-client status freeswitch-astpp` / `fail2ban-client set freeswitch-astpp unbanip <IP>` |
| Firewall | `nft list table inet astpp` |

**Updates**
- Debian-Sicherheitsupdates laufen automatisch (`unattended-upgrades`).
- **PHP 7.4 (Sury) und FreeSWITCH** werden *nicht* automatisch aktualisiert – monatlich
  `apt update && apt list --upgradable` prüfen und gezielt einspielen.
- **ASTPP-Updates:** neuen Commit holen, Patchset neu anwenden/anpassen, auf einem Testsystem prüfen.
  `git -C /opt/ASTPP status` zeigt jederzeit, ob Dateien vom geprüften Stand abweichen.

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

Das Paket wurde statisch geprüft (Bash-Syntax, Template-Rendering, Paketverfügbarkeit in
Debian 13/Sury, vollständiges Review des Patches). Ein Lauf auf echter Hardware stand bei der
Erstellung **nicht** zur Verfügung. Vor dem Produktivbetrieb auf einer Debian-13-Test-VM bzw. dem
Zielserver vor Go-Live prüfen:

- [ ] Installer läuft ohne Fehler durch, keine Warnungen in der Zusammenfassung
- [ ] Login als admin, Konto anlegen, Passwort ändern, erneut anmelden
- [ ] Kunde + SIP-Gerät anlegen, Telefon registriert sich (`fs_cli -x 'sofia status profile default reg'`)
- [ ] ausgehender Anruf über Gateway, CDR erscheint mit korrekten Kosten, Guthaben sinkt
- [ ] eingehender Anruf auf DID
- [ ] Rechnung erzeugen (PDF), Logo-Upload, Tarif-Import (CSV)
- [ ] Datenbank-Backup/-Restore über die Oberfläche
- [ ] Cronjobs laufen (`/var/log/astpp/cron.log`, `cron_settings.last_execution_date`)
- [ ] 10 falsche SIP-Passwörter innerhalb 5 Minuten → IP wird von fail2ban gesperrt
- [ ] `curl -k https://astpp.example.com/getstatus/reload_freeswitch/status` → 404
- [ ] `curl -k https://astpp.example.com/purge/ProcessPurge` → 404

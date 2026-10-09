# Werkzeuge der PHP-8.5-Portierung

Mit diesen Skripten wurde der Commit *PHP 8.5 port* im Fork `astpp/` erzeugt und geprüft (früher
`patches/astpp-v6-php85.patch`); auch html2pdf 4.6.1 lief später durch dieselbe Kette. Sie werden
gebraucht, wenn Code aus Upstream oder eine PHP-Bibliothek in den Fork übernommen wird – neuer Code
muss erneut durch die Umschreiber und die A/B-Tests. Für den Betrieb sind sie nicht nötig.

Alle Skripte laufen auf dem Server als root mit PHP 8.5. `rewrite/arith_ast.php` und
`scan/find_literal_args.php` brauchen nikic/php-parser (`apt install php-parser`, nach der Arbeit
wieder entfernen).

## Umschreiber (`rewrite/`) – Reihenfolge der Portierung

| Skript | PHP-8-Änderung | Ersatz |
|---|---|---|
| `curly.pl`, `tcpdf85.pl` | `$s{0}` entfernt | `$s[0]` |
| `each85.pl` | `each()` entfernt | `foreach` |
| `fix_optional_params.php` | optionaler Parameter vor Pflichtparameter | Default entfernt (Verhalten gleich) |
| `dynprops.pl` | dynamische Properties deprecated | `#[\AllowDynamicProperties]` |
| `rewrite_cmp0.php` (+ `triage_eq0.php`) | `'' == 0` ist `false` | `astpp_eq0()`, `astpp_lt()` … |
| `rewrite_calls.php` | TypeError statt `NULL` bei falschen Argumenttypen | `astpp_count()`, `astpp_in_array()` …, mit `--names=chr` für `chr()` |
| `arith_ast.php` | TypeError bei `'' + 1`, `DivisionByZeroError` | `astpp_n()`, `astpp_div()`; prüft jede Datei per AST-Vergleich |
| `fix_dep85.php` | PHP-8.5-Deprecations | `curl_close()` & Co. nur noch < PHP 8, `case X:`, kanonische Casts |

Alle Umschreiber arbeiten tokengenau (Rest der Datei bytegleich) und haben `--dry-run`.

## Suche (`scan/`)

| Skript | findet |
|---|---|
| `find_barewords.php` | undefinierte Konstanten (PHP 7: als String verwendet, PHP 8: Error) |
| `find_literal_args.php` | nicht-numerische String-Literale an int/float-Parametern interner Funktionen |
| `find_stroffset.php` | `$string['key']` (PHP 7: erstes Zeichen, PHP 8: TypeError) – Heuristik |
| `find_dep85.php` | statisch erkennbare PHP-8.5-Deprecations |
| `count_funcs.php` | Häufigkeit interner Funktionsaufrufe (Auswahl der Wrapper) |

## Tests (`test/`)

`php7compat_test.php` vergleicht die Hilfsfunktionen mit echtem PHP 7.4:

```bash
php7.4 php7compat_test.php native > native.txt && php8.5 php7compat_test.php compat > compat.txt && diff native.txt compat.txt
```

(einziger erwarteter Unterschied: `Error` statt `TypeError` bei Arrays in Arithmetik.)

A/B-Testumgebung (portierter Code mit PHP 8.5 auf `127.0.0.2`, Originalcode mit PHP 7.4 auf
`127.0.0.3`, beide gegen eine **Kopie** der Datenbank): `testdb.sh` (Testdatenbank + Testinstanz),
`ab74.sh` (Referenz), `fs74.sh` (FreeSWITCH-Referenz mit eigener DB), dann

| Skript | prüft |
|---|---|
| `testall.sh` | Admin-Crawl, Formulare, A/B, Kunden-/Reseller-Portal (`portals.sh`, Benutzer via `mkusers.php`) |
| `fstest.sh` | xml_curl (Directory/Configuration) und CDR-Verarbeitung, danach Vergleich aller Tabellen |
| `crontest.sh` | alle Cronjobs, danach Vergleich aller Tabellen |
| `pdftest.php` | Rechnungs-PDFs |
| `importtest.php` | CSV-Tarifimport |

**Wichtig:** Die Testdatenbank ist ein Klon und enthielt anfangs den echten Event-Socket-Zugang
von FreeSWITCH. Seiten wie `freeswitch/fssipprofile_action/stop/1` schicken dann Befehle an das
**produktive** FreeSWITCH (ist bei der Portierung einmal passiert, das SIP-Profil stand ~12 Minuten).
`testdb.sh` setzt den ESL-Zugang im Klon deshalb sofort auf einen toten Port, Crawler und A/B-Vergleich
meiden `*_action/` und `rescan`. Testinstanz, Testdatenbanken und Konfigurationskopien enthalten
Produktionsdaten und -schlüssel – nach den Tests vollständig entfernen.

Nach Änderungen in Produktion: `tools/astpp-smoketest.php` (nur lesend, Admin-Login, ~90 Seiten/Grids).

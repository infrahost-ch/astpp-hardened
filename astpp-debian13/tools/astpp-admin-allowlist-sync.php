#!/usr/bin/php8.5
<?php
/*
 * astpp-admin-allowlist-sync - central management allowlist -> admin_allowed_ips (audit O5)
 *
 * Fetches the address lists named in the settings (/etc/astpp/admin-allowlist.conf, https only,
 * one address or network per line), checks and normalizes every entry, adds the addresses of
 * this server (127.0.0.0/8, ::1 and the interface addresses - the smoketest and the deploy log in
 * from there) and writes the result as admin_allowed_ips into astpp-config.conf: atomically
 * (temporary file + rename), with owner, group and mode of the existing file, all other lines
 * unchanged. Runs from cron every 15 minutes (files/cron/astpp-admin-allowlist).
 *
 * Never locks anybody out by itself: on a fetch error (TLS, HTTP status, timeout, size), an empty
 * list or a list with an invalid entry nothing is written and the previous value stays.
 * Switched off as long as /etc/astpp/admin-allowlist.disabled exists (the value then stays as it
 * is; to lift the restriction as well, empty the line admin_allowed_ips).
 *
 * Networks: ASTPP accepts IPv4 networks from /8 and IPv6 networks from /32 (the same check as the
 * allowlists of API keys, a safety net against typos like "::/0"). Broader IPv6 networks of the
 * list (/24 to /31, e.g. a provider allocation /29) are split into /32 networks - the same
 * addresses, and the check in ASTPP stays as it is. IPv4 networks broader than /8 and IPv6
 * networks broader than /24 are invalid entries.
 *
 * Usage (root):
 *   astpp-admin-allowlist-sync [--dry-run] [--settings FILE] [--target FILE]
 *     --dry-run   show the new value and the differences, write nothing
 *     --settings  default /etc/astpp/admin-allowlist.conf
 *     --target    default /var/lib/astpp/astpp-config.conf (test instance: its own config)
 * Exit status: 0 written, unchanged or switched off; 1 fetch/list error (value unchanged);
 * 2 settings or target error. Messages go to stdout and to syslog (tag astpp-admin-allowlist).
 *
 * Settings (ini format):
 *   url[] = "https://whitelist.example.net/lists/management-ipv4.txt"
 *   url[] = "https://whitelist.example.net/lists/management-ipv6.txt"
 *   extra[] = "203.0.113.10"        ; optional, further own entries
 *   ca_file = "/path/ca.pem"        ; optional, CA bundle instead of the system store
 */

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');

const MAX_BYTES = 262144;      // per list
const MAX_ENTRIES = 1000;      // after splitting, all lists together
const MIN_PREFIX_V4 = 8;       // as ASTPP (libraries/astpp/api_keys.php)
const MIN_PREFIX_V6 = 32;
const MIN_SPLIT_V6 = 24;       // broader IPv6 networks are invalid, /24../31 are split into /32
const KEY = 'admin_allowed_ips';

$opt = array('dry-run' => false, 'settings' => '/etc/astpp/admin-allowlist.conf', 'target' => '/var/lib/astpp/astpp-config.conf');
for ($i = 1; $i < count($argv); $i++) {
	$a = $argv[$i];
	if ($a === '--dry-run') {
		$opt['dry-run'] = true;
	} elseif (($a === '--settings' || $a === '--target') && isset($argv[$i + 1])) {
		$opt[substr($a, 2)] = $argv[++$i];
	} else {
		fwrite(STDERR, "usage: astpp-admin-allowlist-sync [--dry-run] [--settings FILE] [--target FILE]\n");
		exit(2);
	}
}

openlog('astpp-admin-allowlist', LOG_PID, LOG_USER);
function say($level, $msg)
{
	echo $msg, "\n";
	syslog($level, $msg);
}
/** $quiet: only to stdout (unchanged, switched off - every 15 minutes, not worth a syslog line) */
function stop($code, $msg, $quiet = false)
{
	if ($quiet) {
		echo $msg, "\n";
	} else {
		say($code === 0 ? LOG_NOTICE : LOG_ERR, $msg);
	}
	exit($code);
}

// ---------------------------------------------------------------- settings
$disabled = dirname($opt['settings']) . '/admin-allowlist.disabled';
if (is_file($disabled)) {
	stop(0, "abgeschaltet ($disabled vorhanden) - admin_allowed_ips unveraendert", true);
}
if (! is_readable($opt['settings'])) {
	stop(2, "Einstellungen {$opt['settings']} fehlen oder sind nicht lesbar - nichts geaendert");
}
$settings = @parse_ini_file($opt['settings'], false, INI_SCANNER_RAW);
if ($settings === false) {
	stop(2, "Einstellungen {$opt['settings']} nicht lesbar (ini-Format) - nichts geaendert");
}
$urls = isset($settings['url']) ? (array) $settings['url'] : array();
if (empty($urls)) {
	stop(2, "keine Liste (url[]) in {$opt['settings']} - nichts geaendert");
}
foreach ($urls as $url) {
	$p = parse_url((string) $url);
	if (! is_array($p) || strtolower($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass'])) {
		stop(2, "Liste $url: nur https-Adressen ohne Zugangsdaten erlaubt - nichts geaendert");
	}
}
$ca_file = isset($settings['ca_file']) ? (string) $settings['ca_file'] : '';
if ($ca_file !== '' && ! is_readable($ca_file)) {
	stop(2, "ca_file $ca_file nicht lesbar - nichts geaendert");
}
if (! is_file($opt['target']) || ! is_readable($opt['target'])) {
	stop(2, "Ziel {$opt['target']} fehlt - nichts geaendert");
}

// one run at a time (cron and a manual run)
$lock = fopen('/run/lock/astpp-admin-allowlist.lock', 'c');
if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
	stop(0, 'laeuft bereits - uebersprungen', true);
}

// ---------------------------------------------------------------- entries
/**
 * One entry ("a.b.c.d", "a.b.c.d/nn", IPv6 with or without /nnn) -> list of normalized entries
 * (address without prefix for single addresses, otherwise network/prefix), or an error text.
 */
function normalize_entry($entry, &$note)
{
	if (! preg_match('#^([0-9A-Fa-f:.]+)(?:/(\d{1,3}))?$#', $entry, $m)) {
		return 'kein Adressformat';
	}
	$bin = @inet_pton($m[1]);
	if ($bin === false) {
		return 'keine gueltige Adresse';
	}
	$v6 = strlen($bin) === 16;
	$max = $v6 ? 128 : 32;
	$prefix = (isset($m[2]) && $m[2] !== '') ? (int) $m[2] : $max;
	if ($prefix > $max) {
		return 'Praefix zu lang';
	}
	if ($v6 ? $prefix < MIN_SPLIT_V6 : $prefix < MIN_PREFIX_V4) {
		return 'Netz zu gross (mindestens /' . ($v6 ? MIN_SPLIT_V6 : MIN_PREFIX_V4) . ')';
	}
	$net = network($bin, $prefix);
	if ($net !== $bin) {
		$note = 'Host-Bits gesetzt, als ' . inet_ntop($net) . '/' . $prefix . ' uebernommen';
	}
	if ($v6 && $prefix < MIN_PREFIX_V6) {
		// split into /32 networks: the first 4 bytes count up
		$out = array();
		$first = unpack('N', substr($net, 0, 4))[1];
		for ($k = 0; $k < (1 << (MIN_PREFIX_V6 - $prefix)); $k++) {
			$out[] = inet_ntop(pack('N', $first + $k) . str_repeat("\0", 12)) . '/' . MIN_PREFIX_V6;
		}
		$note = trim(($note ?? '') . ' in ' . count($out) . ' Netze /' . MIN_PREFIX_V6 . ' zerlegt');
		return $out;
	}
	return array(inet_ntop($net) . ($prefix === $max ? '' : '/' . $prefix));
}
function network($bin, $prefix)
{
	$out = '';
	for ($i = 0, $n = strlen($bin); $i < $n; $i++) {
		$bits = max(0, min(8, $prefix - 8 * $i));
		$out .= chr(ord($bin[$i]) & ((0xff << (8 - $bits)) & 0xff));
	}
	return $out;
}
/** Entries of one list text; error text on the first invalid line or for an empty list. */
function parse_list($text, $what, &$entries, &$notes)
{
	if (strncmp($text, "\xEF\xBB\xBF", 3) === 0) {
		$text = substr($text, 3);
	}
	$found = 0;
	foreach (preg_split('/\r\n|\r|\n/', $text) as $no => $line) {
		$line = trim(preg_replace('/[#;].*$/', '', $line));
		if ($line === '') {
			continue;
		}
		$note = null;
		$r = normalize_entry($line, $note);
		if (! is_array($r)) {
			return "$what Zeile " . ($no + 1) . ': ' . substr(preg_replace('/[^\x20-\x7e]/', '?', $line), 0, 60) . " - $r";
		}
		if ($note !== null) {
			$notes[] = "$what Zeile " . ($no + 1) . " ($line): $note";
		}
		foreach ($r as $e) {
			$entries[] = $e;
		}
		$found++;
	}
	return $found === 0 ? "$what enthaelt keinen Eintrag" : '';
}

function fetch($url, $ca_file, &$error)
{
	$c = curl_init($url);
	$body = '';
	$too_big = false;
	curl_setopt_array($c, array(
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_MAXREDIRS => 3,
		CURLOPT_CONNECTTIMEOUT => 10,
		CURLOPT_TIMEOUT => 30,
		CURLOPT_SSL_VERIFYPEER => true,
		CURLOPT_SSL_VERIFYHOST => 2,
		CURLOPT_USERAGENT => 'astpp-admin-allowlist-sync',
		CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body, &$too_big) {
			if (strlen($body) + strlen($chunk) > MAX_BYTES) {
				$too_big = true;
				return 0;
			}
			$body .= $chunk;
			return strlen($chunk);
		}
	));
	// https only, also after redirects
	if (defined('CURLOPT_PROTOCOLS_STR')) {
		curl_setopt($c, CURLOPT_PROTOCOLS_STR, 'https');
		curl_setopt($c, CURLOPT_REDIR_PROTOCOLS_STR, 'https');
	} else {
		curl_setopt($c, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
		curl_setopt($c, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
	}
	if ($ca_file !== '') {
		curl_setopt($c, CURLOPT_CAINFO, $ca_file);
	}
	$ok = curl_exec($c);
	$status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
	if ($too_big) {
		$error = 'Liste groesser als ' . MAX_BYTES . ' Bytes';
		return null;
	}
	if ($ok === false) {
		$error = 'Abruf fehlgeschlagen: ' . curl_error($c);
		return null;
	}
	if ($status !== 200) {
		$error = "HTTP $status";
		return null;
	}
	return $body;
}

$entries = array();
$notes = array();
foreach ($urls as $url) {
	$error = '';
	$text = fetch((string) $url, $ca_file, $error);
	if ($text === null) {
		stop(1, "Liste $url: $error - admin_allowed_ips unveraendert");
	}
	$error = parse_list($text, "Liste $url", $entries, $notes);
	if ($error !== '') {
		stop(1, "$error - admin_allowed_ips unveraendert");
	}
}
$from_lists = count(array_unique($entries));
if (isset($settings['extra'])) {
	$error = parse_list(implode("\n", (array) $settings['extra']), "extra[] in {$opt['settings']}", $entries, $notes);
	if ($error !== '') {
		stop(2, "$error - admin_allowed_ips unveraendert");
	}
}
// own addresses: loopback (whole 127.0.0.0/8 - install.sh maps the FQDN to 127.0.1.1) and every
// interface address except link-local
$own = array('127.0.0.0/8', '::1');
foreach ((function_exists('net_get_interfaces') ? net_get_interfaces() : array()) ?: array() as $iface) {
	foreach ($iface['unicast'] ?? array() as $u) {
		$bin = isset($u['address']) ? @inet_pton(preg_replace('/%.*$/', '', $u['address'])) : false;
		if ($bin === false || (strlen($bin) === 16 && (ord($bin[0]) === 0xfe && (ord($bin[1]) & 0xc0) === 0x80))
			|| (strlen($bin) === 4 && (substr($bin, 0, 2) === "\xa9\xfe" || $bin[0] === "\x7f"))) {
			continue;
		}
		$own[] = inet_ntop($bin);
	}
}
foreach ($own as $e) {
	$entries[] = $e;
}
$entries = array_values(array_unique($entries));
if (count($entries) > MAX_ENTRIES) {
	stop(1, 'mehr als ' . MAX_ENTRIES . ' Eintraege - admin_allowed_ips unveraendert');
}
$value = implode(', ', $entries);

// ---------------------------------------------------------------- write
$stat = stat($opt['target']);
$content = file_get_contents($opt['target']);
$before = @parse_ini_string($content, false, INI_SCANNER_RAW);
if ($content === false || $before === false) {
	stop(2, "Ziel {$opt['target']} nicht lesbar (ini-Format) - nichts geaendert");
}
$old = isset($before[KEY]) ? trim((string) $before[KEY]) : '';
$line = KEY . ' = "' . $value . '"';
if ($old === $value) {
	stop(0, 'unveraendert (' . count($entries) . ' Eintraege)', true);
}
$old_list = $old === '' ? array() : preg_split('/[\s,;]+/', $old, -1, PREG_SPLIT_NO_EMPTY);
$added = array_diff($entries, $old_list);
$removed = array_diff($old_list, $entries);
$summary = count($entries) . " Eintraege ($from_lists aus den Listen, " . count(array_unique($own)) . ' eigene Adressen'
	. (isset($settings['extra']) ? ', extra[]' : '') . ')'
	. ($added ? '; neu: ' . implode(' ', $added) : '') . ($removed ? '; entfernt: ' . implode(' ', $removed) : '');
foreach ($notes as $n) {
	say(LOG_NOTICE, "Hinweis: $n");
}
if ($opt['dry-run']) {
	echo "$line\n";
	stop(0, "Probelauf, nichts geschrieben: $summary");
}

$count = 0;
$new = preg_replace('/^[ \t]*' . KEY . '[ \t]*=.*$/m', str_replace(array('\\', '$'), array('\\\\', '\\$'), $line), $content, -1, $count);
if ($count === 0) {
	$new = rtrim($content, "\n") . "\n; administrator logins only from these addresses/networks - written by astpp-admin-allowlist-sync\n" . $line . "\n";
} elseif ($count > 1) {
	stop(2, "Ziel {$opt['target']}: " . KEY . ' mehrfach vorhanden - nichts geaendert');
}
// every other setting must stay as it is, read raw and as ASTPP reads it (config/config.php)
$ok = true;
foreach (array(INI_SCANNER_RAW, INI_SCANNER_NORMAL) as $mode) {
	$b = @parse_ini_string($content, false, $mode);
	$a = @parse_ini_string($new, false, $mode);
	if (! is_array($a) || ! is_array($b) || trim((string) ($a[KEY] ?? '')) !== $value) {
		$ok = false;
		break;
	}
	unset($a[KEY], $b[KEY]);
	$ok = $ok && $a === $b;
}
if (! $ok) {
	stop(2, 'Pruefung der neuen Datei fehlgeschlagen - nichts geaendert');
}
$dir = dirname($opt['target']);
$tmp = $dir . '/.' . basename($opt['target']) . '.' . bin2hex(random_bytes(6));
$fh = @fopen($tmp, 'x');
if ($fh === false) {
	stop(2, "temporaere Datei in $dir nicht anlegbar - nichts geaendert");
}
$ok = fwrite($fh, $new) === strlen($new) && fflush($fh) && fsync($fh);
fclose($fh);
$ok = $ok && chmod($tmp, $stat['mode'] & 07777) && chown($tmp, $stat['uid']) && chgrp($tmp, $stat['gid']);
if (! $ok || ! rename($tmp, $opt['target'])) {
	@unlink($tmp);
	stop(2, "Schreiben von {$opt['target']} fehlgeschlagen - nichts geaendert");
}
stop(0, KEY . " in {$opt['target']} aktualisiert: $summary");

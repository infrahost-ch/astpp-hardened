<?php
/*
 * ASTPP fork (audit O4): stores violation reports of the Content-Security-Policy (POST /csp_report/,
 * called from index.php before CodeIgniter - no session, no hooks). Policy: application/hooks/csp.php.
 *
 * Accepts both formats: report-uri (application/csp-report, {"csp-report": {...}}) and the
 * Reporting API (application/reports+json, [{"type": "csp-violation", "body": {...}}]).
 * Stored deduplicated in csp_reports: page path (numbers replaced by :id, no query string),
 * directive, blocked resource (keyword or URL without query), source file and line, the first 80
 * characters of the sample; repeated reports only count up "hits". No address, no user agent, no
 * cookies. Limits: 64 KB per request, 20 reports per request, 5000 distinct rows (afterwards only
 * known rows are counted). nginx limits the requests per address (location /csp_report).
 */
const CSP_MAX_BODY = 65536;
const CSP_MAX_PER_REQUEST = 20;
const CSP_MAX_ROWS = 5000;

header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	http_response_code(405);
	exit();
}
$cfg = @parse_ini_file(getenv('ASTPP_CONFIG') ?: '/var/lib/astpp/astpp-config.conf');
$mode = is_array($cfg) ? strtolower(trim((string) ($cfg['csp'] ?? 'enforce'))) : 'off';
if ($mode === 'off') {
	http_response_code(204);
	exit();
}
$body = (string) file_get_contents('php://input', false, null, 0, CSP_MAX_BODY + 1);
if (strlen($body) > CSP_MAX_BODY) {
	http_response_code(413);
	exit();
}
$data = json_decode($body, true);
$reports = array();
if (is_array($data) && isset($data['csp-report']) && is_array($data['csp-report'])) {
	$r = $data['csp-report'];
	$reports[] = array($r['document-uri'] ?? '', $r['effective-directive'] ?? ($r['violated-directive'] ?? ''), $r['blocked-uri'] ?? '',
		$r['source-file'] ?? '', $r['line-number'] ?? 0, $r['script-sample'] ?? '');
} elseif (is_array($data) && array_is_list($data)) {
	foreach ($data as $r) {
		if (is_array($r) && ($r['type'] ?? '') === 'csp-violation' && is_array($r['body'] ?? null)) {
			$b = $r['body'];
			$reports[] = array($b['documentURL'] ?? ($r['url'] ?? ''), $b['effectiveDirective'] ?? '', $b['blockedURL'] ?? '',
				$b['sourceFile'] ?? '', $b['lineNumber'] ?? 0, $b['sample'] ?? '');
		}
	}
}
if (empty($reports)) {
	http_response_code(204);
	exit();
}

/** Path of a URL of this host (numbers -> :id), another origin as scheme://host/path, keywords as they are. */
function csp_report_url($url, $host)
{
	$url = trim((string) $url);
	if ($url === '' || preg_match('/^[a-z-]+$/i', $url)) {
		return strtolower(substr($url, 0, 32)); // inline, eval, data, blob, wasm-eval ...
	}
	$p = parse_url($url);
	if (! is_array($p) || empty($p['scheme'])) {
		return 'invalid';
	}
	if (empty($p['host'])) {
		return strtolower($p['scheme']); // data:, blob: with content
	}
	$path = preg_replace('~/\d+(?=/|$)~', '/:id', (string) ($p['path'] ?? '/'));
	$own = strtolower($p['host']) === strtolower((string) $host);
	return substr(($own ? '' : strtolower($p['scheme']) . '://' . strtolower($p['host'])) . $path, 0, 255);
}

mysqli_report(MYSQLI_REPORT_OFF);
$db = @new mysqli($cfg['dbhost'] ?? 'localhost', $cfg['dbuser'] ?? '', $cfg['dbpass'] ?? '', $cfg['dbname'] ?? '');
if ($db->connect_errno) {
	http_response_code(204);
	exit();
}
$db->set_charset('utf8');
$count = $db->query('SELECT COUNT(*) FROM csp_reports');
if ($count === false) {
	http_response_code(204); // table not created yet (deploy without astpp-fork-6.1.sql)
	exit();
}
$full = (int) $count->fetch_row()[0] >= CSP_MAX_ROWS;
$host = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
$insert = $db->prepare('INSERT INTO csp_reports (report_key, page, directive, blocked, source, line, sample, hits, first_seen, last_seen)
	VALUES (?, ?, ?, ?, ?, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = UTC_TIMESTAMP()');
$update = $db->prepare('UPDATE csp_reports SET hits = hits + 1, last_seen = UTC_TIMESTAMP() WHERE report_key = ?');
foreach (array_slice($reports, 0, CSP_MAX_PER_REQUEST) as $r) {
	$page = csp_report_url($r[0], $host);
	$directive = substr(strtolower(preg_replace('/[^a-z-].*$/i', '', trim((string) $r[1]))), 0, 64);
	$blocked = csp_report_url($r[2], $host);
	$source = csp_report_url($r[3], $host);
	$line = max(0, min(2147483647, (int) $r[4]));
	$sample = substr(preg_replace('/[^\x20-\x7e]/', '?', (string) $r[5]), 0, 80);
	$key = sha1($page . "\n" . $directive . "\n" . $blocked . "\n" . $source . "\n" . $line);
	if ($full) {
		$update->bind_param('s', $key);
		$update->execute();
	} else {
		$insert->bind_param('sssssis', $key, $page, $directive, $blocked, $source, $line, $sample);
		$insert->execute();
	}
}
http_response_code(204);

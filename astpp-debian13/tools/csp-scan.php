<?php
/*
 * csp-scan.php - what stands between the ASTPP fork and an enforced Content-Security-Policy
 * (audit O4, policy in application/hooks/csp.php). Static scan of the code, prints Markdown.
 *
 *   php csp-scan.php <path to astpp/web_interface/astpp> [--all]
 *
 * Counts per file:
 *   script  inline <script> blocks without nonce (csp_nonce_attr())
 *   handler event handler attributes (onclick=, onchange=, ... - also inside PHP/JS strings)
 *   jsurl   javascript: URLs
 *   eval    eval(), new Function(), setTimeout/setInterval with a string
 * Third-party libraries under assets/vendor are not scanned (they are loaded as files); the old
 * libraries directly under assets/js are, because they build HTML with handlers or use eval.
 * Without --all only files with findings are listed. Approximation: comments count too.
 */
error_reporting(E_ALL);
$root = rtrim($argv[1] ?? '', '/');
if ($root === '' || ! is_dir($root . '/application')) {
	fwrite(STDERR, "Aufruf: php csp-scan.php <astpp/web_interface/astpp> [--all]\n");
	exit(1);
}
$all = in_array('--all', $argv, true);

$files = array();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
	$rel = substr($f->getPathname(), strlen($root) + 1);
	$rel = str_replace('\\', '/', $rel);
	if (preg_match('~^(system|application/third_party|application/libraries/html2pdf|assets/vendor|cron|upload|database_backup)/~', $rel)) {
		continue;
	}
	if (! preg_match('~^(application/.*\.php|assets/js/.*\.js|index\.php)$~', $rel)) {
		continue;
	}
	$files[] = $rel;
}
sort($files);

$handler = '~\bon(?:click|dblclick|change|submit|load|unload|keyup|keydown|keypress|blur|focus|focusin|focusout|input|mouseover|mouseout|mouseenter|mouseleave|mousedown|mouseup|select|reset|error|paste|contextmenu)\s*=~i';
$rows = array();
$tot = array('script' => 0, 'handler' => 0, 'jsurl' => 0, 'eval' => 0);
foreach ($files as $rel) {
	$s = file_get_contents($root . '/' . $rel);
	$c = array('script' => 0, 'handler' => 0, 'jsurl' => 0, 'eval' => 0);
	if (substr($rel, -4) === '.php') {
		preg_match_all('~<script\b[^>]*>~i', $s, $m);
		foreach ($m[0] as $tag) {
			if (stripos($tag, 'src=') === false && strpos($tag, 'csp_nonce_attr') === false && stripos($tag, 'text/template') === false && stripos($tag, 'text/html') === false) {
				$c['script']++;
			}
		}
	}
	// handlers in HTML and inside strings (PHP building HTML, JS building HTML); jQuery .on() calls do not match
	$c['handler'] = preg_match_all($handler, $s);
	$c['jsurl'] = preg_match_all('~javascript\s*:~i', $s);
	$c['eval'] = preg_match_all('~\beval\s*\(|\bnew\s+Function\s*\(|\bset(?:Timeout|Interval)\s*\(\s*["\']~', $s);
	$sum = array_sum($c);
	if ($sum > 0 || $all) {
		$rows[] = array($rel, $c, $sum);
	}
	foreach ($c as $k => $v) {
		$tot[$k] += $v;
	}
}

/** Area of a file for the summary. */
function area($rel)
{
	if (preg_match('~^application/modules/([^/]+)/views/~', $rel, $m)) return 'Module ' . $m[1] . ' (Views)';
	if (preg_match('~^application/modules/([^/]+)/~', $rel, $m)) return 'Module ' . $m[1] . ' (Code, baut HTML)';
	if (strpos($rel, 'application/views/') === 0) return 'Layouts (application/views)';
	if (strpos($rel, 'assets/js/') === 0) return 'JavaScript-Dateien (assets/js)';
	return 'Übrige (application/libraries, controllers, …)';
}
$areas = array();
foreach ($rows as $r) {
	$a = area($r[0]);
	if (! isset($areas[$a])) $areas[$a] = array('files' => 0, 'script' => 0, 'handler' => 0, 'jsurl' => 0, 'eval' => 0);
	if ($r[2] > 0) $areas[$a]['files']++;
	foreach ($r[1] as $k => $v) $areas[$a][$k] += $v;
}
uasort($areas, function ($x, $y) { return ($y['script'] + $y['handler'] + $y['jsurl'] + $y['eval']) <=> ($x['script'] + $x['handler'] + $x['jsurl'] + $x['eval']); });

echo "| Bereich | Dateien | Inline-Skripte ohne Nonce | Event-Handler-Attribute | `javascript:`-URLs | eval & Co. |\n|---|---|---|---|---|---|\n";
foreach ($areas as $a => $v) {
	printf("| %s | %d | %d | %d | %d | %d |\n", $a, $v['files'], $v['script'], $v['handler'], $v['jsurl'], $v['eval']);
}
printf("| **Summe** | **%d** | **%d** | **%d** | **%d** | **%d** |\n\n", count(array_filter($rows, function ($r) { return $r[2] > 0; })), $tot['script'], $tot['handler'], $tot['jsurl'], $tot['eval']);

usort($rows, function ($x, $y) { return $y[2] <=> $x[2] ?: strcmp($x[0], $y[0]); });
echo "| Datei | Skripte | Handler | `javascript:` | eval |\n|---|---|---|---|---|\n";
foreach ($rows as $r) {
	printf("| `%s` | %d | %d | %d | %d |\n", $r[0], $r[1]['script'], $r[1]['handler'], $r[1]['jsurl'], $r[1]['eval']);
}

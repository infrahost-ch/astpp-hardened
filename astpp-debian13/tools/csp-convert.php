<?php
/*
 * csp-convert.php - mechanical part of the Content-Security-Policy conversion (audit O4) of the
 * ASTPP fork; for the fork itself (done 11.10.2026) and for code taken over from upstream later.
 *
 *   php -d short_open_tag=1 csp-convert.php <astpp/web_interface/astpp> [--write] [files...]
 *
 * 1. Inline <script> blocks in the HTML parts of PHP files get the nonce of the policy
 *    (<script ...<?php echo csp_nonce_attr(); ?>>), also <script src=...> (jQuery runs the scripts
 *    of AJAX popups only with the nonce of the tag). Script blocks built in PHP code are reported.
 * 2. Event handler attributes on<event>="..." become data-astpp-<event>="..." (run without eval by
 *    assets/js/astpp_csp.js): in HTML parts, in PHP strings (code that builds HTML), as keys of
 *    form field arrays ('onclick' => ...), and in JavaScript strings that build HTML.
 * 3. href="javascript:void(0)" becomes href="#" (astpp_csp.js prevents the navigation).
 *
 * Every handler that can be read statically is checked against the grammar of astpp_csp.js and
 * its list of callable functions (CALLS). Prints the changes and what needs a manual look:
 * handlers that do not parse, unknown functions, unquoted handler attributes, other on* events,
 * javascript: URLs with code, script blocks in PHP strings. Without --write nothing is changed.
 */
error_reporting(E_ALL);
if (! ini_get('short_open_tag')) {
	fwrite(STDERR, "short_open_tag muss an sein (php -d short_open_tag=1 ...), wie in der ASTPP-Konfiguration\n");
	exit(2);
}
$args = array_slice($argv, 1);
$write = in_array('--write', $args, true);
$args = array_values(array_diff($args, array('--write')));
$root = rtrim($args[0] ?? '', '/');
if ($root === '' || ! is_dir($root . '/application')) {
	fwrite(STDERR, "Aufruf: php -d short_open_tag=1 csp-convert.php <astpp/web_interface/astpp> [--write] [Dateien...]\n");
	exit(2);
}

const EVENTS = 'click|dblclick|change|input|keyup|keydown|keypress|submit|mouseover|mouseout|focus|blur|load';
const PH_OPEN = "\x02";
const PH_CLOSE = "\x03";

// callable functions of astpp_csp.js
$calls = array();
$disp = @file_get_contents($root . '/assets/js/astpp_csp.js');
if ($disp !== false && preg_match('~var CALLS = \[(.*?)\];~s', $disp, $m)) {
	preg_match_all("~'([A-Za-z_\$][\w\$]*)'~", $m[1], $mm);
	$calls = array_flip($mm[1]);
}
$builtin = array_flip(array('confirm', 'alert', 'window.history.back', 'history.back', 'this.form.submit'));

$files = array_slice($args, 1);
if (empty($files)) {
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
	foreach ($it as $f) {
		$rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
		if ($rel !== 'system/helpers/form_helper.php' && preg_match('~^(system|application/third_party|application/libraries/html2pdf|application/cache|assets/vendor|cron|upload|database_backup)/~', $rel)) {
			continue;
		}
		// system/helpers/form_helper.php: changed by the fork, builds HTML of ASTPP (dropdowns, labels)
		if (preg_match('~^(application/.*|system/helpers/form_helper)\.php$~', $rel) && $rel !== 'application/hooks/csp.php') {
			$files[] = $rel;
		} elseif (preg_match('~^assets/js/.*\.js$~', $rel) && ! preg_match('~(\.min\.js|/astpp_csp\.js|/astpp_csrf\.js|/modernizr\.custom\.js|/popup/custom\.js)$~', $rel)) {
			$files[] = $rel;
		}
	}
}
sort($files);

$report = array('change' => array(), 'manual' => array(), 'handlers' => array());
function note($kind, $file, $line, $text)
{
	global $report;
	$report[$kind][] = sprintf('%s:%d  %s', $file, $line, $text);
}

/** Static check of a handler text against the grammar of astpp_csp.js; '' = ok. */
function check_handler($src, &$names)
{
	global $calls, $builtin;
	$src = preg_replace('~' . PH_OPEN . '\d+\n*' . PH_CLOSE . '~', '0', $src);
	$t = array();
	$p = 0;
	$n = strlen($src);
	while ($p < $n) {
		$c = $src[$p];
		if (ctype_space($c)) {
			$p++;
			continue;
		}
		if ($c === "'" || $c === '"') {
			$q = $c;
			$p++;
			while ($p < $n && $src[$p] !== $q) {
				$p += ($src[$p] === '\\') ? 2 : 1;
			}
			if ($p >= $n) {
				return 'Zeichenkette nicht beendet';
			}
			$p++;
			$t[] = array('str', '');
			continue;
		}
		if (preg_match('~\G-?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?~', $src, $m, 0, $p) && ($c === '-' || $c === '.' || ctype_digit($c))) {
			$t[] = array('num', $m[0]);
			$p += strlen($m[0]);
			continue;
		}
		if (preg_match('~\G[A-Za-z_\$][\w\$]*(?:\.[A-Za-z_\$][\w\$]*)*~', $src, $m, 0, $p)) {
			$t[] = array('id', $m[0]);
			$p += strlen($m[0]);
			continue;
		}
		if (strpos('(),;:', $c) !== false) {
			$t[] = array('p', $c);
			$p++;
			continue;
		}
		return 'Zeichen "' . $c . '"';
	}
	$i = 0;
	$is = function ($k, $v = null) use (&$t, &$i) {
		return isset($t[$i]) && $t[$i][0] === $k && ($v === null || $t[$i][1] === $v);
	};
	while ($i < count($t)) {
		if ($is('p', ';')) {
			$i++;
			continue;
		}
		if ($is('id', 'javascript') && isset($t[$i + 1]) && $t[$i + 1] === array('p', ':')) {
			$i += 2;
		}
		$ret = false;
		if ($is('id', 'return')) {
			$ret = true;
			$i++;
		}
		if ($ret && ($is('id', 'false') || $is('id', 'true'))) {
			$i++;
		} elseif ($ret && ($is('p', ';') || $i >= count($t))) {
			// return;
		} else {
			if (! $is('id')) {
				return 'Funktionsaufruf erwartet';
			}
			$name = $t[$i++][1];
			if (! isset($calls[$name]) && ! isset($builtin[$name])) {
				$names[$name] = true;
				return 'Funktion ' . $name . ' nicht in CALLS';
			}
			$names[$name] = true;
			if (! $is('p', '(')) {
				return '( erwartet';
			}
			$i++;
			$first = true;
			while (! $is('p', ')')) {
				if (! $first) {
					if (! $is('p', ',')) {
						return ', erwartet';
					}
					$i++;
				}
				$first = false;
				if (! isset($t[$i])) {
					return 'Argument fehlt';
				}
				list($k, $v) = $t[$i++];
				if ($k === 'id' && ! in_array($v, array('true', 'false', 'null', 'undefined', 'this', 'this.value', 'this.checked', 'this.id', 'this.name', 'event'), true) && ! preg_match('~^[A-Za-z_\$][\w\$]*$~', $v)) {
					return 'Argument ' . $v;
				}
				if ($k === 'p') {
					return 'Argument erwartet';
				}
			}
			$i++;
		}
		if ($i < count($t)) {
			if (! $is('p', ';')) {
				return '; erwartet';
			}
			$i++;
		}
	}
	return '';
}

/** Handler attributes and javascript:void(0) in HTML or in JS/PHP strings that contain HTML. */
function convert_attrs($s, $file, $base_line, $context)
{
	$ev = EVENTS;
	// unquoted handler attributes and other on* events: manual
	if (preg_match_all('~(?<=[\s"\'\\\\/])on(' . $ev . ')\s*=\s*(?![\s\\\\"\'])~i', $s, $m, PREG_OFFSET_CAPTURE)) {
		foreach ($m[0] as $x) {
			note('manual', $file, $base_line + substr_count(substr($s, 0, $x[1]), "\n"), "Handler ohne Anfuehrungszeichen ($context): " . substr($s, $x[1], 80));
		}
	}
	if (preg_match_all('~(?<=[\s"\'\\\\/])on(?!(?:' . $ev . ')\b)[a-z]+\s*=\s*\\\\?["\']~i', $s, $m, PREG_OFFSET_CAPTURE)) {
		foreach ($m[0] as $x) {
			note('manual', $file, $base_line + substr_count(substr($s, 0, $x[1]), "\n"), "anderes Ereignis ($context): " . substr($s, $x[1], 80));
		}
	}
	$s = preg_replace_callback('~(?<=[\s"\'\\\\/])on(' . $ev . ')(\s*=\s*)(\\\\?)(["\'])~i', function ($m) use ($s, $file, $base_line, $context) {
		return 'data-astpp-' . strtolower($m[1]) . $m[2] . $m[3] . $m[4];
	}, $s, -1, $count);
	if ($count) {
		note('change', $file, $base_line, "$count Handler-Attribut(e) ($context)");
	}
	$s = preg_replace('~href=(\\\\?["\'])javascript:\s*void\s*\(\s*0\s*\)\s*;?\1~i', 'href=$1#$1', $s, -1, $count);
	if ($count) {
		note('change', $file, $base_line, "$count javascript:void(0) -> # ($context)");
	}
	if (preg_match_all('~javascript\s*:~i', $s, $m, PREG_OFFSET_CAPTURE)) {
		foreach ($m[0] as $x) {
			$from = max(0, $x[1] - 40);
			$before = substr($s, $from, $x[1] - $from);
			if (! preg_match('~data-astpp-[a-z]+\s*=\s*\\\\?["\']\s*(return\s+)?$~i', $before)) {
				note('manual', $file, $base_line + substr_count(substr($s, 0, $x[1]), "\n"), "javascript:-URL ($context): " . substr($s, max(0, $x[1] - 20), 90));
			}
		}
	}
	return $s;
}

/** Every complete data-astpp-* value in a text: grammar check. */
function check_values($s, $file, $base_line, $context)
{
	global $report;
	if (! preg_match_all('~data-astpp-(' . EVENTS . ')\s*=\s*(\\\\?)(["\'])(.*?)\2\3~is', $s, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
		return;
	}
	foreach ($m as $x) {
		$val = $x[4][0];
		$line = $base_line + substr_count(substr($s, 0, $x[0][1]), "\n");
		$names = array();
		if ($x[2][0] === '\\') {
			$val = stripslashes($val);
		}
		$err = check_handler(html_entity_decode($val, ENT_QUOTES), $names);
		$shown = preg_replace('~' . PH_OPEN . '\d+\n*' . PH_CLOSE . '~', '<?php ?>', $val);
		$report['handlers'][] = sprintf("%s:%d\t%s\t%s\t%s", $file, $line, $x[1][0], $err === '' ? 'ok' : 'FEHLER ' . $err, $shown);
	}
}

$changed = 0;
foreach ($files as $rel) {
	$path = $root . '/' . $rel;
	$orig = file_get_contents($path);
	if (substr($rel, -3) === '.js') {
		$new = convert_attrs($orig, $rel, 1, 'JS');
		check_values($new, $rel, 1, 'JS');
	} else {
		// PHP: HTML parts with placeholders for the PHP blocks (same number of line breaks)
		$tokens = token_get_all($orig);
		$masked = '';
		$blocks = array();
		$cur = null;
		$line = 1;
		$string_lines = array();
		foreach ($tokens as $k => $t) {
			$text = is_array($t) ? $t[1] : $t;
			if (is_array($t) && $t[0] === T_INLINE_HTML) {
				if ($cur !== null) {
					$masked .= PH_OPEN . count($blocks) . str_repeat("\n", substr_count($cur, "\n")) . PH_CLOSE;
					$blocks[] = $cur;
					$cur = null;
				}
				$masked .= $text;
				$line += substr_count($text, "\n");
				continue;
			}
			// PHP strings: handlers, javascript:void(0), field array keys
			if (is_array($t) && in_array($t[0], array(T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE), true)) {
				$nt = $text;
				if ($t[0] === T_CONSTANT_ENCAPSED_STRING && preg_match('~^([\'"])on(' . EVENTS . ')\1$~i', $text, $km)) {
					for ($j = $k + 1; isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE; $j++);
					if (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_DOUBLE_ARROW) {
						$nt = $km[1] . 'data-astpp-' . strtolower($km[2]) . $km[1];
						note('change', $rel, $t[2], "Formularfeld $text => $nt");
						// value of the field: check when it is a plain string
						for ($j++; isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE; $j++);
						$names = array();
						if (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING) {
							for ($e = $j + 1; isset($tokens[$e]) && is_array($tokens[$e]) && $tokens[$e][0] === T_WHITESPACE; $e++);
							$dyn = isset($tokens[$e]) && $tokens[$e] === '.';
							$v = substr($tokens[$j][1], 1, -1);
							$err = $dyn ? 'dynamisch (PHP), bitte ansehen' : check_handler(stripcslashes($v), $names);
							$report['handlers'][] = sprintf("%s:%d\t%s\t%s\t%s", $rel, $t[2], strtolower($km[2]), $err === '' ? 'ok' : ($dyn ? 'PRUEFEN ' : 'FEHLER ') . $err, $v);
						}
					}
				} elseif (preg_match('~on(' . EVENTS . ')\s*=|javascript\s*:|<script~i', $text)) {
					$nt = convert_attrs($text, $rel, $t[2], 'PHP-String');
					check_values($nt, $rel, $t[2], 'PHP-String');
					$inner = $t[0] === T_CONSTANT_ENCAPSED_STRING ? substr($nt, 1, -1) : $nt;
					if (preg_match('~data-astpp-(' . EVENTS . ')\s*=\s*\\\\?["\'][^"\']*$~i', $inner)) {
						$report['handlers'][] = sprintf("%s:%d\t-\tPRUEFEN dynamisch (PHP)\t%s", $rel, $t[2], substr($nt, 0, 160));
					}
					if (stripos($text, '<script') !== false) {
						note('manual', $rel, $t[2], 'script-Block in PHP-String (Nonce von Hand): ' . substr(trim($text), 0, 80));
					}
				}
				$text = $nt;
			}
			$cur = ($cur === null ? '' : $cur) . $text;
		}
		if ($cur !== null) {
			$masked .= PH_OPEN . count($blocks) . str_repeat("\n", substr_count($cur, "\n")) . PH_CLOSE;
			$blocks[] = $cur;
		}
		// HTML parts: script tags (nonce), their content as JS, the rest as HTML
		$out = '';
		$pos = 0;
		while (preg_match('~<script\b([^>]*)>~i', $masked, $m, PREG_OFFSET_CAPTURE, $pos)) {
			$start = $m[0][1];
			$html = substr($masked, $pos, $start - $pos);
			$out .= convert_attrs($html, $rel, 1 + substr_count(substr($masked, 0, $pos), "\n"), 'HTML');
			$tag = $m[0][0];
			$attrs = $m[1][0];
			$tline = 1 + substr_count(substr($masked, 0, $start), "\n");
			$plain = preg_replace_callback('~' . PH_OPEN . '(\d+)\n*' . PH_CLOSE . '~', function ($p) use ($blocks) {
				return $blocks[(int) $p[1]];
			}, $attrs);
			if (stripos($plain, 'nonce') === false && ! preg_match('~type\s*=\s*["\']?text/(template|html|x-template)~i', $plain)) {
				$tag = '<script' . rtrim($attrs) . '<?php echo csp_nonce_attr(); ?>>';
				note('change', $rel, $tline, 'Nonce: ' . preg_replace('~' . PH_OPEN . '\d+\n*' . PH_CLOSE . '~', '<?php ?>', $m[0][0]));
			}
			$out .= $tag;
			$cstart = $start + strlen($m[0][0]);
			$end = stripos($masked, '</script', $cstart);
			if ($end === false) {
				$end = strlen($masked);
			}
			$out .= convert_attrs(substr($masked, $cstart, $end - $cstart), $rel, 1 + substr_count(substr($masked, 0, $cstart), "\n"), 'JS in View');
			$pos = $end;
		}
		$out .= convert_attrs(substr($masked, $pos), $rel, 1 + substr_count(substr($masked, 0, $pos), "\n"), 'HTML');
		check_values($out, $rel, 1, 'View');
		// restore the PHP blocks
		$new = preg_replace_callback('~' . PH_OPEN . '(\d+)\n*' . PH_CLOSE . '~', function ($m) use ($blocks) {
			return $blocks[(int) $m[1]];
		}, $out);
	}
	if ($new !== $orig) {
		$changed++;
		if ($write) {
			file_put_contents($path, $new);
		}
		if (substr($rel, -4) === '.php') {
			$tmp = tempnam(sys_get_temp_dir(), 'cspc');
			file_put_contents($tmp, $new);
			$o = array();
			exec(escapeshellarg(PHP_BINARY) . ' -d short_open_tag=1 -l ' . escapeshellarg($tmp) . ' 2>&1', $o, $rc);
			unlink($tmp);
			if ($rc !== 0) {
				note('manual', $rel, 0, 'SYNTAXFEHLER nach der Umwandlung: ' . implode(' ', $o));
			}
		}
	}
}

echo "# csp-convert " . ($write ? '(geschrieben)' : '(Probelauf)') . ": $changed von " . count($files) . " Dateien geaendert\n\n";
echo "## Von Hand ansehen (" . count($report['manual']) . ")\n";
foreach ($report['manual'] as $l) {
	echo "  $l\n";
}
$bad = array_filter($report['handlers'], function ($l) { return strpos($l, "\tok\t") === false; });
echo "\n## Handler, die nicht geprueft werden konnten oder falsch sind (" . count($bad) . " von " . count($report['handlers']) . ")\n";
foreach ($bad as $l) {
	echo "  $l\n";
}
echo "\n## Aenderungen (" . count($report['change']) . ")\n";
foreach ($report['change'] as $l) {
	echo "  $l\n";
}
echo "\n## Alle Handler\n";
foreach ($report['handlers'] as $l) {
	echo "  $l\n";
}

<?php
/*
 * Rewrites calls of internal functions that throw TypeError/ValueError in PHP 8 to
 * the PHP 7 compatible astpp_* wrappers (application/php7compat.php).
 * Only real global function calls are touched (not ->method(), ::static(),
 * definitions, strings). date()/gmdate() only when a timestamp argument is passed.
 * Byte exact otherwise, round trip verified per file.
 *
 *   php8.5 rewrite_calls.php [--dry-run] files...
 */
$names = array('count', 'sizeof', 'in_array', 'array_search', 'array_key_exists', 'implode', 'join', 'array_merge',
    'array_keys', 'array_values', 'array_unique', 'array_slice', 'array_filter', 'array_diff', 'array_combine',
    'array_map', 'max', 'min', 'end', 'reset', 'current', 'key', 'number_format', 'round', 'floor', 'ceil', 'abs',
    'str_repeat', 'date', 'gmdate');
$dry = false; $files = array();
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--dry-run') $dry = true;
    elseif (strpos($a, '--names=') === 0) $names = explode(',', substr($a, 8)); // e.g. --names=chr
    else $files[] = $a;
}
$names = array_flip($names);
function ts($t) { return is_array($t) ? $t[1] : $t; }
function ws($t) { return is_array($t) && in_array($t[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true); }
$total = 0; $byName = array();
foreach ($files as $file) {
    if (strpos($file, 'php7compat') !== false) continue;
    $src = file_get_contents($file);
    $t = token_get_all($src);
    $n = count($t);
    $rt = ''; foreach ($t as $x) $rt .= ts($x);
    if ($rt !== $src) { fwrite(STDERR, "SKIP (round trip) $file\n"); continue; }
    $changed = 0;
    for ($i = 0; $i < $n; $i++) {
        if (! is_array($t[$i]) || $t[$i][0] !== T_STRING) continue;
        $lname = strtolower($t[$i][1]);
        if (! isset($names[$lname])) continue;
        $q = $i + 1; while ($q < $n && ws($t[$q])) $q++;
        if ($q >= $n || $t[$q] !== '(') continue;
        $p = $i - 1; while ($p >= 0 && ws($t[$p])) $p--;
        if ($p >= 0) {
            $pt = $t[$p];
            if (is_array($pt) && in_array($pt[0], array(T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_NS_SEPARATOR, T_CONST), true)) continue;
            if (defined('T_NULLSAFE_OBJECT_OPERATOR') && is_array($pt) && $pt[0] === T_NULLSAFE_OBJECT_OPERATOR) continue;
            if ($pt === '&') { // function &name(
                $pp = $p - 1; while ($pp >= 0 && ws($t[$pp])) $pp--;
                if ($pp >= 0 && is_array($t[$pp]) && $t[$pp][0] === T_FUNCTION) continue;
            }
        }
        if ($lname === 'date' || $lname === 'gmdate') {
            // count top level arguments
            $depth = 0; $args = 0; $any = false;
            for ($k = $q; $k < $n; $k++) {
                $s = ts($t[$k]);
                if ($s === '(' || $s === '[' || $s === '{') { $depth++; if ($depth === 1) continue; }
                if ($s === ')' || $s === ']' || $s === '}') { $depth--; if ($depth === 0) break; }
                if ($depth === 1 && $s === ',') $args++;
                if ($depth >= 1 && ! ws($t[$k])) $any = true;
            }
            if ($args < 1) continue; // single argument: unchanged behaviour
        }
        $t[$i][1] = 'astpp_' . $t[$i][1];
        $changed++;
        $byName[$lname] = ($byName[$lname] ?? 0) + 1;
    }
    if ($changed) {
        $out = ''; foreach ($t as $x) $out .= ts($x);
        $total += $changed;
        if (! $dry) file_put_contents($file, $out);
    }
}
arsort($byName);
foreach ($byName as $k => $v) echo str_pad($k, 18) . $v . "\n";
echo "Gesamt: $total Aufrufe\n";

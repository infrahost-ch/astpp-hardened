<?php
/*
 * Fixes PHP 8.5 deprecations (behaviour unchanged):
 *   curl_close() & co (no-op since 8.0)  -> "PHP_VERSION_ID < 80000 && curl_close(...)"
 *   (double)/(integer)/(boolean)/(binary)/(real) -> (float)/(int)/(bool)/(string)
 *   "case X;"                            -> "case X:"
 * Byte exact otherwise (token round trip).
 */
$noop = array('curl_close', 'curl_share_close', 'finfo_close', 'imagedestroy', 'xml_parser_free');
$casts = array('(integer)' => '(int)', '(double)' => '(float)', '(real)' => '(float)', '(boolean)' => '(bool)', '(binary)' => '(string)');
function ts($t) { return is_array($t) ? $t[1] : $t; }
function ws($t) { return is_array($t) && in_array($t[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true); }
$tot = array();
foreach (array_slice($argv, 1) as $f) {
    $src = file_get_contents($f);
    $t = token_get_all($src);
    $n = count($t);
    $rt = ''; foreach ($t as $x) $rt .= ts($x);
    if ($rt !== $src) { fwrite(STDERR, "SKIP $f\n"); continue; }
    $pre = array(); $rep = array(); $ch = 0;
    // matching parens (for "if (...) call();")
    $open = array(); $match = array();
    for ($i = 0; $i < $n; $i++) { $s = ts($t[$i]); if ($s === '(') $open[] = $i; elseif ($s === ')') { $o = array_pop($open); if ($o !== null) $match[$i] = $o; } }
    for ($i = 0; $i < $n; $i++) {
        $x = $t[$i];
        if (is_array($x) && in_array($x[0], array(T_INT_CAST, T_DOUBLE_CAST, T_BOOL_CAST, T_STRING_CAST), true)) {
            $c = strtolower(preg_replace('/\s+/', '', $x[1]));
            if (isset($casts[$c])) { $rep[$i] = $casts[$c]; $ch++; @$tot['cast']++; }
        }
        if (is_array($x) && $x[0] === T_STRING && in_array(strtolower($x[1]), $noop, true)) {
            $q = $i + 1; while ($q < $n && ws($t[$q])) $q++;
            if ($q >= $n || $t[$q] !== '(') continue;
            $s0 = $i; $p = $i - 1; while ($p >= 0 && ws($t[$p])) $p--;
            if ($p >= 0 && $t[$p] === '@') { $s0 = $p; $p--; while ($p >= 0 && ws($t[$p])) $p--; }
            if ($p >= 0 && is_array($t[$p]) && in_array($t[$p][0], array(T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW), true)) continue;
            $start = $p < 0 || in_array(ts($t[$p]), array(';', '{', '}', ':'), true) || (is_array($t[$p]) && in_array($t[$p][0], array(T_OPEN_TAG, T_ELSE), true));
            if (! $start && ts($t[$p]) === ')' && isset($match[$p])) {
                $pp = $match[$p] - 1; while ($pp >= 0 && ws($t[$pp])) $pp--;
                $start = $pp >= 0 && is_array($t[$pp]) && in_array($t[$pp][0], array(T_IF, T_ELSEIF, T_WHILE, T_FOR, T_FOREACH), true);
            }
            if (! $start) { echo "MANUAL $f:{$x[2]} {$x[1]}()\n"; continue; }
            $pre[$s0] = 'PHP_VERSION_ID < 80000 && '; $ch++; @$tot['noop']++;
        }
        if (is_array($x) && ($x[0] === T_CASE || $x[0] === T_DEFAULT)) {
            $d = 0; $tern = 0;
            for ($k = $i + 1; $k < $n; $k++) {
                $s = ts($t[$k]);
                if ($s === '(' || $s === '[' || $s === '{') $d++;
                elseif ($s === ')' || $s === ']' || $s === '}') { $d--; if ($d < 0) break; }
                elseif ($d === 0 && $s === '?') $tern++;
                elseif ($d === 0 && $s === ':') { if ($tern) { $tern--; continue; } break; }
                elseif ($d === 0 && $s === ';') { $rep[$k] = ':'; $ch++; @$tot['case']++; break; }
            }
        }
    }
    if ($ch) {
        $out = ''; foreach ($t as $k => $x) $out .= (isset($pre[$k]) ? $pre[$k] : '') . (isset($rep[$k]) ? $rep[$k] : ts($x));
        file_put_contents($f, $out);
        echo "$f: $ch\n";
    }
}
print_r($tot);

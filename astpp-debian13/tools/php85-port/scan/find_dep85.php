<?php
/*
 * PHP 8.5 deprecations that can be found statically:
 *   backtick operator, non-canonical casts ((integer) (double) (boolean) (binary)),
 *   "case X;" , no-op functions curl_close/curl_share_close/finfo_close/imagedestroy/xml_parser_free,
 *   $http_response_header
 */
$noop = array('curl_close', 'curl_share_close', 'finfo_close', 'imagedestroy', 'xml_parser_free');
function ws($t) { return is_array($t) && in_array($t[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true); }
foreach (array_slice($argv, 1) as $f) {
    $t = token_get_all(file_get_contents($f));
    $n = count($t);
    $depth = array(); // stack of '?' counts per bracket level for case detection
    for ($i = 0; $i < $n; $i++) {
        $x = $t[$i];
        if ($x === '`') { echo "$f\tbacktick\n"; $i++; while ($i < $n && $t[$i] !== '`') $i++; continue; }
        if (is_array($x) && in_array($x[0], array(T_INT_CAST, T_DOUBLE_CAST, T_BOOL_CAST, T_STRING_CAST), true)) {
            $c = strtolower(preg_replace('/\s+/', '', $x[1]));
            if (in_array($c, array('(integer)', '(double)', '(boolean)', '(binary)', '(real)'), true)) echo "$f:{$x[2]}\tcast $c\n";
        }
        if (is_array($x) && $x[0] === T_VARIABLE && $x[1] === '$http_response_header') echo "$f:{$x[2]}\thttp_response_header\n";
        if (is_array($x) && $x[0] === T_STRING && in_array(strtolower($x[1]), $noop, true)) {
            $q = $i + 1; while ($q < $n && ws($t[$q])) $q++;
            $p = $i - 1; while ($p >= 0 && ws($t[$p])) $p--;
            if ($q < $n && $t[$q] === '(' && ! ($p >= 0 && is_array($t[$p]) && in_array($t[$p][0], array(T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION), true))) echo "$f:{$x[2]}\tnoop {$x[1]}()\n";
        }
        if (is_array($x) && ($x[0] === T_CASE || $x[0] === T_DEFAULT)) {
            // find the terminator of the case label at nesting level 0 (ternaries contain ':')
            $d = 0; $tern = 0;
            for ($k = $i + 1; $k < $n; $k++) {
                $s = is_array($t[$k]) ? $t[$k][1] : $t[$k];
                if ($s === '(' || $s === '[' || $s === '{') $d++;
                elseif ($s === ')' || $s === ']' || $s === '}') { $d--; if ($d < 0) break; }
                elseif ($d === 0 && $s === '?') $tern++;
                elseif ($d === 0 && $s === ':') { if ($tern) { $tern--; continue; } break; }
                elseif ($d === 0 && $s === ';') { echo "$f:{$x[2]}\tcase-semicolon\n"; break; }
                if (is_array($t[$k]) && $t[$k][0] === T_DOUBLE_COLON) continue;
            }
        }
    }
}

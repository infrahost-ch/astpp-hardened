<?php
/*
 * PHP 8: reading $string['non-numeric'] throws TypeError (PHP 7.4: warning + first character).
 * Heuristic search: inside each function body, a variable assigned from a call that
 * returns a string (get_field_name & co, string functions) and later read with a
 * non-numeric literal offset.
 *
 *   php8.5 find_stroffset.php files...
 */
$strfuncs = array_flip(array('get_field_name', 'get_field_name_coma', 'get_field_name_coma_new', 'get_field_name_balance',
    'get_field_name_country_camel', 'encode', 'decode', 'decode_params', 'encode_params', 'trim', 'rtrim', 'ltrim', 'substr',
    'str_replace', 'sprintf', 'implode', 'join', 'gettext', 'json_encode', 'date', 'gmdate', 'number_format', 'strtolower',
    'strtoupper', 'ucfirst', 'ucwords', 'htmlspecialchars', 'md5', 'sha1', 'base64_encode', 'base64_decode', 'urlencode',
    'urldecode', 'str_pad', 'preg_replace', 'convert_to_currency', 'currency_decimal', 'calculate_currency',
    'calculate_currency_customer', 'get_timezone_offset', 'convert_GMT_to', 'convert_to_gmt', 'astpp_date', 'file_get_contents',
    'get_currency_name', 'get_account_name', 'get_country', 'build_concat_string', 'get_admin_info', 'astpp_number_format',
    'astpp_implode', 'astpp_date', 'astpp_gmdate', 'serialize', 'strip_tags', 'nl2br', 'addslashes', 'stripslashes',
    'get_symbol', 'get_currency_symbol', 'escape_str', 'escape'));
function ts($t) { return is_array($t) ? $t[1] : $t; }
function ws($t) { return is_array($t) && in_array($t[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true); }
foreach (array_slice($argv, 1) as $file) {
    $t = array_values(array_filter(token_get_all(file_get_contents($file)), function ($x) { return ! ws($x); }));
    $n = count($t);
    $strvars = array(); // var => line of string assignment (reset at each function)
    $depth = 0; $fdepth = -1;
    for ($i = 0; $i < $n; $i++) {
        $s = ts($t[$i]);
        if (is_array($t[$i]) && $t[$i][0] === T_FUNCTION) { $strvars = array(); }
        if ($s === '{' || (is_array($t[$i]) && in_array($t[$i][0], array(T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES), true))) $depth++;
        if ($s === '}') $depth--;
        if (! is_array($t[$i]) || $t[$i][0] !== T_VARIABLE) continue;
        $v = $t[$i][1];
        // assignment $v = ...;
        if (isset($t[$i + 1]) && $t[$i + 1] === '=' && ! (isset($t[$i - 1]) && in_array(ts($t[$i - 1]), array('->', '::'), true))) {
            // look at the RHS: first called function name at top level
            $j = $i + 2; $name = null; $isArrayLit = false;
            if (isset($t[$j]) && (ts($t[$j]) === 'array' || $t[$j] === '[')) $isArrayLit = true;
            for ($k = $j, $d = 0; $k < $n; $k++) {
                $x = ts($t[$k]);
                if ($x === '(' || $x === '[') $d++;
                if ($x === ')' || $x === ']') $d--;
                if ($d < 0 || ($d === 0 && ($x === ';' || $x === ','))) break;
                if ($name === null && is_array($t[$k]) && $t[$k][0] === T_STRING && isset($t[$k + 1]) && $t[$k + 1] === '(') { $name = strtolower($t[$k][1]); }
                if ($x === '?' || $x === '??') { $name = '#mixed'; }
            }
            if (! $isArrayLit && $name !== null && isset($strfuncs[$name])) $strvars[$v] = array($t[$i][2], $name);
            else unset($strvars[$v]);
            continue;
        }
        // $v['literal'] read
        if (isset($strvars[$v]) && isset($t[$i + 1]) && $t[$i + 1] === '[' && isset($t[$i + 2]) && is_array($t[$i + 2])
            && $t[$i + 2][0] === T_CONSTANT_ENCAPSED_STRING && ! is_numeric(trim($t[$i + 2][1], '\'"'))) {
            // writes like $v['x'] = are not reads but also break - report both
            echo "$file:{$t[$i][2]}\t$v{$t[$i + 2][1]}\t(assigned line {$strvars[$v][0]} from {$strvars[$v][1]}())\n";
        }
    }
}

<?php
/*
 * Triage of loose comparisons against 0 (PHP 8: '' == 0 and 'abc' == 0 became false).
 * Uses the tokenizer to find "==", "!=", "<>" with an integer/float 0 operand and
 * extracts the other operand. Classifies:
 *   SAFE  - operand is numeric by construction (count(), num_rows, (int), loop counter, ...)
 *   DB    - array element / property of a database row (numeric string or NULL in PHP 7 and 8)
 *   RISK  - request data, function results, session values, foreach values ... may be '' / text
 * Output: CLASS<TAB>file:line<TAB>operand<TAB>source line
 */
$files = array_slice($argv, 1);
$safeRe = '~^(\(int\)|\(float\)|\(bool\)|intval\(|floatval\(|count\(|sizeof\(|strlen\(|mb_strlen\(|round\(|floor\(|ceil\(|abs\(|array_sum\(|\$i$|\$j$|\$k$|\$cnt$|\$count$|\$counter$|\$total$|\$n$|.*->num_rows\(?\)?$|.*num_rows.*|strpos\(|stripos\(|strcmp\(|strcasecmp\(|strncmp\(|array_search\(|preg_match\(|filesize\(|time\(|bccomp\(|.*\s[\+\-\*/%]\s.*)~';
$riskRe = '~(post|_POST|_GET|_REQUEST|input->|add_array|edit_data|\$post\b|\$data\b|userdata|session|get_field_name|get_field|select_value|explode|trim\(|substr\(|config\[|global_config|\$value\b|\$val\b|\$row\b|\$result\b|\$res\b|\$info\b|\$params|\$param\b|getHeader|json_decode|\$_)~';
foreach ($files as $file) {
    $tokens = token_get_all(file_get_contents($file));
    $n = count($tokens);
    $lines = file($file);
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (! is_array($t) || ! in_array($t[0], array(T_IS_EQUAL, T_IS_NOT_EQUAL), true)) continue;
        // find previous / next significant token
        $p = $i - 1; while ($p >= 0 && is_array($tokens[$p]) && $tokens[$p][0] === T_WHITESPACE) $p--;
        $q = $i + 1; while ($q < $n && is_array($tokens[$q]) && $tokens[$q][0] === T_WHITESPACE) $q++;
        $isZero = function ($tk) { return is_array($tk) && in_array($tk[0], array(T_LNUMBER, T_DNUMBER), true) && (float) $tk[1] == 0.0; };
        if ($isZero($tokens[$q])) { $dir = -1; $start = $p; }
        elseif ($p >= 0 && $isZero($tokens[$p])) { $dir = 1; $start = $q; }
        else continue;
        // collect the other operand: walk until an operator of lower precedence / delimiter at depth 0
        $op = '';
        $depth = 0;
        for ($k = $start; $k >= 0 && $k < $n; $k += $dir) {
            $tk = $tokens[$k];
            $s = is_array($tk) ? $tk[1] : $tk;
            if ($dir < 0) {
                if ($s === ')' || $s === ']') $depth++;
                if ($s === '(' || $s === '[') { if ($depth === 0) break; $depth--; }
            } else {
                if ($s === '(' || $s === '[') $depth++;
                if ($s === ')' || $s === ']') { if ($depth === 0) break; $depth--; }
            }
            if ($depth === 0 && (in_array($s, array(';', ',', '?', ':', '{', '}'), true)
                || (is_array($tk) && in_array($tk[0], array(T_BOOLEAN_AND, T_BOOLEAN_OR, T_LOGICAL_AND, T_LOGICAL_OR, T_RETURN, T_ECHO, T_IF, T_ELSEIF, T_WHILE, T_DOUBLE_ARROW, T_IS_EQUAL, T_IS_NOT_EQUAL, T_IS_IDENTICAL, T_IS_NOT_IDENTICAL), true)) || $s === '=')) break;
            $op = $dir < 0 ? $s . $op : $op . $s;
        }
        $op = trim(preg_replace('~\s+~', ' ', $op));
        $line = $t[2];
        if (preg_match($safeRe, $op)) $class = 'SAFE';
        elseif (preg_match($riskRe, $op)) $class = 'RISK';
        elseif (preg_match('~^\$\w+(\[[\'"][\w]+[\'"]\]|->\w+)+$~', $op)) $class = 'DB';
        else $class = 'RISK';
        echo $class . "\t" . $file . ':' . $line . "\t" . $op . "\t" . trim($lines[$line - 1]) . "\n";
    }
}

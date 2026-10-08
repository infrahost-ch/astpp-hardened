<?php
// Count real calls (tokenizer) of internal functions that throw TypeError/ValueError
// in PHP 8 for null / non-numeric-string / non-array arguments.
$names = array('count', 'sizeof', 'in_array', 'array_key_exists', 'array_search', 'array_merge', 'implode', 'join',
    'array_keys', 'array_values', 'array_map', 'array_filter', 'array_diff', 'array_intersect', 'array_flip',
    'array_unique', 'array_slice', 'array_combine', 'max', 'min', 'number_format', 'round', 'floor', 'ceil', 'abs',
    'date', 'gmdate', 'str_repeat', 'array_sum', 'array_fill', 'range', 'sort', 'ksort', 'asort', 'arsort', 'usort',
    'array_column', 'array_reverse', 'array_push', 'array_shift', 'end', 'reset', 'current', 'key', 'strlen', 'trim',
    'explode', 'strtolower', 'strtoupper', 'str_replace', 'substr', 'htmlspecialchars', 'json_decode', 'base64_decode', 'urlencode');
$cnt = array_fill_keys($names, 0);
foreach (array_slice($argv, 1) as $f) {
    $t = token_get_all(file_get_contents($f));
    $n = count($t);
    for ($i = 0; $i < $n; $i++) {
        if (! is_array($t[$i]) || $t[$i][0] !== T_STRING) continue;
        $name = strtolower($t[$i][1]);
        if (! isset($cnt[$name])) continue;
        $p = $i - 1; while ($p >= 0 && is_array($t[$p]) && $t[$p][0] === T_WHITESPACE) $p--;
        $q = $i + 1; while ($q < $n && is_array($t[$q]) && $t[$q][0] === T_WHITESPACE) $q++;
        if ($q >= $n || $t[$q] !== '(') continue;
        if ($p >= 0 && is_array($t[$p]) && in_array($t[$p][0], array(T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_NS_SEPARATOR), true)) continue;
        $cnt[$name]++;
    }
}
arsort($cnt);
foreach ($cnt as $k => $v) if ($v) printf("%-18s %5d\n", $k, $v);

<?php
/*
 * PHP 8: using an undefined constant is an Error (PHP 7: warning, used as string).
 * Finds identifiers used as constants that are neither PHP constants nor defined by
 * the application (define()/const).
 */
$files = array_slice($argv, 1);
$defined = array_change_key_case(get_defined_constants(), CASE_LOWER);
$app = array();
foreach ($files as $f) {
    if (preg_match_all('~\bdefine\s*\(\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]~', file_get_contents($f), $m)) foreach ($m[1] as $c) $app[strtolower($c)] = 1;
    if (preg_match_all('~\bconst\s+([A-Za-z_][A-Za-z0-9_]*)\s*=~', file_get_contents($f), $m)) foreach ($m[1] as $c) $app[strtolower($c)] = 1;
}
$skipPrev = array(T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW, T_FUNCTION, T_CLASS, T_INTERFACE, T_TRAIT, T_EXTENDS,
    T_IMPLEMENTS, T_INSTANCEOF, T_CONST, T_USE, T_NAMESPACE, T_GOTO, T_NS_SEPARATOR, T_INSTEADOF, T_AS);
foreach ($files as $f) {
    $t = token_get_all(file_get_contents($f));
    $n = count($t);
    for ($i = 0; $i < $n; $i++) {
        if (! is_array($t[$i]) || $t[$i][0] !== T_STRING) continue;
        $name = $t[$i][1];
        $l = strtolower($name);
        if (isset($defined[$l]) || isset($app[$l]) || in_array($l, array('true', 'false', 'null', 'self', 'parent', 'static', 'array', 'list', 'callable', 'parent'), true)) continue;
        $q = $i + 1; while ($q < $n && is_array($t[$q]) && in_array($t[$q][0], array(T_WHITESPACE, T_COMMENT), true)) $q++;
        $p = $i - 1; while ($p >= 0 && is_array($t[$p]) && in_array($t[$p][0], array(T_WHITESPACE, T_COMMENT), true)) $p--;
        if ($q < $n && ($t[$q] === '(' || (is_array($t[$q]) && in_array($t[$q][0], array(T_DOUBLE_COLON, T_VARIABLE, T_ELLIPSIS, T_NS_SEPARATOR), true)))) continue;
        if ($p >= 0 && is_array($t[$p]) && in_array($t[$p][0], $skipPrev, true)) continue;
        if ($q < $n && $t[$q] === ':' && $p >= 0 && in_array($t[$p], array(';', '{', '}'), true)) continue; // goto label
        if ($p >= 0 && ($t[$p] === '&' || $t[$p] === '?') && $q < $n && is_array($t[$q]) && $t[$q][0] === T_VARIABLE) continue; // types
        if ($p >= 0 && in_array($t[$p], array('(', ',', '|'), true) && $q < $n && is_array($t[$q]) && in_array($t[$q][0], array(T_VARIABLE, T_ELLIPSIS), true)) continue; // param type
        if ($p >= 0 && $t[$p] === ':' && $q < $n && in_array($t[$q], array('{', ';'), true)) continue; // return type
        if ($p >= 0 && is_array($t[$p]) && $t[$p][0] === T_CATCH) continue;
        if ($p >= 0 && $t[$p] === '(' ) { // catch (Exception $e)
            $pp = $p - 1; while ($pp >= 0 && is_array($t[$pp]) && $t[$pp][0] === T_WHITESPACE) $pp--;
            if ($pp >= 0 && is_array($t[$pp]) && $t[$pp][0] === T_CATCH) continue;
        }
        echo "$f:{$t[$i][2]}\t$name\n";
    }
}

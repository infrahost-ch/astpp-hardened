<?php
/*
 * PHP 8.0+: "Optional parameter $a declared before required parameter $b is
 * implicitly treated as a required parameter" (deprecated).
 * The default of such a parameter is never used, so removing it does not change
 * behaviour. Rewrites files token by token (byte exact for everything else).
 * A typed "Type $x = null" becomes "?Type $x" (keeps it nullable).
 *
 *   php8.5 fix_optional_params.php [--dry-run] file.php ...
 */
$dry = false;
$files = array();
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--dry-run') { $dry = true; continue; }
    $files[] = $a;
}
$total = 0;
foreach ($files as $file) {
    $src = file_get_contents($file);
    $tokens = token_get_all($src);
    $out = '';
    $changed = 0;
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (is_array($t) && $t[0] === T_FUNCTION) {
            // copy tokens up to and including the opening parenthesis of the parameter list
            $j = $i;
            $head = '';
            while ($j < $n && $tokens[$j] !== '(') {
                $head .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                $j++;
            }
            if ($j >= $n) { $out .= is_array($t) ? $t[1] : $t; continue; }
            // collect parameter tokens until the matching ')'
            $depth = 0;
            $k = $j;
            $params = array(array());
            for (; $k < $n; $k++) {
                $tk = $tokens[$k];
                if ($tk === '(' || $tk === '[' || $tk === '{') {
                    $depth++;
                    if ($depth === 1 && $tk === '(') continue;
                } elseif ($tk === ')' || $tk === ']' || $tk === '}') {
                    $depth--;
                    if ($depth === 0) break;
                }
                if ($depth === 1 && $tk === ',') { $params[] = array(); continue; }
                $params[count($params) - 1][] = $tk;
            }
            // analyse parameters
            $info = array();
            foreach ($params as $p) {
                $eq = null; $d = 0; $variadic = false; $hasVar = false;
                foreach ($p as $idx => $tk) {
                    if ($tk === '(' || $tk === '[') $d++;
                    if ($tk === ')' || $tk === ']') $d--;
                    if ($tk === '=' && $d === 0 && $eq === null) $eq = $idx;
                    if (is_array($tk) && $tk[0] === T_ELLIPSIS) $variadic = true;
                    if (is_array($tk) && $tk[0] === T_VARIABLE) $hasVar = true;
                }
                $info[] = array('eq' => $eq, 'variadic' => $variadic, 'var' => $hasVar);
            }
            $lastRequired = -1;
            foreach ($info as $idx => $pi) {
                if ($pi['var'] && $pi['eq'] === null && ! $pi['variadic']) $lastRequired = $idx;
            }
            $newParams = array();
            foreach ($params as $idx => $p) {
                $txt = '';
                if ($idx < $lastRequired && $info[$idx]['eq'] !== null) {
                    // keep tokens before '=' (trim trailing whitespace), drop the default
                    $before = array_slice($p, 0, $info[$idx]['eq']);
                    $default = '';
                    foreach (array_slice($p, $info[$idx]['eq'] + 1) as $tk) $default .= is_array($tk) ? $tk[1] : $tk;
                    $btxt = '';
                    foreach ($before as $tk) $btxt .= is_array($tk) ? $tk[1] : $tk;
                    $btxt = rtrim($btxt);
                    // typed parameter with "= null": keep it nullable
                    if (strtolower(trim($default)) === 'null' && preg_match('/^(\s*)([A-Za-z_\\\\][\w\\\\]*)(\s+&?\$\w+)$/', $btxt, $m)
                        && ! in_array(strtolower($m[2]), array('mixed', 'null'), true)) {
                        $btxt = $m[1] . '?' . $m[2] . $m[3];
                    }
                    $txt = $btxt;
                    $changed++;
                } else {
                    foreach ($p as $tk) $txt .= is_array($tk) ? $tk[1] : $tk;
                }
                $newParams[] = $txt;
            }
            $out .= $head . '(' . implode(',', $newParams) . ')';
            $i = $k; // continue after ')'
            continue;
        }
        $out .= is_array($t) ? $t[1] : $t;
    }
    if ($changed) {
        $total += $changed;
        echo "$file: $changed Parameter\n";
        if (! $dry) file_put_contents($file, $out);
    } elseif ($out !== $src) {
        fwrite(STDERR, "WARNUNG: $file wuerde ohne Aenderung neu geschrieben - uebersprungen\n");
    }
}
echo "Gesamt: $total Parameter\n";

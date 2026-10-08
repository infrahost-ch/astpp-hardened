<?php
/*
 * Rewrites loose comparisons whose result changed in PHP 8 for non-numeric strings:
 *   X == 0 / X != 0 / X <> 0      ->  astpp_eq0(X) / (!astpp_eq0(X))
 *   X <  N / X <= N / X > N / X >= N   (N = 0 or a negative number)
 *                                  ->  astpp_lt/le/gt/ge(X, N)
 * (also with the literal on the left side, mirrored). Operands that are numeric by
 * construction (count(), (int), num_rows, arithmetic, ...) are left alone.
 * Byte exact for everything else; verifies the round trip of every file.
 *
 *   php8.5 rewrite_cmp0.php [--dry-run] [--report=file.tsv] files...
 */
$dry = false; $report = null; $files = array();
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--dry-run') $dry = true;
    elseif (strpos($a, '--report=') === 0) $report = substr($a, 9);
    else $files[] = $a;
}
$rep = $report ? fopen($report, 'w') : null;

const STOP_TYPES = array(T_BOOLEAN_AND, T_BOOLEAN_OR, T_LOGICAL_AND, T_LOGICAL_OR, T_LOGICAL_XOR,
    T_COALESCE, T_RETURN, T_ECHO, T_PRINT, T_IF, T_ELSEIF, T_WHILE, T_FOR, T_FOREACH, T_SWITCH, T_CASE,
    T_DOUBLE_ARROW, T_IS_EQUAL, T_IS_NOT_EQUAL, T_IS_IDENTICAL, T_IS_NOT_IDENTICAL, T_SPACESHIP,
    T_IS_SMALLER_OR_EQUAL, T_IS_GREATER_OR_EQUAL, T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL,
    T_CONCAT_EQUAL, T_MOD_EQUAL, T_AND_EQUAL, T_OR_EQUAL, T_XOR_EQUAL, T_SL_EQUAL, T_SR_EQUAL,
    T_POW_EQUAL, T_COALESCE_EQUAL, T_YIELD, T_INLINE_HTML, T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO, T_CLOSE_TAG,
    T_AS, T_ARRAY_CAST, T_INCLUDE, T_REQUIRE, T_INCLUDE_ONCE, T_REQUIRE_ONCE);
const STOP_CHARS = array(';', ',', '?', ':', '{', '}', '=', '&', '|', '^', '<', '>', '@');

$safeRe = '~^(\(int\)|\(integer\)|\(float\)|\(double\)|\(bool\)|\(boolean\)|intval\s*\(|floatval\s*\(|count\s*\(|sizeof\s*\(|strlen\s*\(|mb_strlen\s*\(|round\s*\(|floor\s*\(|ceil\s*\(|abs\s*\(|array_sum\s*\(|strpos\s*\(|stripos\s*\(|strcmp\s*\(|strcasecmp\s*\(|strncmp\s*\(|strncasecmp\s*\(|preg_match\s*\(|preg_match_all\s*\(|filesize\s*\(|time\s*\(|mktime\s*\(|strtotime\s*\(|bccomp\s*\(|ord\s*\(|array_search\s*\(|.*->num_rows\s*(\(\s*\))?$|\$(i|j|k|n|x|y|cnt|count|counter|loop|idx|index)$)~i';

function tok_str($t) { return is_array($t) ? $t[1] : $t; }
function is_ws($t) { return is_array($t) && in_array($t[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true); }

$grand = 0;
foreach ($files as $file) {
    $src = file_get_contents($file);
    $tokens = token_get_all($src);
    $n = count($tokens);
    $rebuilt = '';
    foreach ($tokens as $t) $rebuilt .= tok_str($t);
    if ($rebuilt !== $src) { fwrite(STDERR, "SKIP (round trip): $file\n"); continue; }

    $edits = array(); // [from, to, replacement]
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        $opType = null;
        if (is_array($t) && $t[0] === T_IS_EQUAL) $opType = 'eq';
        elseif (is_array($t) && $t[0] === T_IS_NOT_EQUAL) $opType = 'ne';
        elseif (is_array($t) && $t[0] === T_IS_SMALLER_OR_EQUAL) $opType = 'le';
        elseif (is_array($t) && $t[0] === T_IS_GREATER_OR_EQUAL) $opType = 'ge';
        elseif ($t === '<') $opType = 'lt';
        elseif ($t === '>') $opType = 'gt';
        if ($opType === null) continue;

        $p = $i - 1; while ($p >= 0 && is_ws($tokens[$p])) $p--;
        $q = $i + 1; while ($q < $n && is_ws($tokens[$q])) $q++;

        // literal on the right: 0, -N ; literal on the left: 0, -N
        $lit = null; $litFrom = $litTo = null; $side = null;
        $readLit = function ($k, $dir) use ($tokens, $n) {
            // returns [value, from, to] or null
            if ($dir > 0) {
                $neg = false; $s = $k;
                if ($tokens[$k] === '-') { $neg = true; $k++; while ($k < $n && is_ws($tokens[$k])) $k++; }
                if ($k < $n && is_array($tokens[$k]) && in_array($tokens[$k][0], array(T_LNUMBER, T_DNUMBER), true)) {
                    // must not be followed by an operator binding tighter than the comparison (e.g. "0 + 1")
                    $m = $k + 1; while ($m < $n && is_ws($tokens[$m])) $m++;
                    if ($m < $n && in_array(tok_str($tokens[$m]), array('+', '-', '*', '/', '%', '.', '**', '[', '('), true)) return null;
                    $v = (float) $tokens[$k][1];
                    return array($neg ? -$v : $v, $s, $k, $tokens[$k][1], $neg);
                }
                return null;
            } else {
                if ($k >= 0 && is_array($tokens[$k]) && in_array($tokens[$k][0], array(T_LNUMBER, T_DNUMBER), true)) {
                    $e = $k; $v = (float) $tokens[$k][1]; $neg = false;
                    $m = $k - 1; while ($m >= 0 && is_ws($tokens[$m])) $m--;
                    if ($m >= 0 && $tokens[$m] === '-') {
                        $m2 = $m - 1; while ($m2 >= 0 && is_ws($tokens[$m2])) $m2--;
                        $prev = $m2 >= 0 ? tok_str($tokens[$m2]) : '';
                        // unary minus only
                        if ($m2 < 0 || in_array($prev, array('(', ',', '=', '?', ':', '[', '!', '&&', '||', 'return'), true) || (is_array($tokens[$m2]) && in_array($tokens[$m2][0], STOP_TYPES, true))) { $neg = true; $k = $m; }
                    }
                    $m = $k - 1; while ($m >= 0 && is_ws($tokens[$m])) $m--;
                    if ($m >= 0 && in_array(tok_str($tokens[$m]), array('+', '-', '*', '/', '%', '.', '**', ']', ')', '$'), true) && ! ($neg && $k == $m + 1)) {
                        if (! $neg) return null;
                    }
                    return array($neg ? -$v : $v, $k, $e, $tokens[$e][1], $neg);
                }
                return null;
            }
        };
        $r = $readLit($q, 1);
        if ($r) { $side = 'right'; }
        else { $r = $readLit($p, -1); if ($r) $side = 'left'; }
        if (! $r) continue;
        list($value, $lf, $lt, $litText, $neg) = $r;
        if (in_array($opType, array('eq', 'ne'), true) && $value != 0.0) continue;
        if (in_array($opType, array('lt', 'le', 'gt', 'ge'), true) && $value > 0.0) continue;

        // collect the other operand
        $dir = $side === 'right' ? -1 : 1;
        $start = $side === 'right' ? $p : $q;
        $depth = 0; $a = $b = null;
        for ($k = $start; $k >= 0 && $k < $n; $k += $dir) {
            $tk = $tokens[$k]; $s = tok_str($tk);
            if ($dir < 0) {
                if ($s === ')' || $s === ']' || $s === '}') { $depth++; }
                elseif ($s === '(' || $s === '[' || $s === '{' || (is_array($tk) && $tk[0] === T_CURLY_OPEN) || (is_array($tk) && $tk[0] === T_DOLLAR_OPEN_CURLY_BRACES)) { if ($depth === 0) break; $depth--; }
                elseif ($depth === 0 && ((is_array($tk) && in_array($tk[0], STOP_TYPES, true)) || in_array($s, STOP_CHARS, true))) break;
            } else {
                if ($s === '(' || $s === '[' || $s === '{') { $depth++; }
                elseif ($s === ')' || $s === ']' || $s === '}') { if ($depth === 0) break; $depth--; }
                elseif ($depth === 0 && ((is_array($tk) && in_array($tk[0], STOP_TYPES, true)) || in_array($s, STOP_CHARS, true))) break;
            }
            if ($dir < 0) { $a = $k; if ($b === null) $b = $start; } else { if ($a === null) $a = $start; $b = $k; }
        }
        if ($a === null) continue;
        // trim whitespace inside the operand range
        while ($a <= $b && is_ws($tokens[$a])) $a++;
        while ($b >= $a && is_ws($tokens[$b])) $b--;
        if ($a > $b) continue;
        $operand = '';
        for ($k = $a; $k <= $b; $k++) $operand .= tok_str($tokens[$k]);
        $flat = trim(preg_replace('~\s+~', ' ', $operand));
        if ($flat === '' || preg_match($safeRe, $flat)) continue;
        // never guess with mixed comparison operators ("$a < $b == 0") - report and skip
        if (preg_match('~(<=|>=|<|>|==|!=)~', preg_replace('~([\'"]).*?\1|->|=>~', '', $flat))) {
            fwrite(STDERR, "MANUELL: $file:" . (is_array($t) ? $t[2] : '?') . "  $flat\n");
            continue;
        }
        // pure arithmetic of numbers/variables is numeric already (or throws in PHP 8 anyway)
        if (preg_match('~(^|[^\'"])(\s[\+\-\*/%]\s)~', $flat) && ! preg_match('~[\'"]~', $flat)) continue;

        // mirror the operator when the literal is on the left side
        $op = $opType;
        if ($side === 'left') { $op = array('lt' => 'gt', 'gt' => 'lt', 'le' => 'ge', 'ge' => 'le', 'eq' => 'eq', 'ne' => 'ne')[$opType]; }
        $num = ($neg ? '-' : '') . $litText;
        if ($op === 'eq') $rep_txt = 'astpp_eq0(' . $operand . ')';
        elseif ($op === 'ne') $rep_txt = '(!astpp_eq0(' . $operand . '))';
        else $rep_txt = 'astpp_' . $op . '(' . $operand . ', ' . $num . ')';

        $from = min($a, $lf, $i); $to = max($b, $lt, $i);
        // whole range must not overlap an earlier edit
        $overlap = false;
        foreach ($edits as $e) if (! ($to < $e[0] || $from > $e[1])) $overlap = true;
        if ($overlap) continue;
        $edits[] = array($from, $to, $rep_txt, $tokens[$i][2] ?? 0, $flat, $opType);
    }
    if (! $edits) continue;
    usort($edits, function ($x, $y) { return $x[0] - $y[0]; });
    $out = ''; $pos = 0;
    foreach ($edits as $e) {
        for ($k = $pos; $k < $e[0]; $k++) $out .= tok_str($tokens[$k]);
        $out .= $e[2];
        $pos = $e[1] + 1;
        $line = is_array($tokens[$e[0]]) ? $tokens[$e[0]][2] : '?';
        if ($rep) fwrite($rep, "$file:$line\t{$e[5]}\t{$e[4]}\t{$e[2]}\n");
    }
    for ($k = $pos; $k < $n; $k++) $out .= tok_str($tokens[$k]);
    $grand += count($edits);
    echo "$file: " . count($edits) . "\n";
    if (! $dry) file_put_contents($file, $out);
}
if ($rep) fclose($rep);
echo "Gesamt: $grand Vergleiche\n";

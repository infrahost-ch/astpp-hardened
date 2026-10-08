<?php
/*
 * Verifies the PHP 7 compatibility wrappers.
 *   php7.4 php7compat_test.php native  > native.txt   (real PHP 7 functions)
 *   php8.5 php7compat_test.php compat  > compat.txt   (astpp_* wrappers on PHP 8)
 *   diff native.txt compat.txt
 */
error_reporting(0);
$mode = $argv[1] ?? 'native';
if ($mode === 'compat') {
    require __DIR__ . '/php7compat.php';
}
date_default_timezone_set('UTC');
function f($name) {
    global $mode;
    return $mode === 'compat' ? 'astpp_' . $name : $name;
}
function show($label, $fn) {
    try {
        $r = $fn();
        $out = var_export($r, true);
    } catch (Throwable $e) {
        $out = 'EXCEPTION ' . get_class($e);
    }
    echo $label, ' => ', str_replace("\n", ' ', $out), "\n";
}
$o = new stdClass(); $o->a = 1; $o->b = '';
$vals = array(
    'null' => null, 'empty' => '', 'abc' => 'abc', 's0' => '0', 's12' => '12', 's12abc' => '12abc', 'sp12' => ' 12',
    's1.5' => '1.5', 'sneg' => '-7', 'i12' => 12, 'f1.5' => 1.5, 'i0' => 0, 'ineg' => -3, 'true' => true, 'false' => false,
    'arr0' => array(), 'arr123' => array(1, 2, 3), 'assoc' => array('a' => 1, 'b' => ''), 'mixed' => array(0, '', 'x', '1'),
    'obj' => $o,
);
$scalars = array_filter($vals, function ($v) { return ! is_array($v) && ! is_object($v); });

foreach ($vals as $k => $v) {
    show("count($k)", function () use ($v) { return call_user_func(f('count'), $v); });
    show("array_keys($k)", function () use ($v) { return call_user_func(f('array_keys'), $v); });
    show("array_values($k)", function () use ($v) { return call_user_func(f('array_values'), $v); });
    show("array_unique($k)", function () use ($v) { return call_user_func(f('array_unique'), $v); });
    show("array_slice($k,1)", function () use ($v) { return call_user_func(f('array_slice'), $v, 1); });
    show("array_filter($k)", function () use ($v) { return call_user_func(f('array_filter'), $v); });
    show("array_diff($k,[1])", function () use ($v) { return call_user_func(f('array_diff'), $v, array(1)); });
    show("array_combine($k,$k)", function () use ($v) { return call_user_func(f('array_combine'), $v, $v); });
    show("array_map(strval,$k)", function () use ($v) { return call_user_func(f('array_map'), 'strval', $v); });
    show("max($k)", function () use ($v) { return call_user_func(f('max'), $v); });
    show("min($k)", function () use ($v) { return call_user_func(f('min'), $v); });
    show("current($k)", function () use ($v) { return call_user_func(f('current'), $v); });
    show("key($k)", function () use ($v) { return call_user_func(f('key'), $v); });
    show("end($k)", function () use ($v) { $x = $v; $fn = f('end'); return $fn($x); });
    show("reset($k)", function () use ($v) { $x = $v; $fn = f('reset'); return $fn($x); });
    show("implode(',',$k)", function () use ($v) { return call_user_func(f('implode'), ',', $v); });
    show("implode($k,',')", function () use ($v) { return call_user_func(f('implode'), $v, ','); });
    show("implode($k)", function () use ($v) { return call_user_func(f('implode'), $v); });
    show("join('-',$k)", function () use ($v) { return call_user_func(f('join'), '-', $v); });
    show("array_merge($k,[9])", function () use ($v) { return call_user_func(f('array_merge'), $v, array(9)); });
    show("array_merge([9],$k)", function () use ($v) { return call_user_func(f('array_merge'), array(9), $v); });
    show("number_format($k)", function () use ($v) { return call_user_func(f('number_format'), $v); });
    show("number_format($k,2)", function () use ($v) { return call_user_func(f('number_format'), $v, 2); });
    show("number_format(1234.567,$k)", function () use ($v) { return call_user_func(f('number_format'), 1234.567, $v); });
    show("round($k)", function () use ($v) { return call_user_func(f('round'), $v); });
    show("round($k,2)", function () use ($v) { return call_user_func(f('round'), $v, 2); });
    show("round(3.14159,$k)", function () use ($v) { return call_user_func(f('round'), 3.14159, $v); });
    show("floor($k)", function () use ($v) { return call_user_func(f('floor'), $v); });
    show("ceil($k)", function () use ($v) { return call_user_func(f('ceil'), $v); });
    show("abs($k)", function () use ($v) { return call_user_func(f('abs'), $v); });
    show("str_repeat('ab',$k)", function () use ($v) { return call_user_func(f('str_repeat'), 'ab', $v); });
    show("str_repeat($k,2)", function () use ($v) { return call_user_func(f('str_repeat'), $v, 2); });
    show("date(Y-m-d,$k)", function () use ($v) { return call_user_func(f('date'), 'Y-m-d H:i', $v); });
    show("gmdate(Y-m-d,$k)", function () use ($v) { return call_user_func(f('gmdate'), 'Y-m-d H:i', $v); });
    foreach ($vals as $k2 => $h) {
        show("in_array($k,$k2)", function () use ($v, $h) { return call_user_func(f('in_array'), $v, $h); });
        show("in_array_strict($k,$k2)", function () use ($v, $h) { return call_user_func(f('in_array'), $v, $h, true); });
        show("array_search($k,$k2)", function () use ($v, $h) { return call_user_func(f('array_search'), $v, $h); });
        if (! is_array($v) && ! is_object($v)) {
            show("array_key_exists($k,$k2)", function () use ($v, $h) { return call_user_func(f('array_key_exists'), $v, $h); });
        }
    }
}
// comparison helpers vs. the native PHP 7 operators
foreach ($vals as $k => $v) {
    foreach (array(0, -1, -2.5) as $n) {
        if ($n == 0) {
            show("eq0($k)", function () use ($v, $mode) { return $mode === 'compat' ? astpp_eq0($v) : $v == 0; });
        }
        show("lt($k,$n)", function () use ($v, $n, $mode) { return $mode === 'compat' ? astpp_lt($v, $n) : $v < $n; });
        show("le($k,$n)", function () use ($v, $n, $mode) { return $mode === 'compat' ? astpp_le($v, $n) : $v <= $n; });
        show("gt($k,$n)", function () use ($v, $n, $mode) { return $mode === 'compat' ? astpp_gt($v, $n) : $v > $n; });
        show("ge($k,$n)", function () use ($v, $n, $mode) { return $mode === 'compat' ? astpp_ge($v, $n) : $v >= $n; });
    }
    foreach ($scalars as $k2 => $w) {
        show("loose_eq($k,$k2)", function () use ($v, $w, $mode) { return $mode === 'compat' ? astpp_loose_eq($v, $w) : $v == $w; });
    }
}
show("number_format(1234.5,2,',','.')", function () { return call_user_func(f('number_format'), 1234.5, 2, ',', '.'); });
show("number_format(1234.5,2,',')", function () { return call_user_func(f('number_format'), 1234.5, 2, ','); });
show("max(1,'2')", function () { return call_user_func(f('max'), 1, '2'); });
show("array_merge()", function () { return call_user_func(f('array_merge')); });
show("array_slice([1,2,3],'')", function () { return call_user_func(f('array_slice'), array(1, 2, 3), ''); });
show("array_slice([1,2,3],1,'')", function () { return call_user_func(f('array_slice'), array(1, 2, 3), 1, ''); });
show("date('Y') no ts", function () { return call_user_func(f('date'), 'Y') === date('Y'); });
// arithmetic: astpp_n() operands vs. the native PHP 7 operators
$arith = array_merge($scalars, array('sp' => ' ', 'sdot' => '.', 'sminus' => '-', 's1e3' => '1e3', 'shex' => '0x1A', 's5sp' => '5 ',
    'sp5sp' => ' 5 ', 'sbig' => '99999999999999999999', 'scomma' => '1,234.50', 'sneg1.5x' => '-1.5x', 'snl' => "\n3"));
foreach ($arith as $k => $v) {
    foreach (array(7, 2.5, -3, '4', '') as $w) {
        $kw = var_export($w, true);
        show("add($k,$kw)", function () use ($v, $w, $mode) { return $mode === 'compat' ? astpp_n($v) + astpp_n($w) : $v + $w; });
        show("sub($kw,$k)", function () use ($v, $w, $mode) { return $mode === 'compat' ? astpp_n($w) - astpp_n($v) : $w - $v; });
        show("mul($k,$kw)", function () use ($v, $w, $mode) { return $mode === 'compat' ? astpp_n($v) * astpp_n($w) : $v * $w; });
        show("div($k,$kw)", function () use ($v, $w, $mode) { return $mode === 'compat' ? astpp_div($v, $w) : $v / $w; });
        show("rdiv($kw,$k)", function () use ($v, $w, $mode) { return $mode === 'compat' ? astpp_div($w, $v) : $w / $v; });
        show("mod($k,$kw)", function () use ($v, $w, $mode) { return $mode === 'compat' ? astpp_n($v) % astpp_n($w) : $v % $w; });
        show("pow($k,$kw)", function () use ($v, $w, $mode) { return $mode === 'compat' ? astpp_n($v) ** astpp_n($w) : $v ** $w; });
    }
    show("neg($k)", function () use ($v, $mode) { return $mode === 'compat' ? -astpp_n($v) : -$v; });
    show("pos($k)", function () use ($v, $mode) { return $mode === 'compat' ? +astpp_n($v) : +$v; });
    show("addeq($k)", function () use ($v, $mode) { $a = $v; if ($mode === 'compat') { $a = astpp_n($a) + 1.5; } else { $a += 1.5; } return $a; });
}
foreach (array('arr0' => array(), 'arr123' => array(1, 2, 3)) as $k => $v) {
    show("union($k)", function () use ($v, $mode) { return $mode === 'compat' ? astpp_n($v) + astpp_n(array(9 => 9)) : $v + array(9 => 9); });
    show("addarr($k)", function () use ($v, $mode) { return $mode === 'compat' ? astpp_n($v) + astpp_n(1) : $v + 1; });
}
foreach (array(-0.0, 0.0, 0, '0', '0.0', '-0', '-0.0', ' -0', null, false, '', 'abc', ' 0') as $z) {
    foreach (array(5, -5, 0, 2.5, '7', '') as $x) {
        $l = var_export($x, true) . '/' . var_export($z, true);
        show("div0 $l", function () use ($x, $z, $mode) { return $mode === 'compat' ? astpp_div($x, $z) : $x / $z; });
    }
}
$arrv = array(1); $two = 2;
show("divarr", function () use ($mode, $arrv, $two) { return $mode === 'compat' ? astpp_div($arrv, $two) : $arrv / $two; });
// chr()
foreach (array_merge($vals, array('f65.9' => 65.9, 'fneg' => -1.5, 'fbig' => 1e30, 'nan' => NAN, 'inf' => INF, 's1e30' => '1e30', 's65.9' => '65.9',
    'sp66' => ' 66', 's66sp' => '66 ', 'i-1' => -1, 'i300' => 300, 's300' => '300', 'sx' => 'x', 'sneg' => '-3')) as $k => $v) {
    show("chr($k)", function () use ($v) { return bin2hex(call_user_func(f('chr'), $v)); });
}

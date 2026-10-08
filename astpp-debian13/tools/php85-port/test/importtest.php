<?php
/*
 * CSV rate import A/B (test DB): the same CSV is imported with 7.4 (127.0.0.3) into
 * rate group 4 / trunk 1 and with 8.5 (127.0.0.2) into rate group 5 / trunk 2;
 * preview pages and the imported rows are compared.
 */
$host = 'astpp.example.com'; $base = "https://$host/";
foreach (file('/root/astpp-credentials.txt') as $l) if (preg_match('/^Admin-Passwort\s*:\s*(\S+)/', $l, $m)) $pw = $m[1];
function r($ip, $jar, $u, $p = null) {
    global $host;
    $c = curl_init($u);
    curl_setopt_array($c, array(CURLOPT_RETURNTRANSFER => 1, CURLOPT_SSL_VERIFYPEER => 0, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_TIMEOUT => 120,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_RESOLVE => array("$host:443:$ip"), CURLOPT_USERAGENT => 'Mozilla/5.0 importtest'));
    if ($p !== null) { curl_setopt($c, CURLOPT_POST, 1); curl_setopt($c, CURLOPT_POSTFIELDS, $p); }
    $b = (string) curl_exec($c);
    return array(curl_getinfo($c, CURLINFO_HTTP_CODE), $b, (string) curl_getinfo($c, CURLINFO_REDIRECT_URL));
}
function norm($s, $ids) {
    $s = preg_replace('~<style.*?</style>~s', '', $s);
    $s = str_replace($ids, 'ID', $s);
    $s = preg_replace('~\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?~', 'TS', $s);
    $s = preg_replace('~\s+~', ' ', $s);
    return trim($s);
}
$csv = "code,destination,connection cost (USD),grace time,cost / min (USD),initial increment,increment\n"
    . "4144,Z\xC3\xBCrich Festnetz,0.01,0,0.05,60,60\n"
    . "4179,Mobile CH,,,,,\n"
    . "4176,Mobile Sunrise,abc,x,0.2,1,1\n"
    . "49,Deutschland,0,0,0.02,1,1\n"
    . "1212,\"New York, NY\",0.0,0,0.01,30,6\n"
    . ",empty code,0,0,0,0,0\n"
    . "33,\"France \"\"Paris\"\"\",0,0,0.03,60,60\n"
    . "4143,Z\xFCrich latin1,0,0,0.04,1,1\n"
    . "44,UK,-1,-5,-0.5,-1,-1\n";
$tmp = '/tmp/importtest-rates.csv'; file_put_contents($tmp, $csv);
$side = array('7.4' => array('ip' => '127.0.0.3', 'pl' => 4, 'trunk' => 1), '8.5' => array('ip' => '127.0.0.2', 'pl' => 5, 'trunk' => 2));
$out = array();
foreach ($side as $v => &$s) {
    $s['jar'] = tempnam('/tmp', 'imp');
    r($s['ip'], $s['jar'], $base . 'login/login');
    list($c) = r($s['ip'], $s['jar'], $base . 'login/login', array('username' => 'admin', 'password' => $pw));
    echo "Login $v: $c\n";
    // origination
    r($s['ip'], $s['jar'], $base . 'rates/origination_rate_import/');
    list($c, $b) = r($s['ip'], $s['jar'], $base . 'rates/origination_rate_preview_file/', array('pricelist_id' => $s['pl'], 'trunk_id' => '0', 'check_header' => '1',
        'origination_rate_import' => new CURLFile($tmp, 'text/csv', 'rates.csv')));
    $out[$v]['orig_preview'] = array($c, $b);
    list($c, $b, $loc) = r($s['ip'], $s['jar'], $base . "rates/origination_rate_import_file/{$s['pl']}/0/1");
    $out[$v]['orig_import'] = array($c, $b . $loc);
    // termination
    r($s['ip'], $s['jar'], $base . 'rates/termination_rate_import/');
    list($c, $b) = r($s['ip'], $s['jar'], $base . 'rates/termination_rate_preview_file/', array('trunk_id' => $s['trunk'], 'check_header' => '1',
        'termination_rate_import' => new CURLFile($tmp, 'text/csv', 'rates.csv')));
    $out[$v]['term_preview'] = array($c, $b);
    list($c, $b, $loc) = r($s['ip'], $s['jar'], $base . "rates/termination_rate_rates_import/{$s['trunk']}/1");
    $out[$v]['term_import'] = array($c, $b . $loc);
}
unset($s);
foreach (array('orig_preview', 'orig_import', 'term_preview', 'term_import') as $k) {
    $a = norm($out['7.4'][$k][1], array()); $b = norm($out['8.5'][$k][1], array());
    // rate group / trunk ids differ by design
    $a = preg_replace(array('~PHP85TEST200~', '~/4/~', '~value="4"~'), array('PL', '/X/', 'value="X"'), $a);
    $b = preg_replace(array('~PHP85TEST210~', '~/5/~', '~value="5"~'), array('PL', '/X/', 'value="X"'), $b);
    printf("%-13s 7.4 HTTP %d %6d | 8.5 HTTP %d %6d  %s\n", $k, $out['7.4'][$k][0], strlen($out['7.4'][$k][1]), $out['8.5'][$k][0], strlen($out['8.5'][$k][1]), $a === $b ? 'identisch' : 'ABWEICHEND');
    if ($a !== $b) {
        $p = 0; $l = min(strlen($a), strlen($b)); while ($p < $l && $a[$p] === $b[$p]) $p++;
        echo "   7.4: " . substr($a, max(0, $p - 100), 260) . "\n   8.5: " . substr($b, max(0, $p - 100), 260) . "\n";
    }
}

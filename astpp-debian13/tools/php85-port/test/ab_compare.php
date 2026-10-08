<?php
/*
 * A/B content comparison: every URL the crawler visited is fetched from
 *   A = 127.0.0.3  original code on PHP 7.4
 *   B = 127.0.0.2  ported code on PHP 8.5
 * (same test database). Responses are normalised (timestamps, random values,
 * session data) and compared. Prints every URL whose output differs.
 *
 *   php8.5 ab_compare.php crawl85.txt [--user=admin --pass=...] [--log-from=LINE]
 */
$host = 'astpp.example.com'; $base = "https://$host/";
$pw = null;
foreach (file('/root/astpp-credentials.txt') as $l) if (preg_match('/^Admin-Passwort\s*:\s*(\S+)/', $l, $m)) $pw = $m[1];
$user = 'admin'; $logFrom = 0;
foreach ($argv as $a) {
    if (strpos($a, '--user=') === 0) $user = substr($a, 7);
    if (strpos($a, '--pass=') === 0) $pw = substr($a, 7);
    if (strpos($a, '--log-from=') === 0) $logFrom = (int) substr($a, 11);
}

function mk($ip) {
    return array('ip' => $ip, 'jar' => tempnam('/tmp', 'ab'));
}
function r($c, $u, $p = null) {
    global $host;
    $ch = curl_init($u);
    curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => 1, CURLOPT_SSL_VERIFYPEER => 0, CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_COOKIEJAR => $c['jar'], CURLOPT_COOKIEFILE => $c['jar'], CURLOPT_RESOLVE => array("$host:443:" . $c['ip']),
        CURLOPT_USERAGENT => 'Mozilla/5.0 ab-compare', CURLOPT_TIMEOUT => 120));
    if (strpos($u, '_json') !== false) curl_setopt($ch, CURLOPT_HTTPHEADER, array('X-Requested-With: XMLHttpRequest'));
    if ($p !== null) { curl_setopt($ch, CURLOPT_POST, 1); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($p)); }
    $b = (string) curl_exec($ch);
    return array(curl_getinfo($ch, CURLINFO_HTTP_CODE), $b, (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL));
}
function norm($s) {
    $s = preg_replace('~<style.*?</style>~s', '', $s);
    $s = preg_replace('~\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?~', 'TS', $s);          // timestamps
    $s = preg_replace('~\d{1,2}:\d{2}(:\d{2})?\s?(AM|PM)?~i', 'TIME', $s);
    $s = preg_replace('~(value=["\'])[A-Za-z0-9$!@#%^&*()+]{6,20}(["\'])~', '$1RND$2', $s); // generated numbers/passwords
    $s = preg_replace('~\b\d{6,}\b~', 'NUM', $s);                                        // generated account numbers, pins
    $s = preg_replace('~(captcha[^"\']*\.(png|jpg))~i', 'CAPTCHA', $s);
    $s = preg_replace('~\s+~', ' ', $s);
    return trim($s);
}
$A = mk('127.0.0.3'); $B = mk('127.0.0.2');
foreach (array($A, $B) as $c) {
    r($c, $base . 'login/login');
    list($code) = r($c, $base . 'login/login', array('username' => $user, 'password' => $pw));
    echo "Login {$c['ip']}: $code\n";
}
$urls = array();
foreach (file($argv[1]) as $l) if (preg_match('~^\S*\s+\d{3}\s+\d+\s+[\d.]+s\s+(\S+)~', $l, $m)) $urls[] = $m[1];
// the crawler only prints problem URLs - use the nginx access log of the 8.5 instance for the full list
foreach (array_slice(file('/var/log/nginx/astpp85_access.log'), $logFrom) as $l) {
    if (preg_match('~"GET /([^ ?"]*)(\?[^ "]*)? HTTP~', $l, $m) && ! preg_match('~\.(css|js|png|jpe?g|gif|ico|svg|woff2?|ttf|eot|wav|mp3|map)$~i', $m[1])) $urls[] = $m[1] . ($m[2] ?? '');
}
// never replay URLs that send commands to FreeSWITCH (both instances would execute them)
$urls = array_values(array_unique(array_filter($urls, function ($u) { return $u !== '' && strpos($u, 'login') !== 0 && strpos($u, 'logout') === false && ! preg_match('~_action/|rescan~', $u); })));
$same = 0; $diff = 0; $diffs = array();
foreach ($urls as $u) {
    list($ca, $ba, $la) = r($A, $base . ltrim($u, '/'));
    list($cb, $bb, $lb) = r($B, $base . ltrim($u, '/'));
    $na = norm($ba); $nb = norm($bb);
    $la = preg_replace('~^https://[^/]+/~', '', $la); $lb = preg_replace('~^https://[^/]+/~', '', $lb);
    if ($ca === $cb && $na === $nb && $la === $lb) { $same++; continue; }
    $diff++;
    // first differing position for a short excerpt
    $pos = 0; $len = min(strlen($na), strlen($nb));
    while ($pos < $len && $na[$pos] === $nb[$pos]) $pos++;
    $diffs[] = sprintf("%s\n    7.4: %d %s %s\n    8.5: %d %s %s", $u, $ca, $la, substr($na, max(0, $pos - 60), 160), $cb, $lb, substr($nb, max(0, $pos - 60), 160));
}
echo "URLs: " . count($urls) . ", identisch: $same, abweichend: $diff\n\n";
echo implode("\n", $diffs), "\n";
@unlink($A['jar']); @unlink($B['jar']);

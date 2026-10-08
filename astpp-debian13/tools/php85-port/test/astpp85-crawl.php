<?php
/*
 * ASTPP PHP 8.5 port - crawler for the internal test vhost (127.0.0.2).
 * Logs in as admin, follows all same-host links and grid JSON endpoints, skips
 * anything destructive, and attributes new PHP log lines to each request.
 *
 *   php8.5 astpp85-crawl.php [max_urls] [--user=admin] [--pass-file=/root/astpp-credentials.txt]
 */
$host = 'astpp.example.com';
$ip = getenv('CRAWL_IP') ?: '127.0.0.2';
$base = "https://$host/";
$log = getenv('CRAWL_LOG') ?: '/var/log/astpp/php85_errors.log';
$max = isset($argv[1]) && ctype_digit($argv[1]) ? (int) $argv[1] : 1500;
$user = 'admin';
$pass = null;
foreach ($argv as $a) {
    if (strpos($a, '--user=') === 0) $user = substr($a, 7);
    if (strpos($a, '--pass=') === 0) $pass = substr($a, 7);
}
if ($pass === null) {
    foreach (file('/root/astpp-credentials.txt') as $l) {
        if (preg_match('/^Admin-Passwort\s*:\s*(\S+)/', $l, $m)) $pass = $m[1];
    }
}
$jar = tempnam('/tmp', 'crawl');

// never follow URLs that change or destroy data, or that send commands to FreeSWITCH
// (fssipprofile_action/start|stop|rescan/<id>, gateway actions)
$skip = '~(_action/|rescan|logout|delet|remove|/del_|_del/|terminat|purge|restore|backup_save|reset|release|regenerat|login_as|status_change|change_status|cancel|void|truncate|rebuild|reload|send|process|generate|refund|approve|reject|_save|save/|batch_update|recharge|payment_process|paypal|optin|optout|install|uninstall|activate|deactivate|unblock|duplicate|transfer|kill|hangup|originate|import_file|import_data|_import_|translation_script|currencyupdate|getstatus|feedback|crons|run_cron|purge)~i';

function req($url, $post = null) {
    global $jar, $host, $ip;
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => false, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_TIMEOUT => 120,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_RESOLVE => array("$host:443:$ip"),
        CURLOPT_USERAGENT => "Mozilla/5.0 (X11; Linux x86_64) astpp85-crawler",
    ));
    if (strpos($url, '_json') !== false) curl_setopt($ch, CURLOPT_HTTPHEADER, array('X-Requested-With: XMLHttpRequest'));
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = curl_exec($ch);
    $info = curl_getinfo($ch);
    return array((int) $info['http_code'], (string) $body, (string) ($info['redirect_url'] ?? ''), (float) $info['total_time']);
}

function new_log_lines() {
    global $log;
    static $pos = null;
    clearstatcache();
    $size = @filesize($log);
    if ($pos === null) { $pos = $size; return array(); }
    if ($size < $pos) $pos = 0;
    if ($size == $pos) return array();
    $fh = fopen($log, 'r'); fseek($fh, $pos); $data = stream_get_contents($fh); fclose($fh);
    $pos = $size;
    $out = array();
    foreach (explode("\n", $data) as $l) {
        if (preg_match('/^\[[^\]]+\] (PHP .*)$/', $l, $m)) $out[] = $m[1];
    }
    return $out;
}

function norm($u) {
    global $base, $host;
    $u = html_entity_decode(trim($u));
    $u = preg_replace('~#.*$~', '', $u);
    if ($u === '' || preg_match('~^(javascript:|mailto:|tel:|data:)~i', $u)) return null;
    if (strpos($u, '//') === 0) $u = 'https:' . $u;
    if ($u[0] === '/') $u = "https://$host" . $u;
    if (strpos($u, $base) !== 0) return null;
    $path = substr($u, strlen($base));
    if (preg_match('~\.(css|js|png|jpe?g|gif|ico|svg|woff2?|ttf|eot|wav|mp3|map|pdf|csv|xls)$~i', $path)) return null;
    $path = preg_replace('~/+~', '/', $path);
    return $base . $path;
}

new_log_lines();
list($c) = req($base . 'login/login');
list($c, $b, $loc) = req($base . 'login/login', array('username' => $user, 'password' => $pass));
echo "Login: HTTP $c -> $loc\n";
foreach (new_log_lines() as $l) echo "  [login] $l\n";
if ($c != 302 || strpos($loc, 'login') !== false) { echo "LOGIN FAILED\n"; exit(1); }

$queue = array($base . 'dashboard/');
$seen = array($base . 'dashboard/' => true);
$results = array();
$errors_by_msg = array();
$n = 0;
while ($queue && $n < $max) {
    $url = array_shift($queue);
    $n++;
    $fetch = $url;
    if (preg_match('~_json/?$|_json/~', $url) && strpos($url, '?') === false) $fetch = $url . (substr($url, -1) == '/' ? '' : '/') . '?rp=10&page=1';
    list($code, $body, $loc, $t) = req($fetch);
    $lines = new_log_lines();
    $sig = array();
    foreach ($lines as $l) {
        if (strpos($l, 'PHP Deprecated') === 0) continue;
        $key = preg_replace('~ \[/[^\]]*\]$~', '', $l);
        $errors_by_msg[$key][] = $url;
        $sig[] = $l;
    }
    if (strpos($body, 'An internal error occurred') !== false || strpos($body, 'A PHP Error was encountered') !== false || strpos($body, 'A Database Error Occurred') !== false) {
        $sig[] = 'PAGE SHOWS ERROR';
        $errors_by_msg['PAGE SHOWS ERROR: ' . substr(strip_tags(preg_replace('~\s+~', ' ', $body)), 0, 200)][] = $url;
    }
    $results[] = array($url, $code, strlen($body), $t, count($sig), $loc);
    if ($loc !== '' && ($u = norm($loc)) !== null && ! isset($seen[$u]) && ! preg_match($skip, substr($u, strlen($base)))) {
        $seen[$u] = true; // e.g. customers are redirected from dashboard/ to their own portal
        $queue[] = $u;
    }
    if ($code == 200 && $body !== '') {
        // grids load their rows from base_url + "<module>/<method>" + "_json" (generate_grid.js)
        if (strpos($body, 'build_grid') !== false) {
            $segs = explode('/', substr($url, strlen($base)));
            if (count($segs) >= 2 && $segs[1] !== '' && substr($segs[1], -5) !== '_json') {
                $ju = $base . $segs[0] . '/' . $segs[1] . '_json/';
                if (! isset($seen[$ju])) { $seen[$ju] = true; $queue[] = $ju; }
            }
        }
        $body2 = str_replace('\/', '/', $body); // JSON-escaped URLs in inline JS
        preg_match_all('~(?:href|action|src)\s*=\s*["\']([^"\']+)["\']~i', $body2, $m1);
        preg_match_all('~https://' . preg_quote($host, '~') . '/[A-Za-z0-9_\-/\.]+~', $body2, $m2);
        foreach (array_merge($m1[1], $m2[0]) as $raw) {
            $u = norm($raw);
            if ($u === null || isset($seen[$u])) continue;
            if (preg_match($skip, substr($u, strlen($base)))) continue;
            $seen[$u] = true;
            $queue[] = $u;
        }
    }
    if ($code >= 500 || $sig) printf("%-4s %3d %7d %5.2fs  %s%s\n", count($sig) ? 'ERR' : '', $code, strlen($body), $t, substr($url, strlen($base)), $loc ? " -> $loc" : '');
}
echo "\n=== $n URLs abgerufen, " . count($queue) . " noch in der Queue ===\n";
$codes = array();
foreach ($results as $r) { $codes[$r[1]] = ($codes[$r[1]] ?? 0) + 1; }
ksort($codes);
foreach ($codes as $c => $cnt) echo "HTTP $c: $cnt\n";
echo "\n=== Fehler/Warnungen (ohne Deprecated), gruppiert ===\n";
uasort($errors_by_msg, function ($a, $b) { return count($b) - count($a); });
foreach ($errors_by_msg as $msg => $urls) {
    $u = array_values(array_unique($urls));
    printf("[%dx] %s\n      z.B. %s\n", count($urls), $msg, substr($u[0], strlen($base)));
}
@unlink($jar);

<?php
/*
 * ASTPP PHP 8.5 port - generic form tester (test instance + test database only).
 * Crawls like astpp85-crawl.php, collects every POST form, fills it with plausible
 * test values and submits it once. Reports HTTP status, application response and
 * the PHP log lines produced by each submission.
 *
 *   php8.5 astpp85-forms.php [max_pages]
 */
$host = 'astpp.example.com';
$ip = '127.0.0.2';
$base = "https://$host/";
$log = '/var/log/astpp/php85_errors.log';
$max = isset($argv[1]) && ctype_digit($argv[1]) ? (int) $argv[1] : 1500;
$pass = null;
foreach (file('/root/astpp-credentials.txt') as $l) if (preg_match('/^Admin-Passwort\s*:\s*(\S+)/', $l, $m)) $pass = $m[1];
$jar = tempnam('/tmp', 'forms');

// pages we do not open (destructive GET links) and form actions we never submit
$skip_get = '~(logout|delet|remove|/del_|_del/|terminat|purge|restore|backup_save|reset|release|regenerat|login_as|status_change|change_status|cancel|void|truncate|rebuild|reload|send|process|generate|refund|approve|reject|_save|save/|batch_update|recharge|payment_process|paypal|optin|optout|install|uninstall|activate|deactivate|unblock|duplicate|transfer|kill|hangup|originate|import_file|import_data|_import_|translation_script|currencyupdate|getstatus|feedback|crons|run_cron)~i';
$skip_post = '~(logout|delet|remove|restore|import|upload|paypal|payment_process|send|mail_to|email_send|broadcast|test_mail|testmail|originate|reload|login_as|signup|forgot|confirmpassword|password_change|user_change_password|database_|backup|addons|translation|currencyupdate|login/login)~i';

function req($url, $post = null, $ajax = false) {
    global $jar, $host, $ip;
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_TIMEOUT => 120,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_RESOLVE => array("$host:443:$ip"),
        CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64) astpp85-formtest',
    ));
    if ($ajax || strpos($url, '_json') !== false) curl_setopt($ch, CURLOPT_HTTPHEADER, array('X-Requested-With: XMLHttpRequest'));
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $body = curl_exec($ch);
    $info = curl_getinfo($ch);
    return array((int) $info['http_code'], (string) $body, (string) ($info['redirect_url'] ?? ''));
}
function new_log_lines() {
    global $log;
    static $pos = null;
    clearstatcache();
    $size = (int) @filesize($log);
    if ($pos === null) { $pos = $size; return array(); }
    if ($size < $pos) $pos = 0;
    if ($size == $pos) return array();
    $fh = fopen($log, 'r'); fseek($fh, $pos); $data = stream_get_contents($fh); fclose($fh);
    $pos = $size;
    $out = array();
    foreach (explode("\n", $data) as $l) if (preg_match('/^\[[^\]]+\] (PHP .*)$/', $l, $m)) $out[] = $m[1];
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
    return $base . preg_replace('~/+~', '/', $path);
}
$seq = 0;
function fill($name, $type) {
    global $seq;
    $seq++;
    $n = strtolower($name);
    if ($type === 'email' || strpos($n, 'mail') !== false) return "php85test$seq@example.com";
    if (strpos($n, 'password') !== false || $n === 'pass') return 'Php85Test!' . (1000 + $seq);
    if (preg_match('/(^ip$|_ip$|^ip_|ipaddress)/', $n)) return '192.0.2.' . (10 + $seq % 200);
    if (preg_match('/(domain|host|proxy|realm|server)/', $n)) return 'sip.example.com';
    if (preg_match('/port/', $n)) return '5060';
    if (preg_match('/(date|time|expiry|start|end)/', $n)) return gmdate('Y-m-d H:i:s');
    if (preg_match('/(phone|mobile|fax|telephone|did|caller|cli|number|pin|account|code|prefix|pattern|dial)/', $n)) return '4199' . str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    if (preg_match('/(postal|zip)/', $n)) return '8000';
    if (preg_match('/(credit|balance|amount|price|cost|fee|rate|charge|commission|tax|percent|discount|value)/', $n)) return '1.5';
    if (preg_match('/(inc|second|minute|day|count|limit|max|cps|priority|digit|length|timeout|channel|interval|frequency|qty|quantity|weight|precedence|retry)/', $n)) return '1';
    return 'PHP85TEST' . $seq;
}
function build_post($dom, $form) {
    $xp = new DOMXPath($dom);
    $post = array();
    $radios = array();
    foreach ($xp->query('.//input|.//select|.//textarea', $form) as $el) {
        $name = $el->getAttribute('name');
        if ($name === '' || $el->hasAttribute('disabled')) continue;
        $tag = $el->tagName;
        $type = $tag === 'input' ? strtolower($el->getAttribute('type') ?: 'text') : $tag;
        if (in_array($type, array('submit', 'button', 'reset', 'image', 'file'), true)) continue;
        if ($type === 'radio') { if (! isset($radios[$name])) { $radios[$name] = 1; $post[] = array($name, $el->getAttribute('value')); } continue; }
        if ($type === 'checkbox') { if ($el->hasAttribute('checked')) $post[] = array($name, $el->getAttribute('value') ?: '1'); continue; }
        if ($type === 'select') {
            $val = null;
            foreach ($el->getElementsByTagName('option') as $o) {
                if ($o->hasAttribute('selected')) { $val = $o->getAttribute('value'); break; }
            }
            if ($val === null || $val === '') {
                foreach ($el->getElementsByTagName('option') as $o) {
                    $v = $o->getAttribute('value');
                    if ($v !== '' && stripos($o->textContent, 'select') === false) { $val = $v; break; }
                }
            }
            $post[] = array($name, (string) $val);
            continue;
        }
        $val = $type === 'textarea' ? $el->textContent : $el->getAttribute('value');
        if ($val === '' && $type !== 'hidden') $val = fill($name, $type);
        $post[] = array($name, $val);
    }
    // http_build_query-compatible nested array (keeps name[] semantics)
    $qs = array();
    foreach ($post as $p) $qs[] = rawurlencode($p[0]) . '=' . rawurlencode((string) $p[1]);
    parse_str(implode('&', $qs), $arr);
    return $arr;
}

new_log_lines();
req($base . 'login/login');
list($c, , $loc) = req($base . 'login/login', array('username' => 'admin', 'password' => $pass));
echo "Login: HTTP $c -> $loc\n";
if ($c != 302) exit(1);

$queue = array($base . 'dashboard/');
$seen = array($base . 'dashboard/' => true);
$forms = array();
$n = 0;
while ($queue && $n < $max) {
    $url = array_shift($queue);
    $n++;
    list($code, $body) = req($url);
    new_log_lines();
    if ($code != 200 || $body === '') continue;
    $dom = new DOMDocument();
    @$dom->loadHTML($body);
    foreach ($dom->getElementsByTagName('form') as $f) {
        if (strtolower($f->getAttribute('method')) !== 'post') continue;
        $action = norm($f->getAttribute('action'));
        if ($action === null) continue;
        $rel = substr($action, strlen($base));
        if (preg_match($skip_post, $rel)) continue;
        if (isset($forms[$rel])) continue;
        $forms[$rel] = array('page' => substr($url, strlen($base)), 'post' => build_post($dom, $f));
    }
    $body2 = str_replace('\/', '/', $body); // JSON-escaped URLs in inline JS
    preg_match_all('~(?:href|action|src)\s*=\s*["\']([^"\']+)["\']~i', $body2, $m1);
    preg_match_all('~https://' . preg_quote($host, '~') . '/[A-Za-z0-9_\-/\.]+~', $body2, $m2);
    foreach (array_merge($m1[1], $m2[0]) as $raw) {
        $u = norm($raw);
        if ($u === null || isset($seen[$u]) || preg_match($skip_get, substr($u, strlen($base)))) continue;
        $seen[$u] = true;
        $queue[] = $u;
    }
}
echo "Seiten: $n, Formulare gefunden: " . count($forms) . "\n\n";

$summary = array();
foreach ($forms as $rel => $f) {
    list($code, $body, $loc) = req($base . $rel, $f['post'], true);
    $lines = array_values(array_filter(new_log_lines(), function ($l) { return strpos($l, 'PHP Deprecated') !== 0; }));
    $resp = trim(preg_replace('~\s+~', ' ', strip_tags($body)));
    $kind = 'OK';
    foreach ($lines as $l) if (preg_match('/Uncaught|Fatal/', $l)) $kind = 'FATAL';
    if ($kind === 'OK' && $code >= 500) $kind = 'HTTP' . $code;
    if ($kind === 'OK' && preg_match('/A PHP Error|Database Error|internal error/i', $body)) $kind = 'ERRPAGE';
    if ($kind === 'OK' && $lines) $kind = 'WARN';
    $summary[$kind] = ($summary[$kind] ?? 0) + 1;
    printf("%-7s %3d %-55s (von %s)%s\n", $kind, $code, $rel, $f['page'], $loc ? " -> " . substr($loc, strlen($base)) : '');
    if ($kind !== 'OK') {
        if ($resp !== '') echo "        Antwort: " . substr($resp, 0, 160) . "\n";
        foreach (array_slice(array_unique($lines), 0, 6) as $l) echo "        " . substr(str_replace('/opt/ASTPP85/web_interface/astpp/', '', $l), 0, 260) . "\n";
    }
}
echo "\n=== Zusammenfassung ===\n";
foreach ($summary as $k => $v) echo "$k: $v\n";
@unlink($jar);

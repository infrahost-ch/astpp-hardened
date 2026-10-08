<?php
/*
 * Test instance only: create a reseller via the web form (PHP 8.5 instance) and set
 * known passwords for one customer and the reseller in the TEST database.
 * Passwords are written to /root/astpp85-testusers.txt (root only).
 */
$host = 'astpp.example.com'; $base = "https://$host/";
$jar = tempnam('/tmp', 'mk');
function r($u, $p = null) {
    global $jar, $host;
    $c = curl_init($u);
    curl_setopt_array($c, array(CURLOPT_RETURNTRANSFER => 1, CURLOPT_SSL_VERIFYPEER => 0, CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_RESOLVE => array("$host:443:127.0.0.2"),
        CURLOPT_USERAGENT => 'Mozilla/5.0 mkusers', CURLOPT_HTTPHEADER => array('X-Requested-With: XMLHttpRequest')));
    if ($p !== null) { curl_setopt($c, CURLOPT_POST, 1); curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($p)); }
    $b = curl_exec($c);
    return array(curl_getinfo($c, CURLINFO_HTTP_CODE), $b, curl_getinfo($c, CURLINFO_REDIRECT_URL));
}
foreach (file('/root/astpp-credentials.txt') as $l) if (preg_match('/^Admin-Passwort\s*:\s*(\S+)/', $l, $m)) $pw = $m[1];
r($base . 'login/login');
r($base . 'login/login', array('username' => 'admin', 'password' => $pw));
list($c, $html) = r($base . 'accounts/reseller_add/');
$dom = new DOMDocument(); @$dom->loadHTML($html); $xp = new DOMXPath($dom);
$post = array();
foreach ($dom->getElementsByTagName('form') as $f) {
    if (strpos($f->getAttribute('action'), 'reseller_save') === false) continue;
    foreach ($xp->query('.//input|.//select|.//textarea', $f) as $el) {
        $name = $el->getAttribute('name'); if ($name === '') continue;
        if ($el->tagName === 'select') {
            $val = '';
            foreach ($el->getElementsByTagName('option') as $o) { if ($o->getAttribute('value') !== '') { $val = $o->getAttribute('value'); break; } }
            if ($o = $xp->query('.//option[@selected]', $el)->item(0)) $val = $o->getAttribute('value');
        } else {
            $val = $el->tagName === 'textarea' ? $el->textContent : $el->getAttribute('value');
        }
        $post[$name] = $val;
    }
}
$post['first_name'] = 'PHP85TEST'; $post['last_name'] = 'Reseller'; $post['company_name'] = 'PHP85TEST Reseller';
$post['email'] = 'php85reseller@example.com'; $post['notification_email'] = 'php85reseller@example.com';
$post['credit_limit'] = '100'; $post['telephone_1'] = '41440000000';
list($c, $b, $loc) = r($base . 'accounts/reseller_save/', $post);
echo "reseller_save: HTTP $c " . ($loc ? "-> $loc" : '') . ' ' . substr(trim(strip_tags($b)), 0, 120) . "\n";

// known passwords for the test logins (encrypted like the application does)
$cfg = parse_ini_file('/var/lib/astpp85/astpp-config.conf');
function enc($v, $k) {
    $e = openssl_encrypt($v, 'BF-ECB', $k, OPENSSL_RAW_DATA, '');
    return str_replace(array('+', '/', '='), array('-', '$', ''), base64_encode($e));
}
$db = new mysqli($cfg['dbhost'], $cfg['dbuser'], $cfg['dbpass'], 'astpp85test');
$out = '';
foreach (array(0 => 'Kunde', 1 => 'Reseller') as $type => $label) {
    $row = $db->query("SELECT id, number FROM accounts WHERE type = $type AND status = 0 AND deleted = 0 ORDER BY id DESC LIMIT 1")->fetch_assoc();
    if (! $row) { echo "kein $label gefunden\n"; continue; }
    $p = 'Php85' . $label . '!' . random_int(1000, 9999);
    $st = $db->prepare('UPDATE accounts SET password = ? WHERE id = ?');
    $e = enc($p, $cfg['PRIVATE_KEY']);
    $st->bind_param('si', $e, $row['id']); $st->execute();
    $out .= "$label\t{$row['number']}\t$p\n";
    echo "$label: Konto {$row['number']} (id {$row['id']}) Passwort gesetzt\n";
}
file_put_contents('/root/astpp85-testusers.txt', $out);
chmod('/root/astpp85-testusers.txt', 0600);
@unlink($jar);

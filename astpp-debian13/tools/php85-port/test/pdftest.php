<?php
/*
 * Invoice PDF A/B: download every invoice as admin from 7.4 (127.0.0.3) and 8.5 (127.0.0.2),
 * compare the PDFs without the volatile parts (creation date, document id).
 */
$host = 'astpp.example.com'; $base = "https://$host/";
foreach (file('/root/astpp-credentials.txt') as $l) if (preg_match('/^Admin-Passwort\s*:\s*(\S+)/', $l, $m)) $pw = $m[1];
function r($ip, $jar, $u, $p = null) {
    global $host;
    $c = curl_init($u);
    curl_setopt_array($c, array(CURLOPT_RETURNTRANSFER => 1, CURLOPT_SSL_VERIFYPEER => 0, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_TIMEOUT => 120,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_RESOLVE => array("$host:443:$ip"), CURLOPT_USERAGENT => 'Mozilla/5.0 pdftest'));
    if ($p !== null) { curl_setopt($c, CURLOPT_POST, 1); curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($p)); }
    $b = (string) curl_exec($c);
    return array(curl_getinfo($c, CURLINFO_HTTP_CODE), (string) curl_getinfo($c, CURLINFO_CONTENT_TYPE), $b);
}
function norm($pdf) {
    $pdf = preg_replace('~/(CreationDate|ModDate) \(D:[^)]*\)~', '/$1 (X)', $pdf);
    $pdf = preg_replace('~/ID \[ ?<[0-9a-f]+> ?<[0-9a-f]+> ?\]~i', '/ID [X]', $pdf);
    $pdf = preg_replace('~<xmp:(CreateDate|ModifyDate|MetadataDate)>[^<]*~', '<xmp:$1>X', $pdf);
    $pdf = preg_replace('~uuid:[0-9a-f-]+~i', 'uuid:X', $pdf);
    return $pdf;
}
$ids = array_map('intval', array_slice($argv, 1));
$jars = array();
foreach (array('127.0.0.3', '127.0.0.2') as $ip) {
    $jars[$ip] = tempnam('/tmp', 'pdf');
    r($ip, $jars[$ip], $base . 'login/login');
    list($c) = r($ip, $jars[$ip], $base . 'login/login', array('username' => 'admin', 'password' => $pw));
    echo "Login $ip: $c\n";
}
foreach ($ids as $id) {
    $res = array();
    foreach (array('127.0.0.3' => '7.4', '127.0.0.2' => '8.5') as $ip => $v) {
        list($c, $ct, $b) = r($ip, $jars[$ip], $base . "invoices/invoice_download/$id");
        $res[$v] = $b;
        file_put_contents("/root/pdftest/invoice-$id-$v.pdf", $b);
        printf("  invoice %d %s: HTTP %d %s %d bytes %s\n", $id, $v, $c, $ct, strlen($b), substr($b, 0, 8) === '%PDF-1.7' || substr($b, 0, 5) === '%PDF-' ? 'PDF' : 'KEIN PDF: ' . substr(strip_tags($b), 0, 120));
    }
    echo "  -> " . (norm($res['7.4']) === norm($res['8.5']) ? 'identisch (ohne Datum/ID)' : 'ABWEICHEND') . "\n";
}

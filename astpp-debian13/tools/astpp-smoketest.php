<?php
/*
 * Read-only smoke test of the PRODUCTION web interface after the switch to PHP 8.5:
 * admin login, list/view pages and their JSON grids. Nothing is saved or changed.
 */
$host = 'astpp.example.com'; $base = "https://$host/";
foreach (file('/root/astpp-credentials.txt') as $l) if (preg_match('/^Admin-Passwort\s*:\s*(\S+)/', $l, $m)) $pw = $m[1];
$jar = tempnam('/tmp', 'smoke');
function r($u, $p = null) {
    global $jar, $host;
    $c = curl_init($u);
    curl_setopt_array($c, array(CURLOPT_RETURNTRANSFER => 1, CURLOPT_SSL_VERIFYPEER => 0, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_TIMEOUT => 60,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_RESOLVE => array("$host:443:127.0.0.1"), CURLOPT_USERAGENT => 'Mozilla/5.0 php85-smoketest'));
    if (strpos($u, '_json') !== false) curl_setopt($c, CURLOPT_HTTPHEADER, array('X-Requested-With: XMLHttpRequest'));
    if ($p !== null) { curl_setopt($c, CURLOPT_POST, 1); curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($p)); }
    $b = (string) curl_exec($c);
    return array(curl_getinfo($c, CURLINFO_HTTP_CODE), $b, (string) curl_getinfo($c, CURLINFO_REDIRECT_URL));
}
r($base . 'login/login');
list($c, $b, $loc) = r($base . 'login/login', array('username' => 'admin', 'password' => $pw));
echo "Login: HTTP $c -> $loc\n";
$pages = array('dashboard/', 'accounts/customer_list/', 'accounts/reseller_list/', 'accounts/provider_list/', 'accounts/admin_list/',
    'freeswitch/fssipdevices/', 'freeswitch/fssipprofile/', 'freeswitch/fsgateway/', 'freeswitch/fsserver_list/', 'did/did_list/',
    'rates/origination_rates_list/', 'rates/termination_rates_list/', 'pricing/price_list/', 'trunk/trunk_list/', 'ipmap/ipmap_detail/',
    'animap/animap_detail/', 'invoices/invoice_list/', 'invoices/invoice_conf_list/', 'reports/customerReport/', 'reports/resellerReport/',
    'reports/providerReport/', 'reports/refillreport/', 'summary/customer/', 'summary/provider/', 'products/products_list/',
    'orders/orders_list/', 'refill_coupon/refill_coupon_list/', 'taxes/taxes_list/', 'systems/configuration/global', 'systems/template/',
    'systems/country_list/', 'systems/currency_list/', 'systems/database_restore/', 'email/email_history_list/', 'calltype/calltype_list/',
    'localization/localization_list/', 'accessnumber/accessnumber_list/', 'ratedeck/ratedeck_list/', 'permissions/permissions_list/',
    'cronsettings/cron_settings_list/', 'addons/addons_list/Community', 'login_activity/login_activity_list/', 'user/user_myprofile/');
$ok = 0; $bad = array();
foreach ($pages as $p) {
    foreach (array($p, preg_replace('~^([^/]+/[^/]+?)/?$~', '$1_json/?rp=10&page=1', $p)) as $u) {
        if ($u !== $p && strpos($u, '_json') === false) continue;
        list($c, $b, $loc) = r($base . $u);
        $err = $c >= 500 || strpos($b, 'An internal error occurred') !== false || strpos($b, 'A PHP Error was encountered') !== false || strpos($b, 'A Database Error Occurred') !== false;
        if ($u !== $p && $c == 404) continue; // no grid behind this page
        if ($err || ($c != 200 && $c != 302)) $bad[] = sprintf('%-50s HTTP %d %s', $u, $c, substr(trim(strip_tags($b)), 0, 80)); else $ok++;
    }
}
echo "Seiten/Grids ok: $ok, Probleme: " . count($bad) . "\n" . implode("\n", $bad) . "\n";
r($base . 'login/logout');
@unlink($jar);

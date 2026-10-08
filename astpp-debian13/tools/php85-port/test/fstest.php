<?php
/*
 * A/B test of the FreeSWITCH endpoints (xml_curl directory/configuration, json_cdr):
 *   A = 127.0.0.1:8737  original code, PHP 7.4, DB astpp74test
 *   B = 127.0.0.1:8736  ported code,   PHP 8.5, DB astpp85test
 * Both databases are identical before the run (fstest.sh clones them). Every request
 * goes to both; responses are compared, afterwards all tables are compared.
 */
function post($port, $body, $json = false) {
    $c = curl_init("http://127.0.0.1:$port/" . ($json ? 'cdr.php' : 'index.php'));
    curl_setopt_array($c, array(CURLOPT_RETURNTRANSFER => 1, CURLOPT_POST => 1, CURLOPT_TIMEOUT => 30,
        CURLOPT_POSTFIELDS => $json ? $body : http_build_query($body),
        CURLOPT_HTTPHEADER => $json ? array('Content-Type: application/json') : array()));
    $r = (string) curl_exec($c);
    return array(curl_getinfo($c, CURLINFO_HTTP_CODE), $r);
}
$same = 0; $diff = 0;
function ab($label, $body, $json = false) {
    global $same, $diff;
    list($ca, $ra) = post(8737, $body, $json);
    list($cb, $rb) = post(8736, $body, $json);
    if ($ca === $cb && $ra === $rb) { $same++; printf("  ok   %-34s %d %5d bytes\n", $label, $cb, strlen($rb)); return; }
    $diff++;
    $p = 0; $l = min(strlen($ra), strlen($rb)); while ($p < $l && $ra[$p] === $rb[$p]) $p++;
    printf("  DIFF %-34s\n    7.4: %d %s\n    8.5: %d %s\n", $label, $ca, substr($ra, max(0, $p - 80), 200), $cb, substr($rb, max(0, $p - 80), 200));
}

echo "=== Directory\n";
$dom = 'astpp.example.com';
foreach (array('1159375007', '8939266115', '6045428945', '4727985745', 'unknown', "1' OR '1'='1", '') as $u) {
    ab("directory user=" . var_export($u, true), array('section' => 'directory', 'tag_name' => 'domain', 'key_name' => 'name',
        'key_value' => $dom, 'Event-Name' => 'REQUEST_PARAMS', 'action' => 'sip_auth', 'sip_profile' => 'default',
        'sip_user_agent' => 'Test/1.0', 'sip_auth_username' => $u, 'sip_auth_realm' => $dom, 'user' => $u, 'domain' => $dom,
        'ip' => '192.0.2.10'));
}
ab('directory without user', array('section' => 'directory', 'domain' => $dom));
ab('directory vm change-password', array('section' => 'directory', 'Event-Name' => 'CUSTOM', 'VM-Action' => 'change-password',
    'VM-User-Password' => '24680', 'VM-User' => '1159375007', 'user' => '1159375007', 'domain' => $dom));
echo "=== Configuration\n";
foreach (array('sofia.conf', 'acl.conf', 'xml_cdr.conf', '') as $k) ab("configuration $k", array('section' => 'configuration', 'key_value' => $k));
ab('configuration without key', array('section' => 'configuration'));
ab('section dialplan', array('section' => 'dialplan'));
ab('section ../../etc', array('section' => '../../../etc/passwd'));

echo "=== CDR\n";
$o13 = 'ID:1|CODE:^1.*|DESTINATION:USA|CONNECTIONCOST:0.05000|INCLUDEDSECONDS:0|CT:0|COST:0.20000|INC:60|INITIALBLOCK:30|RATEGROUP:1|MARKUP:0|CI:226|ACCID:13';
$o14 = str_replace('ACCID:13', 'ACCID:14', $o13);
$o15 = 'ID:1|CODE:^1.*|DESTINATION:USA|CONNECTIONCOST:0|INCLUDEDSECONDS:0|CT:0|COST:0.12000|INC:6|INITIALBLOCK:6|RATEGROUP:1|MARKUP:0|CI:226|ACCID:15';
$term = 'ID:1|CODE:^1.*|DESTINATION:USA|CONNECTIONCOST:0|INCLUDEDSECONDS:0|COST:0.10000|INC:1|INITIALBLOCK:1|TRUNK:1|PROVIDER:3';
$base = array('uuid' => '', 'billmsec' => '65432', 'callstart' => urlencode('2026-10-08 12:00:00'), 'end_stamp' => urlencode('2026-10-08 12:01:06'),
    'hangup_cause' => 'NORMAL_CLEARING', 'account_id' => '13', 'parent_id' => '0', 'entity_id' => '0', 'call_direction' => 'outbound',
    'calltype' => 'STANDARD', 'origination_rates' => urlencode($o13), 'termination_rates' => urlencode($term),
    'effective_destination_number' => '12125550100', 'original_caller_id_name' => 'Test', 'original_caller_id_number' => '41440000013',
    'effective_caller_id_name' => 'Test', 'effective_caller_id_number' => '41440000013', 'sip_via_host' => '192.0.2.10',
    'sip_contact_host' => '192.0.2.10', 'call_request' => '0', 'sip_user' => '1159375007', 'rate_flag' => '3',
    'force_trunk_flag' => '0', 'trunk_id' => '1', 'provider_id' => '3', 'intcall' => '0');
$cases = array(
    'answered' => array(),
    'empty rate fields' => array('origination_rates' => urlencode('ID:1|CODE:^1.*|DESTINATION:USA|CONNECTIONCOST:|INCLUDEDSECONDS:|CT:|COST:|INC:|INITIALBLOCK:|RATEGROUP:1|MARKUP:|CI:|ACCID:13'),
        'termination_rates' => urlencode('ID:1|CODE:^1.*|DESTINATION:|CONNECTIONCOST:|INCLUDEDSECONDS:|COST:|INC:|INITIALBLOCK:|TRUNK:1|PROVIDER:3')),
    'non-numeric rate fields' => array('origination_rates' => urlencode('ID:1|CODE:^1.*|DESTINATION:USA|CONNECTIONCOST:n/a|INCLUDEDSECONDS:x|CT:0|COST:abc|INC:-|INITIALBLOCK:.|RATEGROUP:1|MARKUP:0|CI:226|ACCID:13')),
    'included seconds + init block' => array('origination_rates' => urlencode(str_replace(array('INCLUDEDSECONDS:0', 'INITIALBLOCK:30'), array('INCLUDEDSECONDS:20', 'INITIALBLOCK:90'), $o13))),
    'inc 0' => array('origination_rates' => urlencode(str_replace('INC:60', 'INC:0', $o13))),
    'no answer' => array('billmsec' => '0', 'hangup_cause' => 'NO_ANSWER', 'last_bridge_hangup_cause' => 'NO_ANSWER'),
    'zero billsec normal clearing' => array('billmsec' => '0', 'last_bridge_hangup_cause' => 'USER_BUSY'),
    'billmsec empty' => array('billmsec' => ''),
    'billmsec 1001' => array('billmsec' => '1001'),
    'normal unspecified' => array('hangup_cause' => 'NORMAL_UNSPECIFIED'),
    'hangup NONE' => array('hangup_cause' => 'NONE', 'current_application_data' => 'CALL_REJECTED'),
    'error_cdr' => array('error_cdr' => '1', 'last_bridge_hangup_cause' => 'NO_ROUTE_DESTINATION', 'billmsec' => '0'),
    'reseller chain' => array('account_id' => '14', 'parent_id' => '15', 'sip_user' => '8939266115',
        'origination_rates' => urlencode($o14 . '||' . $o15)),
    'reseller chain empty reseller rates' => array('account_id' => '14', 'parent_id' => '15',
        'origination_rates' => urlencode($o14 . '||ID:1|CODE:^1.*|DESTINATION:|CONNECTIONCOST:|INCLUDEDSECONDS:|CT:|COST:|INC:|INITIALBLOCK:|RATEGROUP:1|MARKUP:|CI:|ACCID:15')),
    'did inbound' => array('calltype' => 'DID', 'call_direction' => 'inbound', 'receiver_accid' => '14', 'account_id' => '13',
        'origination_rates_did' => urlencode(str_replace('ACCID:13', 'ACCID:14', $o13)), 'effective_destination_number' => '41445550100'),
    'local' => array('calltype' => 'LOCAL', 'receiver_accid' => '13', 'account_id' => '14', 'effective_destination_number' => '1159375007',
        'origination_rates' => urlencode($o14)),
    'package' => array('package_id' => '1'),
    'callingcard with leg' => array('calltype' => 'CALLINGCARD', 'originating_leg_uuid' => 'cc-leg-1'),
    'callingcard without leg' => array('calltype' => 'CALLINGCARD'),
    'intcall' => array('intcall' => '1'),
    'force trunk' => array('force_trunk_flag' => '2'),
    'no account' => array('account_id' => ''),
    'no callstart' => array('callstart' => ''),
    'caller id injection' => array('original_caller_id_name' => urlencode("O'Brien \" %27 ' OR 1=1 -- "), 'original_caller_id_number' => "1' ; DROP TABLE cdrs; --",
        'effective_destination_number' => "1212'5550100"),
    'module_name traversal' => array('module_name' => '../../../../etc/passwd'),
);
$n = 0;
foreach ($cases as $label => $over) {
    $n++;
    $v = array_merge($base, $over);
    $v['uuid'] = sprintf('php85cdr-%02d', $n);
    $cdr = array('variables' => $v, 'callflow' => array('caller_profile' => array('caller_id_name' => 'CF', 'caller_id_number' => '41440000099')));
    ab("cdr $label", json_encode($cdr), true);
}
ab('cdr invalid json', '{"variables": ', true);
ab('cdr empty body', '', true);
echo "\nAntworten: identisch $same, abweichend $diff\n";

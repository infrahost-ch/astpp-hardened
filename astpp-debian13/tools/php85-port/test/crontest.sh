#!/bin/bash
# Cron A/B: every job runs once with the original code on PHP 7.4 (DB astpp74test) and
# once with the port on PHP 8.5 (DB astpp85test); afterwards all tables are compared
# (timestamps masked). TEST DATABASES ONLY.
set -e
export OPENSSL_CONF=/etc/astpp/openssl-legacy.cnf
# billing scenario: 14 postpaid daily, 13 postpaid monthly with invoice day today, CDRs of yesterday
mariadb astpp85test -e "
UPDATE accounts SET posttoexternal=1, sweep_id=0, generate_invoice=0, last_bill_date='2026-10-01 00:00:00', credit_limit=50 WHERE id=14;
UPDATE accounts SET posttoexternal=1, sweep_id=2, invoice_day=$(date -u +%-d), generate_invoice=0, last_bill_date='2026-09-08 00:00:00', credit_limit=50 WHERE id=13;
UPDATE cdrs SET callstart = '2026-10-07 12:00:00', end_stamp = '2026-10-07 12:01:06' WHERE uniqueid LIKE 'php85cdr%' OR uniqueid LIKE 'cc-leg%';
UPDATE reseller_cdrs SET callstart = '2026-10-07 12:00:00', end_stamp = '2026-10-07 12:01:06' WHERE uniqueid LIKE 'php85cdr%';"
mariadb -e "DROP DATABASE IF EXISTS astpp74test; CREATE DATABASE astpp74test CHARACTER SET utf8 COLLATE utf8_general_ci; GRANT ALL PRIVILEGES ON astpp74test.* TO 'astppuser'@'localhost'; GRANT ALL PRIVILEGES ON astpp74test.* TO 'astppuser'@'127.0.0.1';"
mariadb-dump --single-transaction --routines --events --triggers astpp85test | mariadb astpp74test
# the 7.4 reference web/cron code reads astpp74test during this test
cfg74=$(grep -rl --include=*.php "/var/lib/astpp85/astpp-config.conf" /opt/ASTPP74/web_interface/astpp)
sed -i "s#/var/lib/astpp85/astpp-config.conf#/var/lib/astpp74/astpp-config.conf#" $cfg74
trap 'sed -i "s#/var/lib/astpp74/astpp-config.conf#/var/lib/astpp85/astpp-config.conf#" $cfg74' EXIT
chmod 755 /var/lib/astpp74 2>/dev/null || true
for job in UpdateBalance ProcessDailyCharges BillAccountCharges GenerateInvoice LowBalance ArchiveCDRs Purge BroadcastEmail crons; do
	echo "=== $job"
	a=$( (cd /opt/ASTPP74/web_interface/astpp/cron && timeout 300 runuser -u www-data -- env OPENSSL_CONF=$OPENSSL_CONF php7.4 cron.php $job 2>&1) | tail -c 600)
	b=$( (cd /opt/ASTPP85/web_interface/astpp/cron && timeout 300 runuser -u www-data -- env OPENSSL_CONF=$OPENSSL_CONF ASTPP_DEBUG_LOG=1 php8.5 -d log_errors=1 -d error_log=/var/log/astpp/php85_cron_errors.log cron.php $job 2>&1) | tail -c 600)
	if [ "$a" == "$b" ]; then echo "  Ausgabe identisch (${#b} Zeichen)"; else echo "  Ausgabe 7.4: $a"; echo "  Ausgabe 8.5: $b"; fi
done
echo "=== Tabellen-Vergleich (Zeitstempel maskiert)"
tables=$(mariadb -N -e "SELECT table_name FROM information_schema.tables WHERE table_schema='astpp85test' AND table_type='BASE TABLE' ORDER BY 1")
nd=0
mask() { sed -E 's/[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}/TS/g'; }
for t in $tables; do
	if ! diff -q <(mariadb -N -e "SELECT * FROM astpp74test.\`$t\` ORDER BY 1" | mask) <(mariadb -N -e "SELECT * FROM astpp85test.\`$t\` ORDER BY 1" | mask) >/dev/null; then
		nd=$((nd+1)); echo "--- $t"
		diff <(mariadb -N -e "SELECT * FROM astpp74test.\`$t\` ORDER BY 1" | mask) <(mariadb -N -e "SELECT * FROM astpp85test.\`$t\` ORDER BY 1" | mask) | head -12 | cut -c1-300
	fi
done
echo "Tabellen: $(echo $tables | wc -w), unterschiedlich (nach Maskierung): $nd"
echo "=== erzeugte Rechnungen (8.5)"
mariadb -t astpp85test -e "SELECT id, number, accountid, type, from_date, to_date, generate_type, status FROM invoices ORDER BY id"
mariadb -t astpp85test -e "SELECT invoiceid, accountid, charge_type, description, debit, credit FROM invoice_details ORDER BY id" | cut -c1-200
echo "=== PHP 8.5 Cron-Log"
sed -E 's/^\[[^]]*\] //' /var/log/astpp/php85_cron_errors.log 2>/dev/null | grep -v "Deprecated" | sort | uniq -c | sort -rn | head -20 | cut -c1-250

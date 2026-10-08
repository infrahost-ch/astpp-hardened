#!/bin/bash
# Clone astpp85test -> astpp74test, run the FS A/B test, compare all tables.
set -e
mariadb -e "DROP DATABASE IF EXISTS astpp74test; CREATE DATABASE astpp74test CHARACTER SET utf8 COLLATE utf8_general_ci; GRANT ALL PRIVILEGES ON astpp74test.* TO 'astppuser'@'localhost'; GRANT ALL PRIVILEGES ON astpp74test.* TO 'astppuser'@'127.0.0.1';"
mariadb-dump --single-transaction --routines --events --triggers astpp85test | mariadb astpp74test
: > /var/log/astpp/php85_fs_errors.log; : > /var/log/astpp/php74_fs_errors.log
php8.5 /root/fstest.php
echo "=== Tabellen-Vergleich"
tables=$(mariadb -N -e "SELECT table_name FROM information_schema.tables WHERE table_schema='astpp85test' AND table_type='BASE TABLE' ORDER BY 1")
nd=0
for t in $tables; do
	a=$(mariadb -N -e "CHECKSUM TABLE astpp74test.\`$t\`" | awk '{print $2}')
	b=$(mariadb -N -e "CHECKSUM TABLE astpp85test.\`$t\`" | awk '{print $2}')
	if [ "$a" != "$b" ]; then
		nd=$((nd+1)); echo "--- $t unterscheidet sich"
		diff <(mariadb -N -e "SELECT * FROM astpp74test.\`$t\` ORDER BY 1") <(mariadb -N -e "SELECT * FROM astpp85test.\`$t\` ORDER BY 1") | head -20 | cut -c1-400
	fi
done
echo "Tabellen: $(echo $tables | wc -w), unterschiedlich: $nd"
echo "=== CDRs (8.5)"
mariadb -t astpp85test -e "SELECT uniqueid, accountid, callerid, callednum, billseconds, disposition, debit, cost, rate_cost, reseller_cost, provider_cost, calltype, call_direction FROM cdrs WHERE uniqueid LIKE 'php85cdr%' ORDER BY uniqueid" | cut -c1-260
mariadb -t astpp85test -e "SELECT uniqueid, accountid, debit, cost, reseller_id, calltype FROM reseller_cdrs WHERE uniqueid LIKE 'php85cdr%' ORDER BY uniqueid"
mariadb -t astpp85test -e "SELECT id, balance, int_balance FROM accounts WHERE id IN (3,13,14,15)"
echo "=== PHP-Logs"
echo "8.5:"; sort /var/log/astpp/php85_fs_errors.log | uniq -c | sort -rn | head -20 | cut -c1-250
echo "7.4:"; sort /var/log/astpp/php74_fs_errors.log | uniq -c | sort -rn | head -10 | cut -c1-250

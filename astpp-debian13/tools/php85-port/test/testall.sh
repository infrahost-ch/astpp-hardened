#!/bin/bash
# Full regression run against the PHP 8.5 test instance (test DB only).
cd /root
systemctl reload php8.5-fpm; sleep 2
: > /var/log/astpp/php85_errors.log
echo "##### Admin-Crawl"
timeout 2400 php8.5 astpp85-crawl.php 3000 > crawl85.txt 2>&1
sed -n "/=== .* URLs/,/=== Fehler/p" crawl85.txt | grep -v "^$"
echo "--- Fatal / Fehlerseiten:"; grep -a "Uncaught\|Fatal\|PAGE SHOWS" crawl85.txt | grep "^\[" | cut -c1-220
echo "--- astpp_n/astpp_div/TypeError/DivisionByZero im Log:"; grep -a "astpp_n\|astpp_div\|TypeError\|DivisionByZero\|Unsupported operand" /var/log/astpp/php85_errors.log | sort | uniq -c | head -20 | cut -c1-250
echo "##### Formulare"
timeout 2400 php8.5 astpp85-forms.php 3000 > forms85.txt 2>&1
sed -n "/=== Zusammenfassung/,\$p" forms85.txt
grep -E "^(FATAL|HTTP5|ERRPAGE)" -A4 forms85.txt | cut -c1-240
echo "##### A/B Admin"
timeout 3000 php8.5 ab_compare.php crawl85.txt > ab.txt 2>&1
sed -n 3p ab.txt
grep -v "^    " ab.txt | sed -n 4,200p
echo "##### Portale"
bash /root/portals.sh 2>&1 | grep -v "Undefined property: CI::\|Undefined variable\|Undefined array key\|array offset on\|foreach() argument"
echo "##### fertig"

#!/bin/bash
# Crawl + A/B comparison of the customer and reseller portals (test DB only).
cd /root
while IFS=$'\t' read -r label number pass; do
	[ -n "$number" ] || continue
	echo "################ $label ($number)"
	from=$(wc -l < /var/log/nginx/astpp85_access.log)
	: > /var/log/astpp/php85_errors.log
	: > /var/log/astpp/php74_errors.log
	timeout 2400 php8.5 astpp85-crawl.php 3000 --user="$number" --pass="$pass" > "crawl85-$label.txt" 2>&1
	echo "=== Crawl:"; sed -n "/=== .* URLs/,/=== Fehler/p" "crawl85-$label.txt" | grep -v "^$"
	grep -a "LOGIN FAILED" "crawl85-$label.txt"
	echo "--- Fatal / Fehlerseiten:"; grep -a "Uncaught\|Fatal\|PAGE SHOWS" "crawl85-$label.txt" | grep "^\[" | cut -c1-220
	echo "--- PHP 8.5 Log (eindeutig):"; sed -E 's/^\[[^]]*\] //' /var/log/astpp/php85_errors.log | sort | uniq -c | sort -rn | head -20 | cut -c1-220
	timeout 3000 php8.5 ab_compare.php "crawl85-$label.txt" --user="$number" --pass="$pass" --log-from="$from" > "ab-$label.txt" 2>&1
	echo "=== A/B:"; head -2 "ab-$label.txt"; sed -n 3p "ab-$label.txt"
	grep -v "^    " "ab-$label.txt" | sed -n 4,200p
	echo "--- PHP 7.4 Log (eindeutig):"; sed -E 's/^\[[^]]*\] //' /var/log/astpp/php74_errors.log | sort | uniq -c | sort -rn | head -10 | cut -c1-220
done < /root/astpp85-testusers.txt

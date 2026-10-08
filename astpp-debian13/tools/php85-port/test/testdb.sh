#!/bin/bash
# Isolated test database for the PHP 8.5 test instance (/opt/ASTPP85).
set -e
cd /opt/ASTPP85
git add -A >/dev/null
git commit -q -m "PHP 8.5 port: work in progress" && echo "WIP committed: $(git rev-parse --short HEAD)"

# 1) test instance reads its own config -> own database (TEST-ONLY commit, reverted later)
files=$(grep -rl --include=*.php --include=*.sh "/var/lib/astpp/astpp-config.conf" web_interface/astpp freeswitch | grep -v "^web_interface/astpp/assets/")
sed -i "s#/var/lib/astpp/astpp-config.conf#/var/lib/astpp85/astpp-config.conf#g" $files
git add -A >/dev/null
git commit -q -m "TEST-ONLY: use /var/lib/astpp85 (database astpp85test) - must be reverted" && echo "TEST-ONLY committed: $(git rev-parse --short HEAD) ($(echo "$files" | wc -l) Dateien)"

# 2) config copy pointing to astpp85test (same keys -> same password encryption)
install -d -o root -g www-data -m 751 /var/lib/astpp85
sed "s/^dbname = astpp$/dbname = astpp85test/" /var/lib/astpp/astpp-config.conf > /var/lib/astpp85/astpp-config.conf
chown root:www-data /var/lib/astpp85/astpp-config.conf; chmod 640 /var/lib/astpp85/astpp-config.conf
grep "^dbname" /var/lib/astpp85/astpp-config.conf

# 3) clone the database (incl. routines, triggers, events)
mariadb -e "DROP DATABASE IF EXISTS astpp85test; CREATE DATABASE astpp85test CHARACTER SET utf8 COLLATE utf8_general_ci; GRANT ALL PRIVILEGES ON astpp85test.* TO 'astppuser'@'localhost'; GRANT ALL PRIVILEGES ON astpp85test.* TO 'astppuser'@'127.0.0.1';"
mariadb-dump --single-transaction --routines --events --triggers astpp | mariadb astpp85test
echo "Tabellen in astpp85test: $(mariadb -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='astpp85test'")"
# The clone must NOT be able to control the live FreeSWITCH: pages like
# freeswitch/fssipprofile_action/stop/1 send ESL commands to every server in this table
# (happened once during the port - the live SIP profile was stopped).
mariadb astpp85test -e "UPDATE freeswich_servers SET freeswitch_host='127.0.0.1', freeswitch_port='1', freeswitch_password='test-db-no-esl'"

# 4) php 8.5 test pools may read the new config dir
sed -i "s#/var/lib/astpp/:#/var/lib/astpp/:/var/lib/astpp85/:/proc/version:#" /etc/php/8.5/fpm/pool.d/astpp85.conf
sed -i "s#/var/lib/astpp/:#/var/lib/astpp/:/var/lib/astpp85/:#" /etc/php/8.5/fpm/pool.d/astpp85-fs.conf
grep -h open_basedir /etc/php/8.5/fpm/pool.d/astpp85*.conf | cut -c1-140
systemctl restart php8.5-fpm

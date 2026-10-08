#!/bin/bash
# TEMPORARY: PHP 7.4 reference for the FreeSWITCH endpoints (original code, own DB astpp74test).
set -e
# 1) config -> astpp74test (same keys)
install -d -o root -g www-data -m 751 /var/lib/astpp74
sed "s/^dbname = astpp85test$/dbname = astpp74test/" /var/lib/astpp85/astpp-config.conf > /var/lib/astpp74/astpp-config.conf
chown root:www-data /var/lib/astpp74/astpp-config.conf; chmod 640 /var/lib/astpp74/astpp-config.conf
grep "^dbname" /var/lib/astpp74/astpp-config.conf
sed -i "s#/var/lib/astpp85/astpp-config.conf#/var/lib/astpp74/astpp-config.conf#" /opt/ASTPP74/freeswitch/fs/lib/astpp.db.php
grep -n "astpp-config" /opt/ASTPP74/freeswitch/fs/lib/astpp.db.php
ln -sfn /opt/ASTPP74/freeswitch/fs /var/www/html/fs74

# 2) php 7.4 pool
cat > /etc/php/7.4/fpm/pool.d/astpp74-fs.conf <<'EOF'
; TEMPORARY reference pool (PHP 7.4, original code) for the A/B test of the FreeSWITCH endpoints
[astpp74-fs]
user = www-data
group = www-data
listen = /run/php/php7.4-fpm-astpp74-fs.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 8
request_terminate_timeout = 60
catch_workers_output = yes
decorate_workers_output = no
security.limit_extensions = .php
env[PATH] = /usr/local/bin:/usr/bin:/bin
php_admin_value[error_log] = /var/log/astpp/php74_fs_errors.log
php_admin_flag[log_errors] = on
php_admin_flag[display_errors] = off
php_admin_value[memory_limit] = 256M
php_admin_value[open_basedir] = /opt/ASTPP74/freeswitch/fs/:/var/www/html/fs74/:/var/lib/astpp74/:/var/log/astpp/:/tmp/
EOF
systemctl reload php7.4-fpm

# 3) nginx vhost 127.0.0.1:8737
cat > /etc/nginx/sites-available/fs74-ref.conf <<'EOF'
# TEMPORARY: FreeSWITCH endpoint reference (PHP 7.4, original code, DB astpp74test)
server {
    listen 127.0.0.1:8737;
    server_name localhost;
    root /var/www/html/fs74;
    access_log /var/log/nginx/fs74_access.log;
    error_log  /var/log/nginx/fs74_error.log;
    allow 127.0.0.1; deny all;
    location ~ ^/(index|cdr)\.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php7.4-fpm-astpp74-fs.sock;
    }
    location / { return 404; }
}
EOF
ln -sfn /etc/nginx/sites-available/fs74-ref.conf /etc/nginx/sites-enabled/fs74-ref.conf
nginx -t 2>&1 | tail -1 && systemctl reload nginx
touch /var/log/astpp/php74_fs_errors.log; chown www-data:adm /var/log/astpp/php74_fs_errors.log
sleep 1
for p in 8736 8737; do echo -n "$p: "; curl -s -d "section=configuration&key_value=acl.conf" http://127.0.0.1:$p/index.php | head -c 120; echo; done

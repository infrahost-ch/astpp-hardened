#!/bin/bash
# Reference instance: baseline code (no PHP 8 changes) on PHP 7.4 against the same
# test database - to tell PHP 8 regressions from upstream bugs.
set -e
cd /opt/ASTPP85
BASE=$(git log --format=%h --grep="^baseline" | tail -1)
rm -rf /opt/ASTPP74
git worktree prune
git worktree add -f --detach /opt/ASTPP74 "$BASE" >/dev/null 2>&1
cd /opt/ASTPP74
files=$(grep -rl --include=*.php --include=*.sh "/var/lib/astpp/astpp-config.conf" web_interface/astpp freeswitch | grep -v "^web_interface/astpp/assets/")
sed -i "s#/var/lib/astpp/astpp-config.conf#/var/lib/astpp85/astpp-config.conf#g" $files
# writable dirs like the real instance
for d in upload attachments invoices database_backup language assets/Rates_File assets/captcha application/cache application/logs application/libraries/html2pdf/_tcpdf_5.0.002/cache; do
	install -d web_interface/astpp/$d; chown -R www-data:www-data web_interface/astpp/$d
done
ln -sfn /opt/ASTPP74/web_interface/astpp /var/www/html/astpp74
echo "ASTPP74 = $(git -C /opt/ASTPP74 log --oneline -1)"

P=/etc/php/7.4/fpm/pool.d/astpp74.conf
sed -e "s#^\[astpp\]#[astpp74]#" -e "s#/run/php/php7.4-fpm-astpp.sock#/run/php/php7.4-fpm-astpp74.sock#" \
    -e "s#/opt/ASTPP/#/opt/ASTPP74/#g" -e "s#/var/lib/astpp/:#/var/lib/astpp/:/var/lib/astpp85/:#" \
    -e "s#php_errors.log#php74_errors.log#" /etc/php/7.4/fpm/pool.d/astpp.conf > $P
install -o www-data -g adm -m 640 /dev/null /var/log/astpp/php74_errors.log
systemctl restart php7.4-fpm

cat > /etc/nginx/sites-available/astpp74-ref.conf <<'EOF'
# TEMPORAER: Referenz (unveraenderter Code, PHP 7.4) fuer den A/B-Vergleich, nur 127.0.0.3
server {
    listen 127.0.0.3:443 ssl;
    server_name astpp.example.com;
    ssl_certificate     /etc/letsencrypt/live/astpp.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/astpp.example.com/privkey.pem;
    root /var/www/html/astpp74;
    access_log /var/log/nginx/astpp74_access.log;
    error_log  /var/log/nginx/astpp74_error.log;
    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
        fastcgi_param PATH_INFO "";
        fastcgi_param HTTPS on;
        fastcgi_pass unix:/run/php/php7.4-fpm-astpp74.sock;
        fastcgi_read_timeout 300;
    }
    location ~ \.php$ { return 404; }
    location / { try_files $uri $uri/ /index.php; }
}
EOF
ln -sfn /etc/nginx/sites-available/astpp74-ref.conf /etc/nginx/sites-enabled/astpp74-ref.conf
nginx -t 2>&1 | tail -1
systemctl reload nginx
curl -sk -o /dev/null -w "Referenz PHP 7.4: HTTP %{http_code}\n" --resolve astpp.example.com:443:127.0.0.3 https://astpp.example.com/login/login

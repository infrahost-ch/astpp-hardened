#!/usr/bin/env bash
# =============================================================================
#  ASTPP (astpp-debian13) - bestehende Installation von PHP 7.4 auf PHP 8.5 umstellen
#
#  Fuer Server, die mit einer frueheren Version dieses Installers (PHP 7.4)
#  eingerichtet wurden. Neuinstallationen mit install.sh laufen direkt auf 8.5.
#
#  Ablauf: Sicherung (Code, Konfiguration, Datenbank) -> PHP 8.5 installieren ->
#  patches/astpp-v6-php85.patch auf /opt/ASTPP anwenden -> PHP-8.5-Pools ->
#  nginx, Cron und FreeSWITCH-Abhaengigkeit umstellen -> Funktionspruefung ->
#  php7.4-fpm stoppen (bleibt installiert, fuer ein Rollback).
#
#  Aufruf (als root, aus dem Verzeichnis astpp-debian13):
#      bash tools/astpp-upgrade-php85.sh
#  Rollback: siehe Ausgabe am Ende bzw. README.md
# =============================================================================
set -Eeuo pipefail
umask 022

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OLD="7.4"
NEW="8.5"
ASTPP_SRC="/opt/ASTPP"
ASTPP_WEB="${ASTPP_SRC}/web_interface/astpp"
ASTPP_CONF_DIR="/var/lib/astpp"
ASTPP_LOG_DIR="/var/log/astpp"
OPENSSL_LEGACY_CNF="/etc/astpp/openssl-legacy.cnf"
FS_SHARE="/usr/share/freeswitch"
PATCH="${SCRIPT_DIR}/patches/astpp-v6-php85.patch"
TS="$(date +%Y%m%d-%H%M%S)"
BACKUP_DIR="/root/astpp-pre-php85-${TS}"
OLD_SOCK="/run/php/php${OLD}-fpm-astpp.sock"
OLD_FS_SOCK="/run/php/php${OLD}-fpm-astpp-fs.sock"
NEW_SOCK="/run/php/php${NEW}-fpm-astpp.sock"
NEW_FS_SOCK="/run/php/php${NEW}-fpm-astpp-fs.sock"

C_OK=$'\e[32m'; C_WARN=$'\e[33m'; C_ERR=$'\e[31m'; C_HEAD=$'\e[1;36m'; C_OFF=$'\e[0m'
log()  { printf '%s[%s]%s %s\n' "${C_OK}" "$(date +%H:%M:%S)" "${C_OFF}" "$*"; }
warn() { printf '%s[WARNUNG]%s %s\n' "${C_WARN}" "${C_OFF}" "$*" >&2; }
die()  { printf '%s[FEHLER]%s %s\n' "${C_ERR}" "${C_OFF}" "$*" >&2; exit 1; }
step() { printf '\n%s==> %s%s\n' "${C_HEAD}" "$*" "${C_OFF}"; }
trap 'die "Abbruch in Zeile ${LINENO}: ${BASH_COMMAND} - bisher umgestellte Teile siehe Rollback in README.md, Sicherung: ${BACKUP_DIR}"' ERR

# render TEMPLATE DEST KEY VALUE [KEY VALUE ...] - literal @KEY@ replacement (wie install.sh)
render() {
	local src="$1" dst="$2" content key val
	shift 2
	content="$(<"${src}")"
	while [ "$#" -ge 2 ]; do
		key="@$1@"; val="$2"; shift 2
		content="${content//"${key}"/"${val}"}"
	done
	if grep -q '@[A-Z0-9_]\+@' <<<"${content}"; then
		die "Template ${src}: nicht ersetzte Platzhalter"
	fi
	printf '%s\n' "${content}" >"${dst}"
}
apt_install() { DEBIAN_FRONTEND=noninteractive apt-get install -y -o Dpkg::Options::=--force-confold "$@"; }
pkg_available() { apt-cache show "$1" >/dev/null 2>&1; }

# ---------------------------------------------------------------- preflight ---
step "Vorabpruefung"
[ "$(id -u)" -eq 0 ] || die "Bitte als root ausfuehren."
[ -f "${PATCH}" ] || die "${PATCH} fehlt."
[ -d "${ASTPP_SRC}/.git" ] || die "${ASTPP_SRC} ist keine Installation dieses Installers."
[ -f "/etc/php/${OLD}/fpm/pool.d/astpp.conf" ] || die "PHP-${OLD}-Pool astpp.conf nicht gefunden - bereits umgestellt?"
ALREADY=no
if git -C "${ASTPP_SRC}" apply --whitespace=nowarn --check "${PATCH}" 2>/dev/null; then
	log "Patch passt auf ${ASTPP_SRC}"
elif git -C "${ASTPP_SRC}" apply --whitespace=nowarn --check -R "${PATCH}" 2>/dev/null; then
	ALREADY=yes
	log "Patch ist in ${ASTPP_SRC} bereits angewendet"
else
	die "Patch passt nicht auf ${ASTPP_SRC} (lokale Aenderungen? git -C ${ASTPP_SRC} status)"
fi

# ------------------------------------------------------------------- backup ---
step "Sicherung nach ${BACKUP_DIR}"
install -d -m 700 "${BACKUP_DIR}"
tar -C / -czf "${BACKUP_DIR}/opt-ASTPP.tar.gz" --exclude='opt/ASTPP/web_interface/astpp/assets/captcha/*' opt/ASTPP
tar -C / -czf "${BACKUP_DIR}/config.tar.gz" etc/nginx/sites-available etc/php/${OLD}/fpm/pool.d etc/cron.d/astpp \
	etc/systemd/system/freeswitch.service.d $( [ -d /etc/php/${NEW} ] && echo etc/php/${NEW} )
mariadb-dump --single-transaction --routines --events --triggers astpp | gzip >"${BACKUP_DIR}/astpp-db.sql.gz"
log "Code, Konfiguration und Datenbank gesichert ($(du -sh "${BACKUP_DIR}" | cut -f1))"

# ---------------------------------------------------------------- PHP 8.5 ---
step "PHP ${NEW} installieren"
apt-get update -q
apt_install "php${NEW}-fpm" "php${NEW}-cli" "php${NEW}-common" "php${NEW}-mysql" "php${NEW}-xml" "php${NEW}-curl" \
	"php${NEW}-gd" "php${NEW}-mbstring" "php${NEW}-readline" "php${NEW}-zip" "php${NEW}-bcmath"
for p in "php${NEW}-imap" "php${NEW}-imagick"; do
	if pkg_available "${p}"; then apt_install "${p}" || warn "${p} konnte nicht installiert werden (optional)"; fi
done
if dpkg -l "php${NEW}-intl" 2>/dev/null | grep -q '^ii'; then phpdismod -v "${NEW}" intl; fi
OPENSSL_CONF="${OPENSSL_LEGACY_CNF}" "php${NEW}" -r \
	'exit(openssl_encrypt("x", "BF-ECB", "0123456789abcdef", OPENSSL_RAW_DATA) === false ? 1 : 0);' \
	|| die "Blowfish (OpenSSL legacy provider) ist in PHP ${NEW} nicht verfuegbar."
install -m 644 "${SCRIPT_DIR}/files/php/astpp.ini" "/etc/php/${NEW}/mods-available/astpp.ini"
phpenmod -v "${NEW}" astpp
log "$("php${NEW}" -v | head -1)"

# ------------------------------------------------------------ ASTPP-Patch ---
if [ "${ALREADY}" = "no" ]; then
	step "PHP-8.5-Portierung auf ${ASTPP_SRC} anwenden"
	git -C "${ASTPP_SRC}" apply --whitespace=nowarn "${PATCH}"
fi
rm -f "${ASTPP_WEB}/index.html"   # Upstream-Kopie der Google-Startseite
bad=0; n=0
while IFS= read -r f; do
	case "${f}" in *.php) n=$((n + 1)); "php${NEW}" -l "${ASTPP_SRC}/${f}" >/dev/null 2>&1 || { echo "PHP-Syntaxfehler: ${f}"; bad=1; } ;; esac
done < <(git -C "${ASTPP_SRC}" diff --name-only; git -C "${ASTPP_SRC}" ls-files --others --exclude-standard -- '*.php')
[ "${bad}" -eq 0 ] || die "Syntaxfehler unter PHP ${NEW} - Abbruch (nginx laeuft noch auf PHP ${OLD})."
log "${n} PHP-Dateien unter PHP ${NEW} syntaktisch geprueft"
chown root:root "${ASTPP_WEB}/application/php7compat.php" "${ASTPP_SRC}/freeswitch/fs/lib/astpp.php7compat.php"
chmod 644 "${ASTPP_WEB}/application/php7compat.php" "${ASTPP_SRC}/freeswitch/fs/lib/astpp.php7compat.php"

# ---------------------------------------------------------------- php-fpm ---
step "PHP-${NEW}-FPM-Pools einrichten"
pool_dir="/etc/php/${NEW}/fpm/pool.d"
[ -f "${pool_dir}/www.conf" ] && mv "${pool_dir}/www.conf" "${pool_dir}/www.conf.disabled"
pm_max="$(awk -F' *= *' '/^pm.max_children/ {print $2}' "/etc/php/${OLD}/fpm/pool.d/astpp.conf")"
pm_fs_max="$(awk -F' *= *' '/^pm.max_children/ {print $2}' "/etc/php/${OLD}/fpm/pool.d/astpp-fs.conf")"
obd_line="$(grep '^php_admin_value\[open_basedir\]' "/etc/php/${OLD}/fpm/pool.d/astpp.conf" || true)"
if [ -n "${obd_line}" ]; then
	# /proc/version: Kernel-Version im Admin-Dashboard
	grep -q '/proc/version' <<<"${obd_line}" || obd_line="${obd_line}:/proc/version"
else
	obd_line="; open_basedir deaktiviert (PHP_OPEN_BASEDIR=no)"
fi
render "${SCRIPT_DIR}/files/php/pool-astpp.conf" "${pool_dir}/astpp.conf" \
	PHP_SOCK "${NEW_SOCK}" PM_MAX_CHILDREN "${pm_max:-16}" OPENSSL_LEGACY_CNF "${OPENSSL_LEGACY_CNF}" OPEN_BASEDIR_LINE "${obd_line}"
render "${SCRIPT_DIR}/files/php/pool-astpp-fs.conf" "${pool_dir}/astpp-fs.conf" \
	PHP_FS_SOCK "${NEW_FS_SOCK}" PM_FS_MAX_CHILDREN "${pm_fs_max:-26}"
install -d "/etc/systemd/system/php${NEW}-fpm.service.d"
render "${SCRIPT_DIR}/files/systemd/php-fpm-astpp.conf" "/etc/systemd/system/php${NEW}-fpm.service.d/astpp.conf" \
	OPENSSL_LEGACY_CNF "${OPENSSL_LEGACY_CNF}"
for s in "${NEW_SOCK}" "${NEW_FS_SOCK}"; do
	if grep -rqs "^listen *= *${s}\$" --exclude=astpp.conf --exclude=astpp-fs.conf "${pool_dir}"; then
		die "Ein anderer PHP-${NEW}-Pool benutzt bereits ${s}: $(grep -rls "^listen *= *${s}\$" --exclude=astpp.conf --exclude=astpp-fs.conf "${pool_dir}")"
	fi
done
systemctl daemon-reload
"php-fpm${NEW}" -t
systemctl enable "php${NEW}-fpm"
systemctl restart "php${NEW}-fpm"
for s in "${NEW_SOCK}" "${NEW_FS_SOCK}"; do
	for i in $(seq 1 20); do [ -S "${s}" ] && break; sleep 0.5; done
	[ -S "${s}" ] || die "Socket ${s} fehlt - php${NEW}-fpm pruefen."
done

# ------------------------------------------------- nginx, cron, FreeSWITCH ---
step "nginx, Cron und FreeSWITCH auf PHP ${NEW} umstellen"
sed -i "s#${OLD_SOCK}#${NEW_SOCK}#" /etc/nginx/sites-available/astpp.conf
sed -i "s#${OLD_FS_SOCK}#${NEW_FS_SOCK}#" /etc/nginx/sites-available/astpp-fs.conf
nginx -t
systemctl reload nginx
sed -i "s#/usr/bin/php${OLD} #/usr/bin/php${NEW} #" /etc/cron.d/astpp
if [ -f /etc/systemd/system/freeswitch.service.d/astpp.conf ]; then
	sed -i "s#php${OLD}-fpm.service#php${NEW}-fpm.service#g" /etc/systemd/system/freeswitch.service.d/astpp.conf
	systemctl daemon-reload
fi

# ------------------------------------------------------------ healthcheck ---
step "Funktionspruefung"
sleep 1
fqdn="$(awk -F'[/:]+' '/^base_url/ {print $2}' "${ASTPP_CONF_DIR}/astpp-config.conf" 2>/dev/null || true)"
fqdn="${fqdn:-$(hostname -f)}"
ok=yes
page="$(curl -sk --resolve "${fqdn}:443:127.0.0.1" "https://${fqdn}/login/login" || true)"
if grep -q 'name="password"' <<<"${page}"; then log "Login-Seite wird von PHP ${NEW} ausgeliefert"; else warn "Login-Seite nicht erreichbar"; ok=no; fi
if curl -fsS "http://127.0.0.1:8735/index.php?section=configuration&key_value=acl.conf" | grep -q '<document'; then
	log "xml_curl-Endpunkt liefert Konfiguration"
else
	warn "xml_curl-Endpunkt (127.0.0.1:8735) liefert kein XML"; ok=no
fi
if (cd "${ASTPP_WEB}/cron" && runuser -u www-data -- env OPENSSL_CONF="${OPENSSL_LEGACY_CNF}" "php${NEW}" -l cron.php >/dev/null); then
	log "Cron nutzt jetzt /usr/bin/php${NEW}"
fi
if [ "${ok}" != "yes" ]; then
	die "Funktionspruefung fehlgeschlagen - PHP ${OLD} laeuft noch; Rollback siehe README.md"
fi

systemctl stop "php${OLD}-fpm"
systemctl disable "php${OLD}-fpm" >/dev/null 2>&1 || true
log "php${OLD}-fpm gestoppt und deaktiviert (Pakete bleiben fuer ein Rollback installiert)"

cat <<EOF

${C_HEAD}Umstellung auf PHP ${NEW} abgeschlossen.${C_OFF}
  Sicherung:  ${BACKUP_DIR}
  Logs:       ${ASTPP_LOG_DIR}/php_errors.log, php_fs_errors.log, cron.log

  Rollback (falls noetig):
    systemctl enable --now php${OLD}-fpm
    sed -i 's#${NEW_SOCK}#${OLD_SOCK}#' /etc/nginx/sites-available/astpp.conf
    sed -i 's#${NEW_FS_SOCK}#${OLD_FS_SOCK}#' /etc/nginx/sites-available/astpp-fs.conf
    sed -i 's#/usr/bin/php${NEW} #/usr/bin/php${OLD} #' /etc/cron.d/astpp
    git -C ${ASTPP_SRC} apply -R --whitespace=nowarn ${PATCH}
    nginx -t && systemctl reload nginx && systemctl stop php${NEW}-fpm

  Wenn alles laeuft, PHP ${OLD} spaeter entfernen:  apt purge 'php${OLD}*'
EOF

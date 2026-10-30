#!/usr/bin/env bash
# =============================================================================
#  ASTPP (astpp-debian13) - bestehende Installation auf den Stand von astpp/ bringen
#
#  Fuer Server, die mit install.sh eingerichtet wurden (auch mit aelteren Versionen,
#  die Upstream-Code + Patches installiert haben). Ablauf:
#    1. Syntaxpruefung des Quellbaums
#    2. Abgleich planen und pruefen: Dateien, die am Server geaendert oder hinzugefuegt
#       wurden und ueberschrieben bzw. geloescht wuerden, fuehren zum Abbruch
#    3. Sicherung: /opt/ASTPP (tar), Datenbank (mariadb-dump), nginx-Site
#    4. Datenbank-Anpassungen des Forks (database/astpp-fork-*.sql aus dem Quellbaum, vor dem
#       Code, damit neuer Code neue Spalten schon vorfindet), dann rsync nach /opt/ASTPP -
#       Laufzeitdaten (Uploads, Rechnungen, Logs, ...) bleiben
#    5. Rechte, nginx-Site aus der Vorlage (FQDN/Zertifikat bleiben), fail2ban-Filter,
#       Sync-Werkzeug der Admin-Freigabe (nur wenn installiert),
#       PHP-FPM neu laden
#    6. Funktionspruefung (Weboberflaeche, FreeSWITCH-xml_curl, Admin-Seiten)
#    7. Stand in /opt/ASTPP/.git festhalten ("git -C /opt/ASTPP status" zeigt spaeter
#       jede Aenderung am Server, die nicht aus dem Fork kommt)
#
#  Aufruf (als root, im Checkout des Repositories):
#      bash astpp-debian13/tools/astpp-deploy.sh
#  Optionen (Umgebung):
#      DRY_RUN=yes    nur anzeigen, was sich aendern wuerde
#      YES=yes        ohne Rueckfrage
#      FORCE=yes      lokale Aenderungen/Zusatzdateien trotzdem ueberschreiben/loeschen
#      DB_BACKUP=no   keine Datenbanksicherung
#      ASTPP_TREE=..  anderer Quellbaum (Standard: astpp/ neben astpp-debian13/, sonst
#                     der Baum, in dem astpp-debian13/ liegt)
#      ASTPP_SRC=..   Probe-Deploy in eine Kopie (z.B. cp -a /opt/ASTPP /root/kopie) -
#                     dann ohne nginx/PHP-FPM/Funktionspruefung
#      BACKUP_ROOT=.. Sicherungsverzeichnis (Standard /var/backups/astpp)
# =============================================================================
set -Eeuo pipefail
umask 022
export LC_ALL=C.UTF-8

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_VER="8.5"
PHP_SOCK="/run/php/php${PHP_VER}-fpm-astpp.sock"
ASTPP_SRC="${ASTPP_SRC:-/opt/ASTPP}"
ASTPP_WEB="${ASTPP_SRC}/web_interface/astpp"
# nginx, PHP-FPM und die Funktionspruefung gehoeren nur zur echten Installation; ein anderes
# Ziel (ASTPP_SRC=/root/kopie) ist ein Probe-Deploy nur des Codes
SERVICES=yes
[ "${ASTPP_SRC}" = "/opt/ASTPP" ] || SERVICES=no
ASTPP_CONFIG="${ASTPP_CONFIG:-/var/lib/astpp/astpp-config.conf}"
NGINX_SITE="/etc/nginx/sites-available/astpp.conf"
BACKUP_ROOT="${BACKUP_ROOT:-/var/backups/astpp}"
DRY_RUN="${DRY_RUN:-no}"
YES="${YES:-no}"
FORCE="${FORCE:-no}"
DB_BACKUP="${DB_BACKUP:-yes}"
TS="$(date +%Y%m%d-%H%M%S)"
# Pfade, die frueher zur Laufzeit beschrieben wurden und im Fork entfallen sind
OBSOLETE_PATHS=(web_interface/astpp/application/libraries/html2pdf/_tcpdf_5.0.002/)

C_OK=$'\e[32m'; C_WARN=$'\e[33m'; C_ERR=$'\e[31m'; C_HEAD=$'\e[1;36m'; C_OFF=$'\e[0m'
log()  { printf '%s[%s]%s %s\n' "${C_OK}" "$(date +%H:%M:%S)" "${C_OFF}" "$*"; }
warn() { printf '%s[WARNUNG]%s %s\n' "${C_WARN}" "${C_OFF}" "$*" >&2; WARNINGS+=("$*"); }
die()  { printf '%s[FEHLER]%s %s\n' "${C_ERR}" "${C_OFF}" "$*" >&2; exit 1; }
step() { printf '\n%s==> %s%s\n' "${C_HEAD}" "$*" "${C_OFF}"; }
WARNINGS=()
DEPLOYED=no
trap 'die "Abbruch in Zeile ${LINENO}: ${BASH_COMMAND}$([ "${DEPLOYED}" = yes ] && printf " - Code ist bereits abgeglichen, Rueckweg siehe README.md (Sicherung %s)" "${BACKUP_ROOT}/*-${TS}*")"' ERR

# shellcheck source=../lib/astpp-tree.sh
. "${SCRIPT_DIR}/lib/astpp-tree.sh"

# ------------------------------------------------------------------ checks ---
preflight() {
	step "Vorabpruefung"
	[ "$(id -u)" -eq 0 ] || die "Bitte als root ausfuehren."
	[ -d "${ASTPP_WEB}" ] || die "${ASTPP_WEB} fehlt - fuer Neuinstallationen install.sh verwenden."
	[ -r "${ASTPP_CONFIG}" ] || die "${ASTPP_CONFIG} fehlt."
	local c
	for c in rsync git luac5.3 "php${PHP_VER}" mariadb-dump nginx; do
		command -v "${c}" >/dev/null || die "${c} fehlt (apt install ...)."
	done
	ASTPP_TREE="$(astpp_tree_dir)"
	REV="$(astpp_tree_revision "${ASTPP_TREE}")"
	log "Quelle: ${ASTPP_TREE} (Revision ${REV}) -> ${ASTPP_SRC}"
	astpp_lint_tree "${ASTPP_TREE}"
}

# Was wuerde der Abgleich tun - und gehen dabei Aenderungen am Server verloren?
plan() {
	step "Abgleich planen"
	local items p o mode
	items="$(astpp_sync_tree "${ASTPP_TREE}" -n -c --itemize-changes)"
	PLAN_NEW="$(grep -c '^>f+++++++++ ' <<<"${items}" || true)"
	PLAN_CHANGED="$(grep -c '^>fc' <<<"${items}" || true)"
	mapfile -t PLAN_DELETE < <(sed -n 's/^\*deleting  *//p' <<<"${items}" | grep -v '/$' || true)
	printf '%s\n' "${items}" >"/tmp/astpp-deploy-plan-${TS}.txt"
	log "neu: ${PLAN_NEW}, geaendert: ${PLAN_CHANGED}, entfernt: ${#PLAN_DELETE[@]} Dateien (Liste: /tmp/astpp-deploy-plan-${TS}.txt)"

	mode="$(git -C "${ASTPP_SRC}" config --get astpp.deploy 2>/dev/null || true)"
	local unexpected=() tracked=""
	if [ -d "${ASTPP_SRC}/.git" ]; then
		tracked="$(git -C "${ASTPP_SRC}" -c core.quotePath=false ls-files)"
	fi
	# geloeschte Dateien, die weder aus dem letzten Deploy noch aus dem Upstream-Checkout stammen
	for p in "${PLAN_DELETE[@]}"; do
		for o in "${OBSOLETE_PATHS[@]}"; do [[ "${p}" == "${o}"* ]] && continue 2; done
		grep -qxF -- "${p}" <<<"${tracked}" || unexpected+=("${p}")
	done
	local problems=0 dirty=""
	if [ "${#unexpected[@]}" -gt 0 ]; then
		printf '%s Datei(en) in %s stammen nicht aus dem Fork und wuerden geloescht (Addon installiert? manuell angelegt?):\n' "${#unexpected[@]}" "${ASTPP_SRC}" >&2
		printf '  %s\n' "${unexpected[@]:0:50}" >&2
		[ "${#unexpected[@]}" -gt 50 ] && printf '  ... (%d weitere)\n' "$((${#unexpected[@]} - 50))" >&2
		problems=1
	fi
	# Aenderungen seit dem letzten Deploy (nur wenn das Ziel schon von hier verwaltet wird)
	if [ "${mode}" = "true" ]; then
		dirty="$(git -C "${ASTPP_SRC}" -c core.quotePath=false status --porcelain --untracked-files=all)"
		if [ -n "${dirty}" ]; then
			printf 'In %s wurde seit dem letzten Deploy geaendert (Details: git -C %s diff):\n' "${ASTPP_SRC}" "${ASTPP_SRC}" >&2
			printf '%s\n' "${dirty}" | head -50 | sed 's/^/  /' >&2
			problems=1
		fi
	elif [ -d "${ASTPP_SRC}/.git" ]; then
		log "${ASTPP_SRC} ist ein Checkout des alten Installers (Upstream + Patches) - wird ab jetzt vom Deploy verwaltet."
	fi
	if [ "${problems}" -eq 1 ]; then
		[ "${FORCE}" = "yes" ] || die "Lokale Aenderungen wuerden verloren gehen (Liste oben). Erst in den Fork (astpp/) uebernehmen oder mit FORCE=yes verwerfen."
		warn "FORCE=yes: lokale Aenderungen/Zusatzdateien werden ueberschrieben bzw. geloescht (sind in der Sicherung)."
	fi
}

confirm() {
	[ "${DRY_RUN}" = "yes" ] && { log "DRY_RUN=yes - nichts geaendert."; exit 0; }
	[ "${YES}" = "yes" ] && return 0
	[ -t 0 ] || die "Ohne Terminal nur mit YES=yes."
	local a
	read -r -p "Deploy von ${REV} nach ${ASTPP_SRC} durchfuehren? [j/N] " a
	[[ "${a}" =~ ^[jJyY]$ ]] || die "Abgebrochen."
}

# ------------------------------------------------------------------ backup ---
backup() {
	step "Sicherung nach ${BACKUP_ROOT}"
	install -d -m 700 "${BACKUP_ROOT}"
	tar -C / -czf "${BACKUP_ROOT}/astpp-code-${TS}.tar.gz" "${ASTPP_SRC#/}"
	log "Code: ${BACKUP_ROOT}/astpp-code-${TS}.tar.gz ($(du -h "${BACKUP_ROOT}/astpp-code-${TS}.tar.gz" | cut -f1))"
	if [ "${DB_BACKUP}" = "yes" ]; then
		local db
		db="$(sed -n 's/^[[:space:]]*dbname[[:space:]]*=[[:space:]]*"\{0,1\}\([A-Za-z0-9_]*\)"\{0,1\}.*/\1/p' "${ASTPP_CONFIG}" | head -1)"
		[ -n "${db}" ] || die "dbname nicht in ${ASTPP_CONFIG} gefunden."
		mariadb-dump --single-transaction --quick --routines --events --triggers "${db}" \
			| gzip >"${BACKUP_ROOT}/astpp-db-${TS}.sql.gz"
		log "Datenbank ${db}: ${BACKUP_ROOT}/astpp-db-${TS}.sql.gz ($(du -h "${BACKUP_ROOT}/astpp-db-${TS}.sql.gz" | cut -f1))"
	fi
	[ -f "${NGINX_SITE}" ] && cp -a "${NGINX_SITE}" "${BACKUP_ROOT}/nginx-astpp.conf-${TS}"
	chmod 600 "${BACKUP_ROOT}"/*"-${TS}"*
}

# ------------------------------------------------------------------ deploy ---
deploy_code() {
	step "Code abgleichen"
	if [ -d "${ASTPP_SRC}/.git" ] && [ "$(git -C "${ASTPP_SRC}" config --get astpp.deploy || true)" != "true" ]; then
		# Repository des alten Installers (Upstream-Checkout) - steckt auch in der tar-Sicherung
		mv "${ASTPP_SRC}/.git" "${BACKUP_ROOT}/astpp-git-upstream-${TS}"
	fi
	PHP_ERRLOG_SIZE="$(stat -c %s /var/log/astpp/php_errors.log 2>/dev/null || echo 0)"
	DEPLOYED=yes
	astpp_sync_tree "${ASTPP_TREE}" -c
	astpp_set_permissions
	log "Code und Rechte aktualisiert"
}

# nginx-Site neu aus der Vorlage; FQDN, Zertifikat und PHP-Socket bleiben wie installiert
deploy_nginx() {
	step "nginx-Site"
	[ -f "${NGINX_SITE}" ] || { warn "${NGINX_SITE} fehlt - nginx unveraendert"; return 0; }
	local fqdn cert key sock tmp
	fqdn="$(awk '$1=="server_name" && $2!="_;" {sub(/;$/, "", $2); print $2; exit}' "${NGINX_SITE}")"
	cert="$(awk '$1=="ssl_certificate" {sub(/;$/, "", $2); c=$2} END {print c}' "${NGINX_SITE}")"
	key="$(awk '$1=="ssl_certificate_key" {sub(/;$/, "", $2); k=$2} END {print k}' "${NGINX_SITE}")"
	sock="$(sed -n 's#^[[:space:]]*fastcgi_pass unix:\([^;]*\);#\1#p' "${NGINX_SITE}" | head -1)"
	if [ -z "${fqdn}" ] || [ -z "${cert}" ] || [ -z "${key}" ] || [ -z "${sock}" ]; then
		warn "nginx-Site: FQDN/Zertifikat/Socket nicht erkannt - nginx unveraendert, Vorlage files/nginx/astpp.conf bitte von Hand abgleichen"
		return 0
	fi
	tmp="$(mktemp)"
	render "${SCRIPT_DIR}/files/nginx/astpp.conf" "${tmp}" FQDN "${fqdn}" PHP_SOCK "${sock}" SSL_CERT "${cert}" SSL_KEY "${key}"
	if cmp -s "${tmp}" "${NGINX_SITE}"; then
		rm -f "${tmp}"
		log "nginx-Site unveraendert"
		return 0
	fi
	diff -u "${NGINX_SITE}" "${tmp}" | sed 's/^/  /' || true
	install -m 644 "${tmp}" "${NGINX_SITE}"
	rm -f "${tmp}"
	# "nginx -t" passes some changes that the running master then refuses on reload (e.g. a
	# limit_req zone with a new key) - it keeps the old configuration and only logs [emerg].
	local errlog=/var/log/nginx/error.log size emerg=""
	size="$(stat -c %s "${errlog}" 2>/dev/null || echo 0)"
	if nginx -t 2>/dev/null && systemctl reload nginx; then
		sleep 1
		emerg="$(tail -c +"$((size + 1))" "${errlog}" 2>/dev/null | grep '\[emerg\]' || true)"
	else
		emerg="nginx -t fehlgeschlagen"
	fi
	if [ -z "${emerg}" ]; then
		log "nginx-Site aktualisiert (${fqdn})"
	else
		printf '  %s\n' "${emerg}" >&2
		cp -a "${BACKUP_ROOT}/nginx-astpp.conf-${TS}" "${NGINX_SITE}"
		nginx -t && systemctl reload nginx
		warn "neue nginx-Site wurde nicht uebernommen (siehe oben) - alte Site wiederhergestellt"
	fi
}

# Datenbank-Anpassungen des Forks (database/astpp-fork-*.sql, jede Anweisung wiederholbar).
# Laufen aus dem Quellbaum VOR dem Code-Abgleich: neuer Code findet neue Spalten schon vor
# (sonst scheitert z.B. das Schreiben der CDRs, bis die Spalte da ist, und die Gespraeche fehlen).
# Deshalb nur Erweiterungen, die auch der bisherige Code vertraegt (Spalten/Tabellen hinzufuegen,
# nichts entfernen oder umbenennen).
deploy_database() {
	step "Datenbank-Anpassungen des Forks"
	local db f
	db="$(sed -n 's/^[[:space:]]*dbname[[:space:]]*=[[:space:]]*"\{0,1\}\([A-Za-z0-9_]*\)"\{0,1\}.*/\1/p' "${ASTPP_CONFIG}" | head -1)"
	[ -n "${db}" ] || die "dbname nicht in ${ASTPP_CONFIG} gefunden."
	for f in "${ASTPP_TREE}"/database/astpp-fork-*.sql; do
		[ -f "${f}" ] || continue
		mariadb "${db}" <"${f}"
		log "angewendet: $(basename "${f}")"
	done
}

# fail2ban-Filter aus dem Paket (die Jail-Datei enthaelt installationsspezifische Werte und bleibt)
deploy_fail2ban() {
	step "fail2ban-Filter"
	command -v fail2ban-client >/dev/null || { warn "fail2ban nicht installiert - Filter unveraendert"; return 0; }
	local f name changed=0
	for f in "${SCRIPT_DIR}"/files/fail2ban/filter.d/*.conf; do
		name="$(basename "${f}")"
		cmp -s "${f}" "/etc/fail2ban/filter.d/${name}" && continue
		[ -f "/etc/fail2ban/filter.d/${name}" ] && cp -a "/etc/fail2ban/filter.d/${name}" "${BACKUP_ROOT}/fail2ban-${name}-${TS}"
		install -m 644 "${f}" "/etc/fail2ban/filter.d/${name}"
		log "aktualisiert: ${name}"
		changed=1
	done
	if [ "${changed}" -eq 0 ]; then
		log "fail2ban-Filter unveraendert"
	elif fail2ban-client reload >/dev/null; then
		log "fail2ban neu geladen"
	else
		warn "fail2ban-client reload fehlgeschlagen - Filter pruefen (Sicherung: ${BACKUP_ROOT}/fail2ban-*-${TS})"
	fi
}

# Sync-Werkzeug der Admin-Freigabe (O5) nur aktualisieren, wenn es installiert ist; Einstellungen
# (/etc/astpp/admin-allowlist.conf), Abschaltdatei und Cron bleiben, wie sie sind
deploy_admin_allowlist() {
	local dst=/usr/local/sbin/astpp-admin-allowlist-sync
	[ -f "${dst}" ] || return 0
	step "Admin-Freigabe (O5): Sync-Werkzeug"
	if cmp -s "${SCRIPT_DIR}/tools/astpp-admin-allowlist-sync.php" "${dst}"; then
		log "Sync-Werkzeug unveraendert"
		return 0
	fi
	cp -a "${dst}" "${BACKUP_ROOT}/astpp-admin-allowlist-sync-${TS}"
	install -m 755 "${SCRIPT_DIR}/tools/astpp-admin-allowlist-sync.php" "${dst}"
	log "Sync-Werkzeug aktualisiert (Sicherung ${BACKUP_ROOT}/astpp-admin-allowlist-sync-${TS})"
}

reload_php() {
	"php-fpm${PHP_VER}" -t 2>/dev/null || die "PHP-FPM-Konfiguration ungueltig"
	systemctl reload "php${PHP_VER}-fpm"
	log "PHP-FPM neu geladen (OPcache geleert)"
}

# ------------------------------------------------------------------ checks ---
healthcheck() {
	step "Funktionspruefung"
	local fqdn code
	fqdn="$(sed -n 's#^[[:space:]]*base_url[[:space:]]*=[[:space:]]*"\{0,1\}https\{0,1\}://\([^/"]*\).*#\1#p' "${ASTPP_CONFIG}" | head -1)"
	code="$(curl -sk -o /dev/null -w '%{http_code}' --resolve "${fqdn}:443:127.0.0.1" "https://${fqdn}/" || true)"
	[ "${code}" = "200" ] && log "Weboberflaeche antwortet (HTTP 200)" || warn "Weboberflaeche antwortet mit HTTP ${code}"
	if curl -fsS "http://127.0.0.1:8735/index.php?section=configuration&key_value=acl.conf" | grep -q '<document'; then
		log "FreeSWITCH-xml_curl liefert Konfiguration"
	else
		warn "FreeSWITCH-xml_curl (127.0.0.1:8735) liefert kein XML"
	fi
	if [ -f /root/astpp-credentials.txt ]; then
		if "php${PHP_VER}" "${SCRIPT_DIR}/tools/astpp-smoketest.php" "${fqdn}"; then
			log "Admin-Seiten und Grids ok"
		else
			warn "Smoketest meldet Probleme (Ausgabe oben)"
		fi
	else
		warn "/root/astpp-credentials.txt fehlt - Smoketest der Admin-Seiten uebersprungen"
	fi
	if fs_cli -x 'sofia status' 2>/dev/null | grep -q 'default'; then
		log "FreeSWITCH laeuft, SIP-Profil 'default' geladen"
	else
		warn "FreeSWITCH: SIP-Profil 'default' nicht aktiv - pruefen mit: fs_cli -x 'sofia status'"
	fi
	local size
	size="$(stat -c %s /var/log/astpp/php_errors.log 2>/dev/null || echo 0)"
	if [ "${size}" -gt "${PHP_ERRLOG_SIZE}" ]; then
		tail -c "$((size - PHP_ERRLOG_SIZE))" /var/log/astpp/php_errors.log | tail -20 | sed 's/^/  /'
		warn "/var/log/astpp/php_errors.log hat seit dem Deploy neue Eintraege (oben)"
	fi
}

summary() {
	step "Deploy abgeschlossen: ${REV}"
	echo "  Sicherung:  ${BACKUP_ROOT}/*-${TS}*"
	echo "  Stand:      git -C ${ASTPP_SRC} log -1   /   lokale Aenderungen: git -C ${ASTPP_SRC} status"
	echo "  Rueckweg:   systemctl stop cron; rm -rf ${ASTPP_SRC} && tar -C / -xzf ${BACKUP_ROOT}/astpp-code-${TS}.tar.gz"
	echo "              (danach systemctl reload php${PHP_VER}-fpm; systemctl start cron; nginx-Site: ${BACKUP_ROOT}/nginx-astpp.conf-${TS})"
	if [ "${#WARNINGS[@]}" -gt 0 ]; then
		echo
		printf '%sWarnungen:%s\n' "${C_WARN}" "${C_OFF}"
		printf '  - %s\n' "${WARNINGS[@]}"
		exit 1
	fi
}

main() {
	preflight
	plan
	confirm
	backup
	if [ "${SERVICES}" = "yes" ]; then
		deploy_database
	fi
	deploy_code
	if [ "${SERVICES}" = "yes" ]; then
		deploy_nginx
		deploy_fail2ban
		deploy_admin_allowlist
		reload_php
		healthcheck
	else
		log "Ziel ist nicht /opt/ASTPP - nginx, PHP-FPM und Funktionspruefung uebersprungen"
	fi
	astpp_git_record "Deploy: ASTPP-Fork ${REV} (Sicherung ${BACKUP_ROOT}/*-${TS}*)"
	summary
}

main "$@"

#!/usr/bin/env bash
# =============================================================================
#  ASTPP 6 (Community Edition) - gehaerteter Installer fuer Debian 13 "trixie"
#  Ziel: dedizierter Server (z.B. astpp.example.com)
#
#  Unterschiede zum Hersteller-Installer (install.sh aus dem ZIP):
#   - nur Debian 13; MariaDB 11.8 aus Debian statt Oracle-MySQL-Repo
#   - PHP 8.5 von packages.sury.org; ASTPP 6 (CodeIgniter 2.1) ist dafuer portiert
#     (PHP-7-Semantik ueber application/php7compat.php)
#   - ASTPP-Quellcode aus dem infrahost-Fork (Verzeichnis astpp/ dieses Repositories):
#     Upstream V6.0 + Haertung + PHP-8.5-Port + aktualisierte, selbst ausgelieferte Bibliotheken
#   - keine Telemetrie (Upstream-Installer schickte E-Mail/IP an astppbilling.org,
#     die Anwendung selbst alle Kundenkontaktdaten an dcoll.inextrix.com)
#   - kein chmod 777, Code gehoert root und ist fuer PHP nur lesbar,
#     Cronjobs laufen als www-data statt als root
#   - Schluessel/Passwoerter pro Installation zufaellig, Demo-Konten gesperrt
#   - kein ionCube, kein "apt purge php8.*", keine --no-check-certificate-Downloads
#   - nftables-Firewall, fail2ban (SSH, SIP, Web-Login), Let's-Encrypt-TLS
#
#  Aufruf (im Checkout des Repositories, astpp/ liegt neben astpp-debian13/):
#            cp install.conf.example install.conf && $EDITOR install.conf
#            bash install.sh
#  Bestehende Installation aktualisieren: tools/astpp-deploy.sh
# =============================================================================
set -Eeuo pipefail
shopt -u patsub_replacement 2>/dev/null || true
umask 022

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
[ -f "${SCRIPT_DIR}/install.conf" ] && . "${SCRIPT_DIR}/install.conf"
# shellcheck source=lib/astpp-tree.sh
. "${SCRIPT_DIR}/lib/astpp-tree.sh"

# ---------------------------------------------------------------- settings ---
ASTPP_FQDN="${ASTPP_FQDN:-}"                 # Pflicht: oeffentlicher Hostname (install.conf)
ADMIN_EMAIL="${ADMIN_EMAIL:-}"
COMPANY_NAME="${COMPANY_NAME:-}"                     # Betreiber: Firmenprofil (Rechnungen, E-Mails), leer = nicht setzen
COMPANY_WEBSITE="${COMPANY_WEBSITE:-}"
COMPANY_COUNTRY="${COMPANY_COUNTRY:-SWITZERLAND}"     # Name wie in der Tabelle countrycode
LETSENCRYPT="${LETSENCRYPT:-yes}"            # yes = Zertifikat via certbot (akzeptiert LE Subscriber Agreement)
FS_INSTALL_METHOD="${FS_INSTALL_METHOD:-auto}"  # auto | packages | source
FS_TOKEN="${FS_TOKEN:-}"                     # SignalWire Personal Access Token (nur fuer packages)
SSH_PORT="${SSH_PORT:-}"                     # leer = aus sshd-Konfiguration ermitteln
SSH_ALLOW_CIDRS="${SSH_ALLOW_CIDRS:-}"       # leer = SSH von ueberall (fail2ban schuetzt)
SIP_ALLOW_CIDRS="${SIP_ALLOW_CIDRS:-}"       # leer = SIP offen (mit Rate-Limit + fail2ban)
SIP_TLS="${SIP_TLS:-no}"                     # yes = Port 5061 zusaetzlich oeffnen
SIP_RATE="${SIP_RATE:-60}"                   # SIP-Pakete/Sekunde pro Quell-IP
SIP_BURST="${SIP_BURST:-200}"
RTP_START="${RTP_START:-16384}"
RTP_END="${RTP_END:-32768}"
PHP_OPEN_BASEDIR="${PHP_OPEN_BASEDIR:-yes}"
FORCE="${FORCE:-no}"

ASTPP_TREE="${ASTPP_TREE:-}"                 # leer = astpp/ neben astpp-debian13/
FS_VERSION="${FS_VERSION:-v1.11.3}"
SOFIA_VERSION="${SOFIA_VERSION:-v1.13.18}"
SPANDSP_VERSION="${SPANDSP_VERSION:-v3.1.1}"

PHP_VER="8.5"
PHP_SOCK="/run/php/php${PHP_VER}-fpm-astpp.sock"
PHP_FS_SOCK="/run/php/php${PHP_VER}-fpm-astpp-fs.sock"
ASTPP_SRC="/opt/ASTPP"
ASTPP_WEB="${ASTPP_SRC}/web_interface/astpp"
ASTPP_CONF_DIR="/var/lib/astpp"
ASTPP_LOG_DIR="/var/log/astpp"
ASTPP_ETC="/etc/astpp"
OPENSSL_LEGACY_CNF="${ASTPP_ETC}/openssl-legacy.cnf"
FS_ETC="/etc/freeswitch"
FS_SHARE="/usr/share/freeswitch"
CRED_FILE="/root/astpp-credentials.txt"
LOG_FILE="/var/log/astpp-install.log"
UPSTREAM_PRIVATE_KEY="8YSDaBtDHAB3EQkxPAyTz2I5DttzA9uR"

export DEBIAN_FRONTEND=noninteractive
export LC_ALL=C.UTF-8

# ----------------------------------------------------------------- helpers ---
C_OK=$'\e[32m'; C_WARN=$'\e[33m'; C_ERR=$'\e[31m'; C_HEAD=$'\e[1;36m'; C_OFF=$'\e[0m'
log()  { printf '%s[%s]%s %s\n' "${C_OK}" "$(date +%H:%M:%S)" "${C_OFF}" "$*"; }
warn() { printf '%s[WARNUNG]%s %s\n' "${C_WARN}" "${C_OFF}" "$*" >&2; WARNINGS+=("$*"); }
die()  { printf '%s[FEHLER]%s %s\n' "${C_ERR}" "${C_OFF}" "$*" >&2; exit 1; }
step() { printf '\n%s==> %s%s\n' "${C_HEAD}" "$*" "${C_OFF}"; }
WARNINGS=()
trap 'die "Abbruch in Zeile ${LINENO}: ${BASH_COMMAND}  (Details: ${LOG_FILE})"' ERR

# random alphanumeric secret (pipefail-safe)
randpw() {
	local len="${1:-24}" out=""
	while [ "${#out}" -lt "${len}" ]; do
		out+="$(openssl rand -base64 64 | tr -dc 'A-Za-z0-9')"
	done
	printf '%s' "${out:0:${len}}"
}

apt_install() { apt-get install -y -o Dpkg::Options::=--force-confold "$@"; }
pkg_available() { apt-cache show "$1" >/dev/null 2>&1; }

local_ips() { ip -o addr show scope global | awk '{split($4,a,"/"); print a[1]}'; }

# "a b c" -> "elements = { a, b, c };" for one address family (4|6)
nft_elements() {
	local family="$1" list=() c
	shift
	for c in "$@"; do
		if [ "${family}" = 6 ] && [[ "${c}" == *:* ]]; then list+=("${c}"); fi
		if [ "${family}" = 4 ] && [[ "${c}" != *:* ]]; then list+=("${c}"); fi
	done
	if [ "${#list[@]}" -gt 0 ]; then
		local IFS=','
		printf 'elements = { %s };' "${list[*]}"
	fi
}

# ---------------------------------------------------------------- preflight ---
preflight() {
	step "Vorabpruefung"
	[ "$(id -u)" -eq 0 ] || die "Bitte als root ausfuehren."
	# shellcheck disable=SC1091
	. /etc/os-release
	[ "${ID:-}" = "debian" ] && [ "${VERSION_CODENAME:-}" = "trixie" ] \
		|| die "Nur Debian 13 (trixie) wird unterstuetzt - gefunden: ${PRETTY_NAME:-unbekannt}"
	[ "$(dpkg --print-architecture)" = "amd64" ] || die "Nur amd64 wird unterstuetzt."
	[ -d /run/systemd/system ] || die "systemd wird benoetigt."
	for f in lib/astpp-tree.sh tools/astpp-postinstall.php files/nginx/astpp.conf; do
		[ -f "${SCRIPT_DIR}/${f}" ] || die "Datei fehlt im Installationspaket: ${f}"
	done
	ASTPP_TREE="$(astpp_tree_dir)"
	[[ "${ASTPP_FQDN}" =~ ^([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,}$ ]] \
		|| die "ASTPP_FQDN fehlt oder ist kein gueltiger Hostname (install.conf): ${ASTPP_FQDN}"
	if [ -e "${ASTPP_SRC}" ] && [ "${FORCE}" != "yes" ]; then
		die "${ASTPP_SRC} existiert bereits. Dieses Skript ist fuer frisch installierte Server gedacht (FORCE=yes ueberschreibt Code, NICHT die Datenbank)."
	fi
	if [ -z "${SSH_PORT}" ]; then
		SSH_PORT="$(sshd -T 2>/dev/null | awk '/^port /{print $2; exit}')"
		SSH_PORT="${SSH_PORT:-22}"
	fi
	[[ "${SSH_PORT}" =~ ^[0-9]+$ ]] || die "SSH_PORT ungueltig: ${SSH_PORT}"
	local mem_mb
	mem_mb="$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)"
	[ "${mem_mb}" -ge 1900 ] || warn "Nur ${mem_mb} MB RAM - empfohlen sind mindestens 4 GB."
	case "${FS_INSTALL_METHOD}" in
		auto) if [ -n "${FS_TOKEN}" ]; then FS_INSTALL_METHOD=packages; else FS_INSTALL_METHOD=source; fi ;;
		packages) [ -n "${FS_TOKEN}" ] || die "FS_INSTALL_METHOD=packages braucht FS_TOKEN (SignalWire Personal Access Token)." ;;
		source) ;;
		*) die "FS_INSTALL_METHOD muss auto, packages oder source sein." ;;
	esac
	if [ "${LETSENCRYPT}" = "yes" ] && [ -z "${ADMIN_EMAIL}" ]; then
		warn "ADMIN_EMAIL ist leer - Let's-Encrypt-Konto wird ohne E-Mail registriert (keine Ablauf-Warnungen)."
	fi
	log "FQDN: ${ASTPP_FQDN} | FreeSWITCH: ${FS_INSTALL_METHOD} | SSH-Port: ${SSH_PORT} | Let's Encrypt: ${LETSENCRYPT}"
}

# --------------------------------------------------------------- base system ---
install_base() {
	step "Basissystem aktualisieren und Pakete installieren"
	apt-get update
	apt-get -y -o Dpkg::Options::=--force-confold full-upgrade
	apt_install ca-certificates curl wget gnupg git rsync lsb-release unzip xz-utils sudo \
		openssl screen imagemagick gettext bc locales-all chrony \
		unattended-upgrades apt-listchanges nftables fail2ban python3-systemd \
		logrotate lua5.3 unixodbc odbc-mariadb iproute2 procps
	if pkg_available openssl-provider-legacy; then apt_install openssl-provider-legacy; fi
	systemctl enable --now chrony

	# Sicherheitsupdates (nur Debian-Security) automatisch einspielen
	cat >/etc/apt/apt.conf.d/20auto-upgrades <<'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
EOF

	# Hostname-Aufloesung lokal (die ASTPP-Cronjobs rufen https://FQDN/ per wget auf)
	if ! grep -qE "[[:space:]]${ASTPP_FQDN//./\\.}([[:space:]]|$)" /etc/hosts; then
		printf '127.0.1.1\t%s %s\n' "${ASTPP_FQDN}" "${ASTPP_FQDN%%.*}" >>/etc/hosts
	fi

	install -d -m 755 "${ASTPP_ETC}"
	render "${SCRIPT_DIR}/files/sysctl/90-astpp.conf" /etc/sysctl.d/90-astpp.conf
	sysctl --system >/dev/null || warn "sysctl --system meldete Fehler"
}

# ------------------------------------------------------------------- MariaDB ---
install_mariadb() {
	step "MariaDB installieren und haerten"
	apt_install mariadb-server mariadb-client
	for p in mariadb-client-compat mariadb-server-compat; do
		if pkg_available "${p}"; then apt_install "${p}"; fi
	done
	# ASTPP ruft /usr/bin/mysql und /usr/bin/mysqldump direkt auf
	[ -x /usr/bin/mysql ] || ln -s /usr/bin/mariadb /usr/bin/mysql
	[ -x /usr/bin/mysqldump ] || ln -s /usr/bin/mariadb-dump /usr/bin/mysqldump

	local mem_mb bp
	mem_mb="$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)"
	bp=$((mem_mb / 4)); [ "${bp}" -lt 256 ] && bp=256
	render "${SCRIPT_DIR}/files/mariadb/90-astpp.cnf" /etc/mysql/mariadb.conf.d/90-astpp.cnf \
		INNODB_BUFFER_POOL "${bp}M"
	install -d -o mysql -g adm -m 2750 /var/log/mysql
	systemctl enable mariadb
	systemctl restart mariadb

	# root nutzt nur unix_socket (Debian-Standard) - kein Root-Passwort noetig
	mariadb -e "DELETE FROM mysql.global_priv WHERE User='';" 2>/dev/null || true
	mariadb -e "DROP DATABASE IF EXISTS test;" || true

	if mariadb -N -e "SHOW DATABASES LIKE 'astpp'" | grep -q astpp; then
		die "Datenbank 'astpp' existiert bereits - Abbruch, um keine Daten zu ueberschreiben."
	fi
	DB_PASS="$(randpw 32)"
	mariadb <<SQL
CREATE DATABASE astpp CHARACTER SET utf8 COLLATE utf8_general_ci;
CREATE USER 'astppuser'@'localhost' IDENTIFIED BY '${DB_PASS}';
CREATE USER 'astppuser'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON astpp.* TO 'astppuser'@'localhost';
GRANT ALL PRIVILEGES ON astpp.* TO 'astppuser'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
	log "MariaDB $(mariadb -N -e 'SELECT VERSION()') bereit"
}

# ------------------------------------------------------------------- PHP 8.5 ---
install_php() {
	step "PHP ${PHP_VER} (packages.sury.org) installieren"
	local deb=/tmp/debsuryorg-archive-keyring.deb
	curl -fsSL -o "${deb}" https://packages.sury.org/debsuryorg-archive-keyring.deb
	dpkg -i "${deb}"
	rm -f "${deb}"
	echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ trixie main" \
		>/etc/apt/sources.list.d/php-sury.list
	apt-get update
	# OPcache und JSON sind ab PHP 8 fest eingebaut (keine eigenen Pakete mehr)
	apt_install "php${PHP_VER}-fpm" "php${PHP_VER}-cli" "php${PHP_VER}-common" "php${PHP_VER}-mysql" \
		"php${PHP_VER}-xml" "php${PHP_VER}-curl" "php${PHP_VER}-gd" "php${PHP_VER}-mbstring" \
		"php${PHP_VER}-readline" "php${PHP_VER}-zip" "php${PHP_VER}-bcmath"
	# NICHT php-intl installieren: ASTPP definiert eine eigene Klasse "Locale"
	# (application/libraries/locale.php) -> Fatal Error "Cannot declare class Locale".
	if dpkg -l "php${PHP_VER}-intl" 2>/dev/null | grep -q '^ii'; then phpdismod -v "${PHP_VER}" intl; fi
	# optional: imap ist seit PHP 8.4 eine PECL-Erweiterung, geoip gibt es fuer PHP 8 nicht mehr
	# (ASTPP prueft function_exists('geoip_country_name_by_name'))
	for p in "php${PHP_VER}-imap" "php${PHP_VER}-imagick"; do
		if pkg_available "${p}"; then apt_install "${p}" || warn "${p} konnte nicht installiert werden (optional)"; fi
	done

	install -m 644 "${SCRIPT_DIR}/files/php/openssl-legacy.cnf" "${OPENSSL_LEGACY_CNF}"
	if ! OPENSSL_CONF="${OPENSSL_LEGACY_CNF}" "php${PHP_VER}" -r \
		'exit(openssl_encrypt("x", "BF-ECB", "0123456789abcdef", OPENSSL_RAW_DATA) === false ? 1 : 0);'; then
		die "Blowfish (OpenSSL legacy provider) ist in PHP nicht verfuegbar - ASTPP-Logins wuerden nicht funktionieren."
	fi

	install -m 644 "${SCRIPT_DIR}/files/php/astpp.ini" "/etc/php/${PHP_VER}/mods-available/astpp.ini"
	phpenmod -v "${PHP_VER}" astpp
	log "$("php${PHP_VER}" -v | head -1)"
}

# --------------------------------------------------------------------- nginx ---
install_nginx() {
	step "nginx installieren"
	apt_install nginx
	rm -f /etc/nginx/sites-enabled/default
	install -d -m 755 /var/www/letsencrypt
	# Platzhalter-Zertifikat (default_server, Fallback falls Let's Encrypt scheitert)
	install -d -m 755 /etc/ssl/astpp
	if [ ! -f /etc/ssl/astpp/selfsigned.key ]; then
		openssl req -x509 -nodes -newkey rsa:3072 -days 825 \
			-subj "/CN=${ASTPP_FQDN}" -addext "subjectAltName=DNS:${ASTPP_FQDN}" \
			-keyout /etc/ssl/astpp/selfsigned.key -out /etc/ssl/astpp/selfsigned.crt 2>/dev/null
		chmod 600 /etc/ssl/astpp/selfsigned.key
	fi
}

# ---------------------------------------------------------------- FreeSWITCH ---
FS_REQUIRED_PKGS=(freeswitch freeswitch-mod-commands freeswitch-mod-console freeswitch-mod-logfile
	freeswitch-mod-db freeswitch-mod-dptools freeswitch-mod-hash freeswitch-mod-expr
	freeswitch-mod-voicemail freeswitch-mod-dialplan-xml freeswitch-mod-sofia freeswitch-mod-loopback
	freeswitch-mod-event-socket freeswitch-mod-json-cdr freeswitch-mod-xml-curl freeswitch-mod-lua
	freeswitch-mod-native-file freeswitch-mod-sndfile freeswitch-mod-local-stream
	freeswitch-mod-tone-stream freeswitch-mod-say-en freeswitch-lang-en
	freeswitch-sounds-en-us-callie freeswitch-music-default freeswitch-conf-vanilla)
FS_OPTIONAL_PKGS=(freeswitch-systemd freeswitch-mod-g723-1 freeswitch-mod-g729 freeswitch-mod-h26x
	freeswitch-mod-opus freeswitch-mod-spandsp freeswitch-timezones)

install_freeswitch_packages() {
	step "FreeSWITCH aus dem SignalWire-Repository installieren"
	install -d -m 755 /etc/apt/auth.conf.d
	umask 077
	printf 'machine freeswitch.signalwire.com login signalwire password %s\n' "${FS_TOKEN}" \
		>/etc/apt/auth.conf.d/freeswitch.conf
	umask 022
	chmod 600 /etc/apt/auth.conf.d/freeswitch.conf
	curl -fsSL --netrc-file /etc/apt/auth.conf.d/freeswitch.conf \
		-o /usr/share/keyrings/signalwire-freeswitch-repo.gpg \
		https://freeswitch.signalwire.com/repo/deb/debian-release/signalwire-freeswitch-repo.gpg \
		|| return 1
	echo "deb [signed-by=/usr/share/keyrings/signalwire-freeswitch-repo.gpg] https://freeswitch.signalwire.com/repo/deb/debian-release/ trixie main" \
		>/etc/apt/sources.list.d/freeswitch.list
	apt-get update || return 1
	apt_install "${FS_REQUIRED_PKGS[@]}" || return 1
	local p
	for p in "${FS_OPTIONAL_PKGS[@]}"; do
		if pkg_available "${p}"; then apt_install "${p}" || warn "FreeSWITCH-Paket ${p} nicht installierbar"; fi
	done
	if [ ! -f "${FS_ETC}/freeswitch.xml" ] && [ -d "${FS_SHARE}/conf/vanilla" ]; then
		cp -a "${FS_SHARE}/conf/vanilla/." "${FS_ETC}/"
	fi
	return 0
}

install_freeswitch_source() {
	step "FreeSWITCH ${FS_VERSION} aus dem Quellcode bauen (dauert 20-40 Minuten)"
	apt_install build-essential autoconf automake libtool libtool-bin pkg-config cmake git \
		libssl-dev zlib1g-dev libdb-dev unixodbc-dev libncurses-dev libexpat1-dev libgdbm-dev \
		bison uuid-dev libpcre2-dev libedit-dev libsqlite3-dev libcurl4-openssl-dev nasm yasm \
		libogg-dev libspeex-dev libspeexdsp-dev liblua5.3-dev libopus-dev \
		libsndfile1-dev libjpeg-dev libtiff-dev
	local b=/usr/src/freeswitch-build j
	j="$(nproc)"
	rm -rf "${b}"; install -d "${b}"

	git clone --depth 1 -b "${SPANDSP_VERSION}" https://github.com/freeswitch/spandsp.git "${b}/spandsp"
	(cd "${b}/spandsp" && ./bootstrap.sh && ./configure --prefix=/usr/local && make -j"${j}" && make install)
	git clone --depth 1 -b "${SOFIA_VERSION}" https://github.com/freeswitch/sofia-sip.git "${b}/sofia-sip"
	(cd "${b}/sofia-sip" && ./bootstrap.sh && ./configure --prefix=/usr/local && make -j"${j}" && make install)
	ldconfig

	git clone --depth 1 -b "${FS_VERSION}" https://github.com/signalwire/freeswitch.git "${b}/freeswitch"
	cd "${b}/freeswitch"
	./bootstrap.sh -j
	cat >modules.conf <<'EOF'
applications/mod_commands
applications/mod_db
applications/mod_dptools
applications/mod_expr
applications/mod_hash
applications/mod_spandsp
applications/mod_voicemail
codecs/mod_g723_1
codecs/mod_g729
codecs/mod_h26x
codecs/mod_opus
dialplans/mod_dialplan_xml
endpoints/mod_loopback
endpoints/mod_sofia
event_handlers/mod_event_socket
event_handlers/mod_json_cdr
formats/mod_local_stream
formats/mod_native_file
formats/mod_sndfile
formats/mod_tone_stream
languages/mod_lua
loggers/mod_console
loggers/mod_logfile
say/mod_say_en
xml_int/mod_xml_curl
EOF
	PKG_CONFIG_PATH=/usr/local/lib/pkgconfig ./configure -C \
		--prefix=/usr --sysconfdir=/etc --localstatedir=/var \
		--enable-core-odbc-support --enable-portable-binary \
		--with-modinstdir=/usr/lib/freeswitch/mod \
		--with-scriptdir="${FS_SHARE}/scripts" --with-soundsdir="${FS_SHARE}/sounds" \
		--with-grammardir="${FS_SHARE}/grammar" --with-htdocsdir="${FS_SHARE}/htdocs" \
		--with-fontsdir="${FS_SHARE}/fonts" --with-certsdir="${FS_ETC}/tls" \
		--with-recordingsdir=/var/lib/freeswitch/recordings --with-storagedir=/var/lib/freeswitch/storage \
		--with-dbdir=/var/lib/freeswitch/db --with-logfiledir=/var/log/freeswitch \
		--with-rundir=/run/freeswitch --with-cachedir=/var/cache/freeswitch
	make -j"${j}"
	make install
	make cd-sounds-install cd-moh-install
	if [ ! -f "${FS_ETC}/freeswitch.xml" ]; then
		install -d "${FS_ETC}"
		cp -a conf/vanilla/. "${FS_ETC}/"
	fi
	cd /

	getent group freeswitch >/dev/null || groupadd --system freeswitch
	getent passwd freeswitch >/dev/null || useradd --system --gid freeswitch --home-dir /var/lib/freeswitch \
		--shell /usr/sbin/nologin --comment "FreeSWITCH" freeswitch
	install -d -o freeswitch -g freeswitch /var/lib/freeswitch /var/lib/freeswitch/db \
		/var/lib/freeswitch/storage /var/log/freeswitch /var/cache/freeswitch
	write_freeswitch_unit
}

# systemd-Unit, falls weder Paket noch Quellbuild eine mitbringen
write_freeswitch_unit() {
	if systemctl cat freeswitch.service >/dev/null 2>&1; then
		return 0
	fi
	log "Lege systemd-Unit fuer FreeSWITCH an"
	cat >/etc/systemd/system/freeswitch.service <<'EOF'
[Unit]
Description=FreeSWITCH
Wants=network-online.target
After=network-online.target

[Service]
Type=forking
PIDFile=/run/freeswitch/freeswitch.pid
ExecStartPre=+/usr/bin/install -d -o freeswitch -g freeswitch -m 0750 /run/freeswitch
ExecStart=/usr/bin/freeswitch -u freeswitch -g freeswitch -ncwait -nonat
TimeoutSec=60s
Restart=on-failure
LimitCORE=infinity
LimitNOFILE=100000
LimitNPROC=60000
LimitSTACK=250000
LimitRTPRIO=infinity
LimitRTTIME=infinity
IOSchedulingClass=realtime
IOSchedulingPriority=2
CPUSchedulingPolicy=rr
CPUSchedulingPriority=89
UMask=0007

[Install]
WantedBy=multi-user.target
EOF
	systemctl daemon-reload
}

install_freeswitch() {
	if [ "${FS_INSTALL_METHOD}" = "packages" ]; then
		if ! install_freeswitch_packages; then
			warn "Installation aus dem SignalWire-Repository fehlgeschlagen (Token/Repo pruefen) - baue aus dem Quellcode."
			rm -f /etc/apt/sources.list.d/freeswitch.list
			apt-get update
			FS_INSTALL_METHOD=source
			install_freeswitch_source
		fi
	else
		install_freeswitch_source
	fi
	write_freeswitch_unit
	command -v freeswitch >/dev/null || die "FreeSWITCH-Binary nicht gefunden."
	getent passwd freeswitch >/dev/null || die "Benutzer 'freeswitch' fehlt."
	log "$(freeswitch -version 2>/dev/null | head -1)"
}

# ------------------------------------------------------------- ASTPP source ---
install_astpp_source() {
	local rev
	rev="$(astpp_tree_revision "${ASTPP_TREE}")"
	step "ASTPP-Quellcode aus dem Fork installieren (${ASTPP_TREE}, Revision ${rev})"
	astpp_lint_tree "${ASTPP_TREE}"
	"php${PHP_VER}" -l "${SCRIPT_DIR}/tools/astpp-postinstall.php" >/dev/null || die "Syntaxfehler in tools/astpp-postinstall.php"

	rm -rf "${ASTPP_SRC}"
	install -d -m 755 "${ASTPP_SRC}"
	astpp_sync_tree "${ASTPP_TREE}"
	# Besitz: root, nur lesbar fuer PHP. Schreibbar nur die Datenverzeichnisse.
	astpp_set_permissions
	astpp_git_record "Installation: ASTPP-Fork ${rev}"

	install -d -m 755 /var/www/html
	ln -sfn "${ASTPP_WEB}" /var/www/html/astpp
	ln -sfn "${ASTPP_SRC}/freeswitch/fs" /var/www/html/fs
}

# ------------------------------------------------------------ ASTPP config ---
configure_astpp() {
	step "ASTPP konfigurieren (neue Schluessel pro Installation)"
	PRIVATE_KEY="$(randpw 32)"
	ENCRYPTION_KEY="$(randpw 32)"
	ADMIN_PASS="$(randpw 20)@7Kq"
	ESL_PASS="$(randpw 28)"
	[ "${PRIVATE_KEY}" != "${UPSTREAM_PRIVATE_KEY}" ] || die "Zufallsgenerator defekt?"

	install -d -o root -g www-data -m 750 "${ASTPP_CONF_DIR}"
	umask 027
	cat >"${ASTPP_CONF_DIR}/astpp-config.conf" <<EOF
; ASTPP configuration - generated by astpp-debian13/install.sh on $(date -u +%F)
; Secrets are unique to this installation. Keep this file private.
dbhost = 127.0.0.1
dbname = astpp
dbuser = astppuser
dbpass = ${DB_PASS}

PRIVATE_KEY = "${PRIVATE_KEY}"
ENCRYPTION_KEY = "${ENCRYPTION_KEY}"
token_key = "$(randpw 32)"
iv_key = "$(randpw 32)"
api_decipher_auth_token = "$(randpw 32)"

astpp_dbengine = MySQLi
debug = 0
base_url=https://${ASTPP_FQDN}/
; call recordings: FreeSWITCH writes, ASTPP reads them (never inside the web root)
recordings_path = /var/lib/freeswitch/recordings
; administrator logins (web, "login as", admin API keys) only from these addresses/networks,
; comma separated, e.g. "203.0.113.10, 198.51.100.0/24, 2001:db8::/48" - empty: no restriction
admin_allowed_ips = ""
EOF
	cat >"${ASTPP_CONF_DIR}/astpp.lua" <<EOF
-- ASTPP database connection for FreeSWITCH - generated by astpp-debian13/install.sh
ODBC_DSN="ASTPP"
DB_USERNAME="astppuser"
DB_PASSWD="${DB_PASS}"
TBL_PREFIX=""
-- Set to false if you want to bill for inbound calls --
INB_FREE="TRUE"
EOF
	local odbc_driver
	odbc_driver="$(dpkg -L odbc-mariadb 2>/dev/null | grep -m1 '/libmaodbc\.so$' || true)"
	[ -n "${odbc_driver}" ] || die "MariaDB-ODBC-Treiber (libmaodbc.so) nicht gefunden."
	cat >/etc/odbc.ini <<EOF
; generated by astpp-debian13/install.sh
[ASTPP]
Description = ASTPP (MariaDB)
Driver      = ${odbc_driver}
Server      = localhost
Socket      = /run/mysqld/mysqld.sock
Database    = astpp
User        = astppuser
Password    = ${DB_PASS}
Charset     = utf8
EOF
	umask 022
	chown root:www-data "${ASTPP_CONF_DIR}/astpp-config.conf"
	chmod 640 "${ASTPP_CONF_DIR}/astpp-config.conf"
	chown root:freeswitch "${ASTPP_CONF_DIR}/astpp.lua" /etc/odbc.ini
	chmod 640 "${ASTPP_CONF_DIR}/astpp.lua" /etc/odbc.ini
	# FreeSWITCH muss astpp.lua lesen koennen
	chmod 751 "${ASTPP_CONF_DIR}"

	install -d -o www-data -g adm -m 2750 "${ASTPP_LOG_DIR}"
	local f
	for f in astpp.log astpp_email.log cron.log php_errors.log php_fs_errors.log; do
		install -o www-data -g adm -m 640 /dev/null "${ASTPP_LOG_DIR}/${f}"
	done
	# fs_cli soll das ESL-Passwort ohne Kommandozeile kennen
	umask 077
	printf '[default]\nhost => 127.0.0.1\nport => 8021\npassword => %s\n' "${ESL_PASS}" >/etc/fs_cli.conf
	umask 022
}

# ----------------------------------------------------------------- database ---
import_database() {
	step "Datenbank importieren"
	local f
	for f in astpp-6.0.sql astpp-6.0.1.sql astpp-fork-6.1.sql; do
		log "Importiere ${f}"
		# MySQL-8-Kollation -> MariaDB-kompatibel
		sed -e 's/utf8mb4_0900_ai_ci/utf8mb4_unicode_ci/g' "${ASTPP_SRC}/database/${f}" | mariadb astpp
	done
	mariadb astpp -e "UPDATE \`system\` SET value='${ASTPP_LOG_DIR}/' WHERE name='log_path';" || true

	step "Seed-Daten absichern (Passwoerter, Demo-Konten, ESL) und Betreiberdaten setzen"
	OPENSSL_CONF="${OPENSSL_LEGACY_CNF}" ASTPP_ADMIN_PASSWORD="${ADMIN_PASS}" ASTPP_ESL_PASSWORD="${ESL_PASS}" ASTPP_WEB="${ASTPP_WEB}" \
		ASTPP_COMPANY_NAME="${COMPANY_NAME}" ASTPP_COMPANY_WEBSITE="${COMPANY_WEBSITE}" \
		ASTPP_COMPANY_EMAIL="${ADMIN_EMAIL}" ASTPP_COMPANY_COUNTRY="${COMPANY_COUNTRY}" \
		"php${PHP_VER}" "${SCRIPT_DIR}/tools/astpp-postinstall.php"
}

# --------------------------------------------------------- FreeSWITCH config ---
configure_freeswitch() {
	step "FreeSWITCH fuer ASTPP konfigurieren"
	[ -f "${FS_ETC}/freeswitch.xml" ] || die "${FS_ETC}/freeswitch.xml fehlt."
	local ac="${FS_ETC}/autoload_configs"
	cp -f "${ASTPP_SRC}"/freeswitch/conf/autoload_configs/*.xml "${ac}/"
	render "${SCRIPT_DIR}/files/freeswitch/event_socket.conf.xml" "${ac}/event_socket.conf.xml" ESL_PASSWORD "${ESL_PASS}"
	cp -f "${SCRIPT_DIR}/files/freeswitch/logfile.conf.xml" "${ac}/logfile.conf.xml"

	# Kapazitaeten wie im Upstream-Installer, RTP-Portbereich passend zur Firewall
	sed -i -e 's#max-sessions" value="1000#max-sessions" value="2000#' \
		-e 's#sessions-per-second" value="30#sessions-per-second" value="50#' \
		-e 's#max-db-handles" value="50#max-db-handles" value="500#' \
		-e 's#db-handle-timeout" value="10#db-handle-timeout" value="30#' \
		-e "s#<!-- *<param name=\"rtp-start-port\" value=\"[0-9]*\"/> *-->#<param name=\"rtp-start-port\" value=\"${RTP_START}\"/>#" \
		-e "s#<!-- *<param name=\"rtp-end-port\" value=\"[0-9]*\"/> *-->#<param name=\"rtp-end-port\" value=\"${RTP_END}\"/>#" \
		"${ac}/switch.conf.xml"
	grep -q "rtp-start-port\" value=\"${RTP_START}\"" "${ac}/switch.conf.xml" \
		|| warn "rtp-start-port in switch.conf.xml nicht gesetzt - bitte pruefen (Firewall erlaubt ${RTP_START}-${RTP_END})."
	# Standardpasswort der Vanilla-Konfiguration ersetzen
	sed -i "s#default_password=1234#default_password=$(randpw 24)#" "${FS_ETC}/vars.xml"

	# Dialplan, Benutzer und SIP-Profile kommen ausschliesslich aus ASTPP (xml_curl / Lua)
	local d ts
	ts="$(date +%Y%m%d%H%M%S)"
	for d in dialplan directory sip_profiles; do
		if [ -d "${FS_ETC}/${d}" ] && [ ! -f "${FS_ETC}/${d}/astpp.xml" ]; then
			mv "${FS_ETC}/${d}" "${FS_ETC}/${d}.vanilla-${ts}"
		fi
		install -d "${FS_ETC}/${d}"
		printf '<include>\n</include>\n' >"${FS_ETC}/${d}/astpp.xml"
	done

	# Lua-Skripte und Sounds von ASTPP
	if [ -d "${FS_SHARE}/scripts" ] && [ ! -L "${FS_SHARE}/scripts" ]; then
		mv "${FS_SHARE}/scripts" "${FS_SHARE}/scripts.dist-${ts}"
	fi
	ln -sfn "${ASTPP_SRC}/freeswitch/scripts" "${FS_SHARE}/scripts"
	install -d "${FS_SHARE}/sounds/en/us/callie"
	cp -f "${ASTPP_SRC}"/freeswitch/sounds/*.wav "${FS_SHARE}/sounds/en/us/callie/"

	# Aufnahmen: FreeSWITCH schreibt, ASTPP (www-data) liest - statt chmod 777
	install -d -o freeswitch -g www-data -m 2770 /var/lib/freeswitch/recordings

	chown -R freeswitch:freeswitch "${FS_ETC}"
	find "${FS_ETC}" -type d -exec chmod 750 {} +
	find "${FS_ETC}" -type f -exec chmod 640 {} +

	install -d /etc/systemd/system/freeswitch.service.d
	render "${SCRIPT_DIR}/files/systemd/freeswitch-astpp.conf" /etc/systemd/system/freeswitch.service.d/astpp.conf \
		PHP_VER "${PHP_VER}"
	touch /var/log/freeswitch/freeswitch.log
	chown freeswitch:freeswitch /var/log/freeswitch/freeswitch.log
}

# ---------------------------------------------------------------- php-fpm ---
configure_php_fpm() {
	step "PHP-FPM-Pools einrichten"
	local pool_dir="/etc/php/${PHP_VER}/fpm/pool.d" mem_mb pm_max obd_line
	[ -f "${pool_dir}/www.conf" ] && mv "${pool_dir}/www.conf" "${pool_dir}/www.conf.disabled"
	mem_mb="$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)"
	pm_max=$((mem_mb / 160)); [ "${pm_max}" -lt 8 ] && pm_max=8; [ "${pm_max}" -gt 60 ] && pm_max=60
	if [ "${PHP_OPEN_BASEDIR}" = "yes" ]; then
		# /proc/version: Kernel-Version im Admin-Dashboard
		obd_line="php_admin_value[open_basedir] = ${ASTPP_SRC}/:/var/www/html/:${ASTPP_CONF_DIR}/:${ASTPP_LOG_DIR}/:/tmp/:/usr/share/php/:/var/lib/freeswitch/recordings/:${FS_SHARE}/sounds/:/proc/version"
	else
		obd_line="; open_basedir deaktiviert (PHP_OPEN_BASEDIR=no)"
	fi
	render "${SCRIPT_DIR}/files/php/pool-astpp.conf" "${pool_dir}/astpp.conf" \
		PHP_SOCK "${PHP_SOCK}" PM_MAX_CHILDREN "${pm_max}" OPENSSL_LEGACY_CNF "${OPENSSL_LEGACY_CNF}" \
		OPEN_BASEDIR_LINE "${obd_line}"
	render "${SCRIPT_DIR}/files/php/pool-astpp-fs.conf" "${pool_dir}/astpp-fs.conf" \
		PHP_FS_SOCK "${PHP_FS_SOCK}" PM_FS_MAX_CHILDREN "$((pm_max + 10))"
	install -d "/etc/systemd/system/php${PHP_VER}-fpm.service.d"
	render "${SCRIPT_DIR}/files/systemd/php-fpm-astpp.conf" "/etc/systemd/system/php${PHP_VER}-fpm.service.d/astpp.conf" \
		OPENSSL_LEGACY_CNF "${OPENSSL_LEGACY_CNF}"
	systemctl daemon-reload
	"php-fpm${PHP_VER}" -t
	systemctl enable "php${PHP_VER}-fpm"
	systemctl restart "php${PHP_VER}-fpm"
}

# --------------------------------------------------------------- nginx/TLS ---
nginx_site() {
	render "${SCRIPT_DIR}/files/nginx/astpp.conf" /etc/nginx/sites-available/astpp.conf \
		FQDN "${ASTPP_FQDN}" PHP_SOCK "${PHP_SOCK}" SSL_CERT "$1" SSL_KEY "$2"
	ln -sfn /etc/nginx/sites-available/astpp.conf /etc/nginx/sites-enabled/astpp.conf
}

configure_nginx_tls() {
	step "nginx und TLS konfigurieren"
	render "${SCRIPT_DIR}/files/nginx/astpp-fs.conf" /etc/nginx/sites-available/astpp-fs.conf PHP_FS_SOCK "${PHP_FS_SOCK}"
	ln -sfn /etc/nginx/sites-available/astpp-fs.conf /etc/nginx/sites-enabled/astpp-fs.conf
	nginx_site /etc/ssl/astpp/selfsigned.crt /etc/ssl/astpp/selfsigned.key
	nginx -t
	systemctl enable nginx
	systemctl restart nginx

	if [ "${LETSENCRYPT}" != "yes" ]; then
		warn "LETSENCRYPT=no - selbstsigniertes Zertifikat aktiv."
		return 0
	fi
	# Voraussetzung: oeffentlicher DNS-Eintrag von ${ASTPP_FQDN} zeigt auf diesen Server
	# und Port 80 ist erreichbar (die Firewall wird erst danach aktiviert).
	apt_install certbot
	local email_args=(--register-unsafely-without-email)
	[ -n "${ADMIN_EMAIL}" ] && email_args=(-m "${ADMIN_EMAIL}")
	if certbot certonly --webroot -w /var/www/letsencrypt -d "${ASTPP_FQDN}" \
		--agree-tos --non-interactive --keep-until-expiring "${email_args[@]}"; then
		nginx_site "/etc/letsencrypt/live/${ASTPP_FQDN}/fullchain.pem" "/etc/letsencrypt/live/${ASTPP_FQDN}/privkey.pem"
		install -d /etc/letsencrypt/renewal-hooks/deploy
		printf '#!/bin/sh\nsystemctl reload nginx\n' >/etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh
		chmod 755 /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh
		nginx -t && systemctl reload nginx
		log "Let's-Encrypt-Zertifikat aktiv"
	else
		warn "Let's Encrypt fehlgeschlagen (DNS-Eintrag / Port 80 pruefen). Selbstsigniertes Zertifikat bleibt aktiv. Spaeter: certbot certonly --webroot -w /var/www/letsencrypt -d ${ASTPP_FQDN}"
	fi
}

# ------------------------------------------------------- cron / logrotate ---
configure_cron_logrotate() {
	step "Cronjobs (als www-data) und Logrotation"
	rm -f /var/spool/cron/crontabs/astpp
	render "${SCRIPT_DIR}/files/cron/astpp" /etc/cron.d/astpp \
		OPENSSL_LEGACY_CNF "${OPENSSL_LEGACY_CNF}" ASTPP_WEB "${ASTPP_WEB}" PHP_VER "${PHP_VER}"
	chmod 644 /etc/cron.d/astpp
	render "${SCRIPT_DIR}/files/logrotate/astpp" /etc/logrotate.d/astpp
	chmod 644 /etc/logrotate.d/astpp
}

# ---------------------------------------------------------------- firewall ---
configure_firewall() {
	step "nftables-Firewall"
	local ssh_rules sip_rules sip_ports="5060" tmp
	# shellcheck disable=SC2086
	if [ -n "${SSH_ALLOW_CIDRS}" ]; then
		ssh_rules=$'\t\tip saddr @ssh_allow4 accept\n\t\tip6 saddr @ssh_allow6 accept\n\t\tdrop'
	else
		ssh_rules=$'\t\taccept'
	fi
	if [ -n "${SIP_ALLOW_CIDRS}" ]; then
		sip_rules=$'\t\tip saddr @sip_allow4 accept\n\t\tip6 saddr @sip_allow6 accept\n\t\tdrop'
	else
		sip_rules=$'\t\tupdate @sip_rate4 { ip saddr limit rate over '"${SIP_RATE}"$'/second burst '"${SIP_BURST}"$' packets } drop\n\t\tupdate @sip_rate6 { ip6 saddr limit rate over '"${SIP_RATE}"$'/second burst '"${SIP_BURST}"$' packets } drop\n\t\taccept'
	fi
	[ "${SIP_TLS}" = "yes" ] && sip_ports="{ 5060, 5061 }"
	tmp="$(mktemp)"
	# shellcheck disable=SC2086
	render "${SCRIPT_DIR}/files/nftables/nftables.conf" "${tmp}" \
		SSH_PORT "${SSH_PORT}" RTP_START "${RTP_START}" RTP_END "${RTP_END}" SIP_PORTS "${sip_ports}" \
		SSH_ALLOW4 "$(nft_elements 4 ${SSH_ALLOW_CIDRS})" SSH_ALLOW6 "$(nft_elements 6 ${SSH_ALLOW_CIDRS})" \
		SIP_ALLOW4 "$(nft_elements 4 ${SIP_ALLOW_CIDRS})" SIP_ALLOW6 "$(nft_elements 6 ${SIP_ALLOW_CIDRS})" \
		SSH_RULES "${ssh_rules}" SIP_RULES "${sip_rules}"
	if ! nft -c -f "${tmp}"; then
		cp "${tmp}" /root/nftables.astpp.invalid
		rm -f "${tmp}"
		warn "nftables-Regelwerk ungueltig (siehe /root/nftables.astpp.invalid) - Firewall NICHT aktiviert!"
		return 0
	fi
	[ -f /etc/nftables.conf ] && cp /etc/nftables.conf "/etc/nftables.conf.bak-$(date +%Y%m%d%H%M%S)"
	install -m 755 "${tmp}" /etc/nftables.conf
	rm -f "${tmp}"
	# Debian-Standardtabelle (alles erlaubt) entfernen, falls geladen
	nft delete table inet filter 2>/dev/null || true
	systemctl enable nftables
	# kein "restart": ExecStop wuerde mit "flush ruleset" auch fail2ban-Tabellen loeschen
	if systemctl is-active --quiet nftables; then systemctl reload nftables; else systemctl start nftables; fi
	log "Firewall aktiv: SSH ${SSH_PORT}/tcp, 80/443, SIP ${sip_ports}, RTP ${RTP_START}-${RTP_END}/udp"
}

configure_fail2ban() {
	step "fail2ban"
	local ignore
	ignore="$(local_ips | tr '\n' ' ') ${SSH_ALLOW_CIDRS}"
	render "${SCRIPT_DIR}/files/fail2ban/jail.d/astpp.conf" /etc/fail2ban/jail.d/astpp.conf \
		IGNORE_IPS "${ignore}" ADMIN_EMAIL "${ADMIN_EMAIL:-root@localhost}" FQDN "${ASTPP_FQDN}" SSH_PORT "${SSH_PORT}"
	install -m 644 "${SCRIPT_DIR}/files/fail2ban/filter.d/freeswitch-astpp.conf" /etc/fail2ban/filter.d/
	install -m 644 "${SCRIPT_DIR}/files/fail2ban/filter.d/astpp-login.conf" /etc/fail2ban/filter.d/
	touch /var/log/nginx/astpp_access.log
	systemctl enable fail2ban
	systemctl restart fail2ban
}

# ---------------------------------------------------------------- services ---
start_services() {
	step "Dienste starten"
	systemctl daemon-reload
	systemctl restart mariadb "php${PHP_VER}-fpm" nginx
	systemctl enable freeswitch
	systemctl restart freeswitch
	local i
	for i in $(seq 1 30); do
		if fs_cli -x status >/dev/null 2>&1; then break; fi
		sleep 2
	done
}

healthcheck() {
	step "Funktionspruefung"
	local code
	code="$(curl -sk -o /dev/null -w '%{http_code}' --resolve "${ASTPP_FQDN}:443:127.0.0.1" "https://${ASTPP_FQDN}/" || true)"
	[ "${code}" = "200" ] && log "Weboberflaeche antwortet (HTTP 200)" || warn "Weboberflaeche antwortet mit HTTP ${code}"

	if curl -fsS "http://127.0.0.1:8735/index.php?section=configuration&key_value=acl.conf" | grep -q '<document'; then
		log "xml_curl-Endpunkt liefert Konfiguration"
	else
		warn "xml_curl-Endpunkt (127.0.0.1:8735) liefert kein XML"
	fi
	if echo "SELECT COUNT(*) FROM accounts;" | isql -b ASTPP >/dev/null 2>&1; then
		log "ODBC-DSN ASTPP funktioniert"
	else
		warn "ODBC-DSN ASTPP funktioniert nicht (isql -v ASTPP)"
	fi
	if fs_cli -x 'sofia status' 2>/dev/null | grep -q 'default'; then
		log "FreeSWITCH laeuft, SIP-Profil 'default' geladen"
	else
		warn "FreeSWITCH: SIP-Profil 'default' nicht aktiv - pruefen mit: fs_cli -x 'sofia status'"
	fi
	[ "$(mariadb -N -e "SELECT @@event_scheduler")" = "ON" ] || warn "MariaDB event_scheduler ist nicht aktiv"
	for code in nginx "php${PHP_VER}-fpm" mariadb freeswitch fail2ban nftables; do
		systemctl is-active --quiet "${code}" || warn "Dienst ${code} laeuft nicht"
	done
}

# Zugangsdaten sofort nach dem Erzeugen sichern - ein spaeterer Abbruch darf sie
# nicht verlieren (das Admin-Passwort existiert sonst nur im Speicher).
write_credentials() {
	umask 077
	cat >"${CRED_FILE}" <<EOF
ASTPP - Zugangsdaten ($(date -u '+%F %T') UTC)
=====================================================
Weboberflaeche : https://${ASTPP_FQDN}/
Admin-Login    : admin
Admin-Passwort : ${ADMIN_PASS}

MariaDB        : Benutzer astppuser / ${DB_PASS}
                 (root: Unix-Socket, als root einfach "mariadb")
FreeSWITCH ESL : 127.0.0.1:8021 / ${ESL_PASS}   (fs_cli liest /etc/fs_cli.conf)

Konfiguration  : ${ASTPP_CONF_DIR}/astpp-config.conf (enthaelt die Schluessel dieser Installation)
Installationslog: ${LOG_FILE}

Diese Datei nach dem Uebertragen in den Passwortmanager loeschen.
EOF
	umask 022
}

summary() {
	step "Installation abgeschlossen"
	echo "  URL:            https://${ASTPP_FQDN}/"
	echo "  Zugangsdaten:   ${CRED_FILE}  (nur root lesbar)"
	echo "  FreeSWITCH:     ${FS_INSTALL_METHOD}"
	if [ "${#WARNINGS[@]}" -gt 0 ]; then
		echo
		printf '%sWarnungen:%s\n' "${C_WARN}" "${C_OFF}"
		printf '  - %s\n' "${WARNINGS[@]}"
	fi
	echo
	echo "  Naechste Schritte: siehe README.md (Abschnitt 'Nach der Installation')."
}

main() {
	install -m 600 /dev/null "${LOG_FILE}" 2>/dev/null || true
	exec > >(tee -a "${LOG_FILE}") 2>&1
	preflight
	install_base
	install_mariadb
	install_php
	install_nginx
	install_freeswitch
	install_astpp_source
	configure_astpp
	write_credentials
	import_database
	configure_freeswitch
	configure_php_fpm
	configure_nginx_tls
	configure_cron_logrotate
	configure_firewall
	configure_fail2ban
	start_services
	healthcheck
	summary
}

main "$@"

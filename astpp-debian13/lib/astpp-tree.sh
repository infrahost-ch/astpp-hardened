# shellcheck shell=bash
# =============================================================================
#  ASTPP-Codebaum auf dem Server - gemeinsam fuer install.sh und tools/astpp-deploy.sh
#
#  Quelle ist das Verzeichnis astpp/ dieses Repositories (der infrahost-Fork).
#  Erwartet: SCRIPT_DIR (= astpp-debian13), ASTPP_SRC, ASTPP_WEB, PHP_VER sowie die
#  Funktionen log/die.
# =============================================================================

# render TEMPLATE DEST KEY VALUE [KEY VALUE ...] - literal @KEY@ replacement
render() {
	local src="$1" dst="$2" content key val
	shift 2
	content="$(<"${src}")"
	while [ "$#" -ge 2 ]; do
		key="@$1@"; val="$2"; shift 2
		content="${content//"${key}"/"${val}"}"
	done
	if grep -q '@[A-Z0-9_]\+@' <<<"${content}"; then
		die "Template ${src}: nicht ersetzte Platzhalter: $(grep -o '@[A-Z0-9_]\+@' <<<"${content}" | sort -u | tr '\n' ' ')"
	fi
	printf '%s\n' "${content}" >"${dst}"
}

# Verzeichnisse unter ASTPP_WEB, in die PHP schreibt. Ihr Inhalt gehoert der Installation:
# versionierte Dateien darin (index.html, Logos, Beispiel-CSVs) werden aktualisiert,
# es wird dort aber nie etwas geloescht.
ASTPP_RUNTIME_DIRS=(upload attachments invoices database_backup language assets/Rates_File
	assets/Rates_File/uploaded_files assets/captcha application/cache application/logs)
# Laufzeitdaten ausserhalb von ASTPP_WEB (relativ zu ASTPP_SRC)
ASTPP_RUNTIME_SRC_DIRS=(freeswitch/scripts/astpp/lib/addons freeswitch/fs/lib/addons)

# Quellbaum (astpp/ neben astpp-debian13/) finden und pruefen
astpp_tree_dir() {
	local tree="${ASTPP_TREE:-$(cd "${SCRIPT_DIR}/.." && pwd)/astpp}"
	[ -f "${tree}/web_interface/astpp/index.php" ] && [ -f "${tree}/web_interface/astpp/application/php7compat.php" ] \
		|| die "ASTPP-Quellbaum nicht gefunden: ${tree} (erwartet: Repository-Checkout mit astpp/ neben astpp-debian13/, oder ASTPP_TREE setzen)"
	printf '%s' "${tree}"
}

# Revision des Quellbaums fuer Protokolle ("<commit> (Repository)" oder "unbekannt")
astpp_tree_revision() {
	local tree="$1" rev
	if rev="$(git -C "${tree}" rev-parse --short=12 HEAD 2>/dev/null)"; then
		git -C "${tree}" diff --quiet HEAD -- . 2>/dev/null || rev="${rev}+lokale-Aenderungen"
		printf '%s' "${rev}"
	else
		printf 'unbekannt'
	fi
}

# Syntaxpruefung aller PHP- und Lua-Dateien eines Baums
astpp_lint_tree() {
	local tree="$1" out f bad=0
	out="$(find "${tree}" -name '*.php' -not -path '*/.git/*' -print0 \
		| xargs -0 -n 200 -P "$(nproc)" "php${PHP_VER}" -l 2>&1 | grep -v '^No syntax errors detected' | grep -v '^$' || true)"
	if [ -n "${out}" ]; then
		printf '%s\n' "${out}" >&2
		bad=1
	fi
	while IFS= read -r -d '' f; do
		luac5.3 -p "${f}" || bad=1
	done < <(find "${tree}/freeswitch" -name '*.lua' -print0)
	[ "${bad}" -eq 0 ] || die "Syntaxfehler im ASTPP-Quellbaum ${tree} - Abbruch."
	log "Syntaxpruefung: $(find "${tree}" -name '*.php' -not -path '*/.git/*' | wc -l) PHP-, $(find "${tree}/freeswitch" -name '*.lua' | wc -l) Lua-Dateien ok"
}

# Laufzeitverzeichnisse relativ zu ASTPP_SRC
astpp_runtime_paths() {
	local d
	for d in "${ASTPP_RUNTIME_DIRS[@]}"; do printf 'web_interface/astpp/%s\n' "${d}"; done
	for d in "${ASTPP_RUNTIME_SRC_DIRS[@]}"; do printf '%s\n' "${d}"; done
}

# Baum nach ASTPP_SRC spiegeln: astpp_sync_tree TREE [zusaetzliche rsync-Optionen, z.B. -n -i -c]
#  1. alles ausser den Laufzeitverzeichnissen exakt (mit --delete)
#  2. Laufzeitverzeichnisse: nur fehlende Dateien ergaenzen - nie ueberschreiben (hochgeladene
#     Logos tragen dieselben Namen wie die mitgelieferten), nie loeschen
# Mit -n (Probelauf) wird nur Schritt 1 ausgegeben.
astpp_sync_tree() {
	local tree="$1" p args=(-rlt --delete --exclude=/.git)
	shift
	# .gitignore kommt mit: es haelt die Laufzeitdaten aus astpp_git_record heraus
	while IFS= read -r p; do args+=("--exclude=/${p}/"); done < <(astpp_runtime_paths)
	rsync "${args[@]}" "$@" "${tree}/" "${ASTPP_SRC}/"
	case " $* " in *" -n "*|*" --dry-run "*) return 0 ;; esac
	while IFS= read -r p; do
		[ -d "${tree}/${p}" ] || continue
		install -d "${ASTPP_SRC}/${p}"
		rsync -rlt --ignore-existing "${tree}/${p}/" "${ASTPP_SRC}/${p}/"
	done < <(astpp_runtime_paths)
}

# Besitz und Rechte: Code gehoert root und ist fuer PHP nur lesbar, schreibbar sind nur die
# Laufzeitverzeichnisse (www-data). Laufzeitverzeichnisse werden nie voruebergehend root-eigen.
astpp_set_permissions() {
	local d prune=()
	for d in "${ASTPP_RUNTIME_DIRS[@]}"; do prune+=(-path "${ASTPP_WEB}/${d}" -prune -o); done
	find "${ASTPP_SRC}" -path "${ASTPP_SRC}/.git" -prune -o "${prune[@]}" -type d -exec chown root:root {} + -exec chmod 755 {} +
	find "${ASTPP_SRC}" -path "${ASTPP_SRC}/.git" -prune -o "${prune[@]}" -type f -exec chown root:root {} + -exec chmod 644 {} +
	for d in "${ASTPP_RUNTIME_DIRS[@]}"; do
		install -d "${ASTPP_WEB}/${d}"
		chown -R www-data:www-data "${ASTPP_WEB}/${d}"
		find "${ASTPP_WEB}/${d}" -type d -exec chmod 750 {} +
		find "${ASTPP_WEB}/${d}" -type f -exec chmod 640 {} +
	done
	# nginx (www-data) muss Logos/Sounds/Captchas ausliefern koennen
	chmod 755 "${ASTPP_WEB}/upload" "${ASTPP_WEB}/assets/captcha"
	for d in "${ASTPP_RUNTIME_SRC_DIRS[@]}"; do install -d "${ASTPP_SRC}/${d}"; done
}

# Installierten Stand in einem lokalen git-Repository festhalten: "git -C /opt/ASTPP status"
# zeigt danach jede Aenderung am Server, die nicht aus dem Fork kommt.
astpp_git_record() {
	local msg="$1"
	[ -d "${ASTPP_SRC}/.git" ] || git init -q "${ASTPP_SRC}"
	git -C "${ASTPP_SRC}" config core.fileMode false
	git -C "${ASTPP_SRC}" config astpp.deploy true
	git -C "${ASTPP_SRC}" add -A
	git -C "${ASTPP_SRC}" -c user.name=astpp-deploy -c user.email="root@$(hostname -f 2>/dev/null || hostname)" \
		commit -q --allow-empty -m "${msg}"
}

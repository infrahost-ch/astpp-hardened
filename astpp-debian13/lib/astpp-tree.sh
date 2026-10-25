# shellcheck shell=bash
# =============================================================================
#  ASTPP-Codebaum auf dem Server - gemeinsam fuer install.sh und tools/astpp-deploy.sh
#
#  Quelle ist der ASTPP-Baum des infrahost-Forks: das Verzeichnis astpp/ neben
#  astpp-debian13/ oder der Baum, in dem astpp-debian13/ selbst liegt.
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

# Quellbaum finden und pruefen. ASTPP_TREE gesetzt: dieser Baum. Sonst astpp/ neben
# astpp-debian13/ (Repository mit beiden Verzeichnissen), und fehlt es, der Baum, in dem
# astpp-debian13/ liegt (astpp-debian13/ neben web_interface/, freeswitch/ ...).
astpp_tree_dir() {
	local parent tree
	parent="$(cd "${SCRIPT_DIR}/.." && pwd)"
	if [ -n "${ASTPP_TREE:-}" ]; then
		tree="${ASTPP_TREE}"
	elif [ -f "${parent}/astpp/web_interface/astpp/index.php" ]; then
		tree="${parent}/astpp"
	else
		tree="${parent}"
	fi
	[ -f "${tree}/web_interface/astpp/index.php" ] && [ -f "${tree}/web_interface/astpp/application/php7compat.php" ] \
		|| die "ASTPP-Quellbaum nicht gefunden: ${tree} (erwartet: astpp/ neben astpp-debian13/ oder astpp-debian13/ im ASTPP-Baum, sonst ASTPP_TREE setzen)"
	printf '%s' "${tree}"
}

# Liegt das Installationspaket (SCRIPT_DIR) im Baum, sein Pfad relativ zum Baum, sonst nichts.
# Dieser Teil gehoert nicht zu ASTPP: keine Syntaxpruefung, kein Abgleich nach ASTPP_SRC,
# Aenderungen daran zaehlen nicht als lokale Aenderungen des Baums.
astpp_tree_skip() {
	local tree inst
	tree="$(cd "$1" && pwd -P)"
	inst="$(cd "${SCRIPT_DIR}" && pwd -P)"
	case "${inst}" in
		"${tree}"/*) printf '%s' "${inst#"${tree}"/}" ;;
	esac
}

# Revision des Quellbaums fuer Protokolle ("<commit> (Repository)" oder "unbekannt")
astpp_tree_revision() {
	local tree="$1" rev skip ex=()
	skip="$(astpp_tree_skip "${tree}")"
	if [ -n "${skip}" ]; then
		ex=(":(exclude)${skip}")
	fi
	if rev="$(git -C "${tree}" rev-parse --short=12 HEAD 2>/dev/null)"; then
		git -C "${tree}" diff --quiet HEAD -- . "${ex[@]}" 2>/dev/null || rev="${rev}+lokale-Aenderungen"
		printf '%s' "${rev}"
	else
		printf 'unbekannt'
	fi
}

# Syntaxpruefung aller PHP- und Lua-Dateien eines Baums
astpp_lint_tree() {
	local tree="$1" out f bad=0 skip prune=()
	skip="$(astpp_tree_skip "${tree}")"
	if [ -n "${skip}" ]; then
		prune=(-path "${tree}/${skip}" -prune -o)
	fi
	out="$(find "${tree}" "${prune[@]}" -name '*.php' -not -path '*/.git/*' -print0 \
		| xargs -0 -n 200 -P "$(nproc)" "php${PHP_VER}" -l 2>&1 | grep -v '^No syntax errors detected' | grep -v '^$' || true)"
	if [ -n "${out}" ]; then
		printf '%s\n' "${out}" >&2
		bad=1
	fi
	while IFS= read -r -d '' f; do
		luac5.3 -p "${f}" || bad=1
	done < <(find "${tree}/freeswitch" -name '*.lua' -print0)
	[ "${bad}" -eq 0 ] || die "Syntaxfehler im ASTPP-Quellbaum ${tree} - Abbruch."
	log "Syntaxpruefung: $(find "${tree}" "${prune[@]}" -name '*.php' -not -path '*/.git/*' -print | wc -l) PHP-, $(find "${tree}/freeswitch" -name '*.lua' | wc -l) Lua-Dateien ok"
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
	local tree="$1" p skip args=(-rlt --delete --exclude=/.git)
	shift
	skip="$(astpp_tree_skip "${tree}")"
	if [ -n "${skip}" ]; then
		args+=("--exclude=/${skip}/")
	fi
	# .gitignore kommt mit: es haelt die Laufzeitdaten aus astpp_git_record heraus
	while IFS= read -r p; do args+=("--exclude=/${p}/"); done < <(astpp_runtime_paths)
	rsync "${args[@]}" "$@" "${tree}/" "${ASTPP_SRC}/"
	case " $* " in *" -n "*|*" --dry-run "*) return 0 ;; esac
	while IFS= read -r p; do
		[ -d "${tree}/${p}" ] || continue
		install -d "${ASTPP_SRC}/${p}"
		rsync -rlt --ignore-existing "${tree}/${p}/" "${ASTPP_SRC}/${p}/"
		astpp_refresh_stock_files "${tree}" "${p}"
	done < <(astpp_runtime_paths)
}

# Mitgelieferte Dateien in einem Laufzeitverzeichnis (Standardlogos, Favicon ...) auf den neuen
# Stand bringen - aber nur, wenn sie am Server unveraendert sind, d.h. ihr Inhalt einer frueheren
# Version derselben Datei im Repository entspricht. Hochgeladene eigene Dateien bleiben.
astpp_refresh_stock_files() {
	local tree="$1" p="$2" f rel cur
	git -C "${tree}" rev-parse --git-dir >/dev/null 2>&1 || return 0
	while IFS= read -r -d '' f; do
		rel="${f#"${tree}"/}"
		[ -f "${ASTPP_SRC}/${rel}" ] || continue
		cmp -s "${f}" "${ASTPP_SRC}/${rel}" && continue
		cur="$(git hash-object "${ASTPP_SRC}/${rel}")"
		if git -C "${tree}" log --format= --raw --no-abbrev -- "${rel}" | awk '{print $3; print $4}' | grep -qxF "${cur}"; then
			cp -p "${f}" "${ASTPP_SRC}/${rel}"
			log "Standarddatei aktualisiert: ${rel}"
		fi
	done < <(find "${tree}/${p}" -maxdepth 1 -type f -print0)
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

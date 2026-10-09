#!/bin/bash
# Fetch npm packages from the official registry and verify the published SHA-512 integrity.
#   npmfetch.sh <dest-dir> pkg[@version] ...
set -euo pipefail
dest="$1"; shift
mkdir -p "$dest"
for spec in "$@"; do
	# pkg, pkg@1.2.3, @scope/pkg, @scope/pkg@1.2.3
	[[ "$spec" =~ ^(@?[^@]+)(@(.+))?$ ]] || { echo "bad spec $spec"; exit 1; }
	name="${BASH_REMATCH[1]}"; ver="${BASH_REMATCH[3]:-latest}"
	enc="${name/\//%2f}"
	meta="$(curl -fsS "https://registry.npmjs.org/${enc}/${ver}")"
	v="$(php8.5 -r '$m=json_decode(stream_get_contents(STDIN),true); echo $m["version"];' <<<"$meta")"
	url="$(php8.5 -r '$m=json_decode(stream_get_contents(STDIN),true); echo $m["dist"]["tarball"];' <<<"$meta")"
	integ="$(php8.5 -r '$m=json_decode(stream_get_contents(STDIN),true); echo $m["dist"]["integrity"];' <<<"$meta")"
	lic="$(php8.5 -r '$m=json_decode(stream_get_contents(STDIN),true); echo is_array($m["license"]??null)?json_encode($m["license"]):($m["license"]??"?");' <<<"$meta")"
	f="$dest/$(basename "$url")"
	curl -fsS -o "$f" "$url"
	[ "${integ%%-*}" = "sha512" ] || { echo "unexpected integrity format: $integ"; exit 1; }
	got="sha512-$(openssl dgst -sha512 -binary "$f" | base64 -w0)"
	if [ "$got" != "$integ" ]; then echo "INTEGRITY MISMATCH $name@$v"; rm -f "$f"; exit 1; fi
	d="$dest/${name//\//__}-$v"; rm -rf "$d"; mkdir -p "$d"; tar -xzf "$f" -C "$d" --strip-components=1
	printf '%-38s %-10s %-16s ok (%s)\n' "$name" "$v" "$lic" "$(basename "$url")"
done

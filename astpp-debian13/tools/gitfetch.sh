#!/bin/bash
# Fetch a Composer package version from its git source and verify the commit against Packagist.
#   gitfetch.sh <dest> vendor/package version
set -euo pipefail
dest="$1"; pkg="$2"; ver="$3"
meta=$(curl -fsS "https://repo.packagist.org/p2/${pkg}.json")
read -r url ref <<<"$(php8.5 -r '
$m = json_decode(stream_get_contents(STDIN), true)["packages"][$argv[1]];
$src = null;
foreach ($m as $v) { if (isset($v["source"])) $src = $v["source"]; if ($v["version"] === $argv[2] || $v["version"] === "v" . $argv[2]) { echo $src["url"], " ", $v["source"]["reference"] ?? $src["reference"]; exit; } }
exit(1);' "$pkg" "$ver" <<<"$meta")"
d="$dest/${pkg//\//__}-$ver"
rm -rf "$d"
git -c advice.detachedHead=false clone -q --depth 1 --branch "$ver" "$url" "$d" 2>/dev/null || git -c advice.detachedHead=false clone -q --depth 1 --branch "v$ver" "$url" "$d"
got=$(git -C "$d" rev-parse HEAD)
if [ "$got" != "$ref" ]; then echo "COMMIT MISMATCH $pkg $ver: $got != $ref"; rm -rf "$d"; exit 1; fi
rm -rf "$d/.git"
echo "$pkg $ver ok (commit $ref, $url)"

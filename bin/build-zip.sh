#!/usr/bin/env bash
# Builds the release zip: build/hestahub-<version>.zip, holding one folder
# "hestahub/" with the plugin and its production autoloader, no dev files.
#
# Usage: bin/build-zip.sh [tag]
#
# With a tag (e.g. v0.1.0), fails unless it matches the plugin's version.
# The version must agree in hestahub.php, src/Plugin.php and readme.txt.

set -euo pipefail

ROOT=$(cd "$(dirname "$0")/.." && pwd)
cd "$ROOT"

header=$(sed -n 's/^ \* Version: *//p' hestahub.php)
constant=$(sed -n "s/^.*const VERSION = '\(.*\)';/\1/p" src/Plugin.php)
stable=$(sed -n 's/^Stable tag: *//p' readme.txt)

if [ -z "$header" ] || [ "$header" != "$constant" ] || [ "$header" != "$stable" ]; then
	echo "Version mismatch: hestahub.php '$header', Plugin::VERSION '$constant', readme.txt Stable tag '$stable'." >&2
	exit 1
fi
if [ $# -ge 1 ] && [ "$1" != "v$header" ]; then
	echo "Tag '$1' does not match version '$header'." >&2
	exit 1
fi

OUT="$ROOT/build"
DIR="$OUT/hestahub"
rm -rf "$OUT"
mkdir -p "$DIR"

cp -R hestahub.php uninstall.php readme.txt LICENSE src composer.json composer.lock "$DIR/"
composer install --working-dir="$DIR" --no-dev --classmap-authoritative --no-interaction --quiet
rm "$DIR/composer.json" "$DIR/composer.lock"

(cd "$OUT" && zip -qr "hestahub-$header.zip" hestahub)
echo "$OUT/hestahub-$header.zip"

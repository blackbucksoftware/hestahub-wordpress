#!/usr/bin/env bash
# Builds the release zip, holding one folder "hestahub/" with the plugin and
# its production autoloader, no dev files.
#
# Usage: bin/build-zip.sh [--wporg] [tag]
#
#   (default)  build/hestahub-<version>.zip, with the update channel
#              (updates.php, lib/plugin-update-checker/, the Update URI header).
#              With a tag, also build/hestahub.json: the update manifest whose
#              download_url is that tag's release zip.
#   --wporg    build/hestahub-<version>-wporg.zip, without the update channel,
#              for wordpress.org.
#
# With a tag (e.g. v0.1.0), fails unless it matches the plugin's version.
# The version must agree in hestahub.php, src/Plugin.php and readme.txt.
# BUILD_DIR overrides the output folder (default: build/). It is emptied first.

set -euo pipefail

WPORG=0
TAG=
for arg in "$@"; do
	case "$arg" in
		--wporg) WPORG=1 ;;
		-*) echo "Unknown option: $arg" >&2; exit 1 ;;
		*) TAG=$arg ;;
	esac
done

ROOT=$(cd "$(dirname "$0")/.." && pwd)
cd "$ROOT"

header() {
	sed -n "s/^ \* $1: *//p" hestahub.php
}

version=$(header Version)
constant=$(sed -n "s/^.*const VERSION = '\(.*\)';/\1/p" src/Plugin.php)
stable=$(sed -n 's/^Stable tag: *//p' readme.txt)

if [ -z "$version" ] || [ "$version" != "$constant" ] || [ "$version" != "$stable" ]; then
	echo "Version mismatch: hestahub.php '$version', Plugin::VERSION '$constant', readme.txt Stable tag '$stable'." >&2
	exit 1
fi
if [ -n "$TAG" ] && [ "$TAG" != "v$version" ]; then
	echo "Tag '$TAG' does not match version '$version'." >&2
	exit 1
fi

OUT=${BUILD_DIR:-$ROOT/build}
DIR="$OUT/hestahub"
rm -rf "$OUT"
mkdir -p "$DIR"

cp -R hestahub.php uninstall.php readme.txt LICENSE src composer.json composer.lock "$DIR/"
composer install --working-dir="$DIR" --no-dev --classmap-authoritative --no-interaction --quiet
rm "$DIR/composer.json" "$DIR/composer.lock"

if [ "$WPORG" = 1 ]; then
	sed -i.bak '/^ \* Update URI:/d' "$DIR/hestahub.php"
	rm "$DIR/hestahub.php.bak"
	ZIP="hestahub-$version-wporg.zip"
else
	mkdir -p "$DIR/lib"
	cp -R updates.php "$DIR/"
	cp -R lib/plugin-update-checker "$DIR/lib/"
	ZIP="hestahub-$version.zip"
fi

(cd "$OUT" && zip -qr "$ZIP" hestahub)

if [ "$WPORG" = 0 ] && [ -n "$TAG" ]; then
	REPO=${GITHUB_REPOSITORY:-blackbucksoftware/hestahub-wordpress}
	php -r '
		list( , $version, $url, $requires, $requires_php, $tested, $homepage ) = $argv;
		echo json_encode(
			array(
				"name"         => "HestaHub",
				"slug"         => "hestahub",
				"version"      => $version,
				"download_url" => $url,
				"homepage"     => $homepage,
				"requires"     => $requires,
				"requires_php" => $requires_php,
				"tested"       => $tested,
				"last_updated" => gmdate( "Y-m-d H:i:s" ) . " UTC",
				"sections"     => array(
					"description" => "Shows your HestaHub Apartments, availability and Clinics on your own website.",
					"changelog"   => "See the <a href=\"" . $homepage . "/releases\">releases</a>.",
				),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		) . "\n";
	' "$version" \
		"https://github.com/$REPO/releases/download/$TAG/$ZIP" \
		"$(header 'Requires at least')" \
		"$(header 'Requires PHP')" \
		"$(sed -n 's/^Tested up to: *//p' readme.txt)" \
		"https://github.com/$REPO" \
		> "$OUT/hestahub.json"
	echo "$OUT/hestahub.json"
fi

echo "$OUT/$ZIP"

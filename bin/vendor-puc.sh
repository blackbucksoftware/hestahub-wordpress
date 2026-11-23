#!/usr/bin/env bash
# Vendors YahnisElsts/plugin-update-checker (MIT) into lib/plugin-update-checker/
# at a pinned release tag, replacing what is there.
#
# Usage: bin/vendor-puc.sh <tag>     e.g. bin/vendor-puc.sh v6.0
#
# Only updates.php loads it. Composer must not: the package's own
# composer.json autoloads its loader unconditionally.

set -euo pipefail

if [ $# -ne 1 ]; then
	echo "usage: $0 <tag>" >&2
	exit 1
fi

TAG=$1
ROOT=$(cd "$(dirname "$0")/.." && pwd)
DEST="$ROOT/lib/plugin-update-checker"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

curl -fsSL "https://github.com/YahnisElsts/plugin-update-checker/archive/refs/tags/$TAG.tar.gz" \
	| tar -xz --strip-components=1 -C "$TMP"

# Keep the runtime only.
rm -rf "$TMP/.git"* "$TMP/examples" "$TMP/composer.json" "$TMP/.editorconfig"

rm -rf "$DEST"
mkdir -p "$(dirname "$DEST")"
mv "$TMP" "$DEST"
chmod 755 "$DEST"
echo "$TAG" > "$DEST/VERSION"
echo "Vendored plugin-update-checker $TAG into lib/plugin-update-checker/"

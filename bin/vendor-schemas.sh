#!/usr/bin/env bash
# Vendors the contract's JSON Schemas and examples for one Revision into
# tests/fixtures/api/, for the test suite. The plugin never loads them.
#
# Usage: bin/vendor-schemas.sh [--check] [revision]
#
#   revision   defaults to Client::REVISION in src/Api/Client.php.
#   --check    downloads the published files and fails if the vendored
#              copy differs from them. CI runs this.

set -euo pipefail

CHECK=0
REVISION=
for arg in "$@"; do
	case "$arg" in
		--check) CHECK=1 ;;
		-*) echo "Unknown option: $arg" >&2; exit 1 ;;
		*) REVISION=$arg ;;
	esac
done

ROOT=$(cd "$(dirname "$0")/.." && pwd)
if [ -z "$REVISION" ]; then
	REVISION=$(sed -n "s/^.*const REVISION = '\(.*\)';/\1/p" "$ROOT/src/Api/Client.php")
fi
if [ -z "$REVISION" ]; then
	echo "No Revision given, and none found in src/Api/Client.php." >&2
	exit 1
fi

DEST="$ROOT/tests/fixtures/api"
BASE="https://docs.hestahub.com/v1/$REVISION"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

for endpoint in meta apartments availability clinics error; do
	for kind in schema example; do
		curl -fsSL "$BASE/$endpoint.$kind.json" -o "$TMP/$endpoint.$kind.json"
	done
done
echo "$REVISION" > "$TMP/REVISION"

if [ "$CHECK" = 1 ]; then
	if ! diff -ru "$DEST" "$TMP"; then
		echo "tests/fixtures/api/ differs from the published Revision $REVISION. Run bin/vendor-schemas.sh $REVISION." >&2
		exit 1
	fi
	echo "tests/fixtures/api/ matches the published Revision $REVISION."
	exit 0
fi

rm -rf "$DEST"
mkdir -p "$(dirname "$DEST")"
mv "$TMP" "$DEST"
chmod 755 "$DEST"
echo "Vendored Revision $REVISION into tests/fixtures/api/"

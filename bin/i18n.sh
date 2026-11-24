#!/usr/bin/env bash
# Keeps the translation templates in step with the code. Needs gettext.
#
# Usage: bin/i18n.sh [--check]
#
#   (default)  regenerates languages/hestahub.pot from hestahub.php, src/ and templates/,
#              and merges it into every languages/hestahub-<locale>.po.
#              Then translate what is new or fuzzy in each .po.
#   --check    fails if the .pot is out of date or a .po has an untranslated
#              or fuzzy string. CI runs it.
#
# The .mo files are built by bin/build-zip.sh and never committed.

set -euo pipefail

CHECK=0
case "${1:-}" in
	--check) CHECK=1 ;;
	'') ;;
	*) echo "Unknown option: $1" >&2; exit 1 ;;
esac

ROOT=$(cd "$(dirname "$0")/.." && pwd)
cd "$ROOT"

POT=languages/hestahub.pot
TMP=$(mktemp)
trap 'rm -f "$TMP"' EXIT

find hestahub.php src templates -name '*.php' | sort | xargs xgettext \
	--language=PHP --from-code=UTF-8 --no-location --no-wrap \
	--add-comments=translators: \
	--package-name=HestaHub --msgid-bugs-address=https://github.com/blackbucksoftware/hestahub-wordpress/issues \
	--keyword=__ --keyword=_e --keyword=esc_html__ --keyword=esc_html_e \
	--keyword=esc_attr__ --keyword=esc_attr_e --keyword=_x:1,2c --keyword=esc_html_x:1,2c \
	--keyword=esc_attr_x:1,2c --keyword=_n:1,2 --keyword=_nx:1,2,4c \
	--output="$TMP"
sed -i.bak 's/charset=CHARSET/charset=UTF-8/' "$TMP" && rm -f "$TMP.bak"

strip() {
	grep -v '^"POT-Creation-Date:' "$1"
}

if [ "$CHECK" = 1 ]; then
	status=0
	if ! diff -q <(strip "$TMP") <(strip "$POT") > /dev/null; then
		echo "$POT is out of date. Run bin/i18n.sh." >&2
		status=1
	fi
	for po in languages/hestahub-*.po; do
		stats=$(msgfmt --check --statistics -o /dev/null "$po" 2>&1)
		if echo "$stats" | grep -qE 'untranslated|fuzzy'; then
			echo "$po: $stats" >&2
			status=1
		fi
		if [ -n "$(msgcmp --use-untranslated "$po" "$POT" 2>&1)" ]; then
			echo "$po does not match $POT. Run bin/i18n.sh." >&2
			status=1
		fi
	done
	exit $status
fi

cp "$TMP" "$POT"
for po in languages/hestahub-*.po; do
	[ -e "$po" ] || continue
	msgmerge --quiet --update --backup=none --no-wrap "$po" "$POT"
	msgattrib --no-obsolete --no-wrap -o "$po" "$po"
done
echo "$POT"

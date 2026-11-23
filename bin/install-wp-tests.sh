#!/usr/bin/env bash
# Installs WordPress and the WordPress test suite for PHPUnit.
#
# Usage: bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]
#
# wp-version is "latest" (default) or a release such as "6.8" or "6.8.3".
# Everything lands in .wp-tests/ unless WP_CORE_DIR / WP_TESTS_DIR are set.
# The database is created if it does not exist; it is never dropped.

set -euo pipefail

if [ $# -lt 3 ]; then
	echo "usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version]" >&2
	exit 1
fi

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4-127.0.0.1}
WP_VERSION=${5-latest}

ROOT=$(cd "$(dirname "$0")/.." && pwd)
WP_CORE_DIR=${WP_CORE_DIR-$ROOT/.wp-tests/wordpress}
WP_TESTS_DIR=${WP_TESTS_DIR-$ROOT/.wp-tests/tests}

# Resolve "latest" or "X.Y" to an exact release, so the core and the test
# suite always come from the same tag.
resolve_version() {
	local wanted=$1
	local json
	json=$(curl -fsSL "https://api.wordpress.org/core/stable-check/1.0/")
	php -r '
		$wanted = $argv[1];
		$all = array_keys( json_decode( $argv[2], true ) );
		usort( $all, "version_compare" );
		$all = array_reverse( $all );
		foreach ( $all as $v ) {
			if ( "latest" === $wanted || $v === $wanted || 0 === strpos( $v, $wanted . "." ) ) {
				echo $v;
				exit( 0 );
			}
		}
		fwrite( STDERR, "Unknown WordPress version: $wanted\n" );
		exit( 1 );
	' "$wanted" "$json"
}

VERSION=$(resolve_version "$WP_VERSION")
STAMP="$WP_TESTS_DIR/.version"

if [ -f "$STAMP" ] && [ "$(cat "$STAMP")" = "$VERSION" ] && [ -f "$WP_CORE_DIR/wp-settings.php" ]; then
	echo "WordPress $VERSION and its test suite are already installed."
else
	echo "Installing WordPress $VERSION and its test suite..."
	rm -rf "$WP_CORE_DIR" "$WP_TESTS_DIR"
	mkdir -p "$WP_CORE_DIR" "$WP_TESTS_DIR"

	curl -fsSL "https://wordpress.org/wordpress-$VERSION.tar.gz" \
		| tar -xz --strip-components=1 -C "$WP_CORE_DIR"

	# wordpress-develop tags an X.Y.0 release as "X.Y".
	TAG=${VERSION%.0}
	curl -fsSL "https://github.com/WordPress/wordpress-develop/archive/refs/tags/$TAG.tar.gz" \
		| tar -xz --strip-components=3 -C "$WP_TESTS_DIR" \
			"wordpress-develop-$TAG/tests/phpunit/includes" \
			"wordpress-develop-$TAG/tests/phpunit/data"
	curl -fsSL "https://raw.githubusercontent.com/WordPress/wordpress-develop/$TAG/wp-tests-config-sample.php" \
		-o "$WP_TESTS_DIR/wp-tests-config-sample.php"

	echo "$VERSION" > "$STAMP"
fi

# Always rewrite the config, so changed credentials take effect.
sed \
	-e "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR/':" \
	-e "s:__DIR__ . '/src/':'$WP_CORE_DIR/':" \
	-e "s/youremptytestdbnamehere/$DB_NAME/" \
	-e "s/yourusernamehere/$DB_USER/" \
	-e "s/yourpasswordhere/$DB_PASS/" \
	-e "s|localhost|$DB_HOST|" \
	"$WP_TESTS_DIR/wp-tests-config-sample.php" > "$WP_TESTS_DIR/wp-tests-config.php"

# Create the database if it is missing.
DB_HOSTNAME=${DB_HOST%%:*}
DB_PORT=
if [ "$DB_HOSTNAME" != "$DB_HOST" ]; then
	DB_PORT="--port=${DB_HOST#*:}"
fi
mysql --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOSTNAME" $DB_PORT \
	-e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`"

echo "Done. WP_TESTS_DIR=$WP_TESTS_DIR"

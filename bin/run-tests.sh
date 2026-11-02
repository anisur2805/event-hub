#!/usr/bin/env bash
#
# Runs the Event Hub PHPUnit suite against a WordPress core test install.
#
# wp-env/Docker is the preferred environment, but Docker Hub is unreachable from
# this machine (see docs/DECISIONS.md #19), so this script provisions the same
# thing locally: WP source from wordpress.org, tests/ from develop.svn, and a
# dedicated MySQL database on the Local site's server.
#
# Usage: bin/run-tests.sh [phpunit args...]

set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORK_DIR="${EH_TEST_WORK_DIR:-/private/tmp/eh-wp-tests}"
WP_VERSION="${EH_TEST_WP_VERSION:-7.1.3}"

DB_HOST="${EH_TEST_DB_HOST:-127.0.0.1:10091}"
DB_USER="${EH_TEST_DB_USER:-root}"
DB_PASS="${EH_TEST_DB_PASS:-root}"
DB_NAME="${EH_TEST_DB_NAME:-eh_tests}"

# Local (Flywheel) ships several MySQL builds; pick the newest one that
# exists on this machine. EH_TEST_MYSQL_BIN always wins.
DEFAULT_MYSQL_BIN="$HOME/Library/Application Support/Local/lightning-services"
if [ -z "${EH_TEST_MYSQL_BIN:-}" ]; then
	MYSQL_BIN=""
	for _candidate in "$DEFAULT_MYSQL_BIN"/mysql-8.0*+*/bin/darwin-arm64/bin/mysql \
		"$DEFAULT_MYSQL_BIN"/mysql-8.4*+*/bin/darwin-arm64/bin/mysql; do
		[ -x "$_candidate" ] && MYSQL_BIN="$_candidate" && break
	done
	if [ -z "$MYSQL_BIN" ]; then
		echo "ERROR: no mysql client found under $DEFAULT_MYSQL_BIN." >&2
		echo "Set EH_TEST_MYSQL_BIN=/path/to/mysql and retry." >&2
		exit 1
	fi
else
	MYSQL_BIN="$EH_TEST_MYSQL_BIN"
fi

mkdir -p "$WORK_DIR"
cd "$WORK_DIR"

# 1. WordPress source (CDN tarball; resumable, staged so an interrupted run
#    can never leave a half-moved tree — the old `mv wordpress $WORK_DIR/`
#    failed permanently when the target dir already existed).
if [ ! -f "$WORK_DIR/wordpress/wp-settings.php" ]; then
	echo "==> Fetching WordPress $WP_VERSION source"
	for _ in 1 2 3 4 5 6; do
		curl -sL -C - --max-time 480 -o wp.tar.gz "https://wordpress.org/wordpress-$WP_VERSION.tar.gz" && break
		sleep 2
	done
	rm -rf "$WORK_DIR/.wordpress-staging"
	mkdir -p "$WORK_DIR/.wordpress-staging"
	tar -xzf wp.tar.gz -C "$WORK_DIR/.wordpress-staging" --strip-components=0
	rm -rf "$WORK_DIR/wordpress"
	mv "$WORK_DIR/.wordpress-staging/wordpress" "$WORK_DIR/wordpress"
	rm -rf "$WORK_DIR/.wordpress-staging"
fi

# 2. Core test suite (SVN export is fast and reliable here). Staged like the
#    tarball: svn export refuses an existing destination, and an interrupted
#    earlier run can leave a partial tests/ dir behind.
if [ ! -f "$WORK_DIR/tests/includes/functions.php" ]; then
	echo "==> Exporting WP test suite $WP_VERSION"
	rm -rf "$WORK_DIR/tests"
	svn export -q --non-interactive "https://develop.svn.wordpress.org/tags/$WP_VERSION/tests/phpunit" "$WORK_DIR/tests"
fi

# 3. Test database — never swallow failures: a silent exit here looks like
#    a PHPUnit bug (it was not; see the hint below).
echo "==> Ensuring test database '$DB_NAME'"
if ! "$MYSQL_BIN" -h "${DB_HOST%%:*}" -P "${DB_HOST##*:}" -u "$DB_USER" -p"$DB_PASS" \
	-e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`;"; then
	echo "" >&2
	echo "ERROR: could not connect to MySQL at $DB_HOST as $DB_USER." >&2
	echo "The Local app may expose a different server/port or socket-only root." >&2
	echo "Override the connection and client, e.g.:" >&2
	echo "  EH_TEST_DB_HOST=127.0.0.1:10004 \\" >&2
	echo "  EH_TEST_DB_USER=eh_tests EH_TEST_DB_PASS=... \\" >&2
	echo "  EH_TEST_MYSQL_BIN=\"\$HOME/Library/Application Support/Local/lightning-services/mysql-<ver>/bin/darwin-arm64/bin/mysql\" \\" >&2
	echo "  ./bin/run-tests.sh" >&2
	exit 1
fi

# 4. wp-tests-config.php.
cat > "$WORK_DIR/wp-tests-config.php" <<PHPEOF
<?php
define( 'ABSPATH', '$WORK_DIR/wordpress/' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Event Hub Test Suite' );
define( 'WP_PHP_BINARY', 'php' );
define( 'DB_NAME', '$DB_NAME' );
define( 'DB_USER', '$DB_USER' );
define( 'DB_PASSWORD', '$DB_PASS' );
define( 'DB_HOST', '$DB_HOST' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
\$table_prefix = 'wptests_';
define( 'WP_DEBUG', true );
PHPEOF

# 5. Run.
echo "==> PHPUnit"
cd "$PLUGIN_DIR"
WP_TESTS_DIR="$WORK_DIR/tests" \
WP_TESTS_CONFIG_FILE_PATH="$WORK_DIR/wp-tests-config.php" \
	./vendor/bin/phpunit --configuration=phpunit.xml.dist "$@"

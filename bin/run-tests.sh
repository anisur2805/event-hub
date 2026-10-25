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

MYSQL_BIN="${EH_TEST_MYSQL_BIN:-$HOME/Library/Application Support/Local/lightning-services/mysql-8.4.0+2/bin/darwin-arm64/bin/mysql}"

mkdir -p "$WORK_DIR"
cd "$WORK_DIR"

# 1. WordPress source (CDN tarball; resumable).
if [ ! -f "$WORK_DIR/wordpress/wp-settings.php" ]; then
	echo "==> Fetching WordPress $WP_VERSION source"
	for _ in 1 2 3 4 5 6; do
		curl -sL -C - --max-time 480 -o wp.tar.gz "https://wordpress.org/wordpress-$WP_VERSION.tar.gz" && break
		sleep 2
	done
	tar -xzf wp.tar.gz
	mv "wordpress" "$WORK_DIR/wordpress"
fi

# 2. Core test suite (SVN export is fast and reliable here).
if [ ! -f "$WORK_DIR/tests/includes/functions.php" ]; then
	echo "==> Exporting WP test suite $WP_VERSION"
	svn export -q --non-interactive "https://develop.svn.wordpress.org/tags/$WP_VERSION/tests/phpunit" "$WORK_DIR/tests"
fi

# 3. Test database.
echo "==> Ensuring test database '$DB_NAME'"
"$MYSQL_BIN" -h "${DB_HOST%%:*}" -P "${DB_HOST##*:}" -u "$DB_USER" -p"$DB_PASS" \
	-e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`;" 2>/dev/null

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

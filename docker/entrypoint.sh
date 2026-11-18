#!/bin/sh
# PlayTube container bootstrap: start MariaDB, import the bundled schema,
# seed site settings, generate config.php, then serve with php-fpm + nginx.
set -eu

APP_ROOT=/var/www/html
DATA_DIR=/var/lib/mysql
SOCK=/run/mysqld/mysqld.sock
PORT="${PORT:-8080}"
DB_NAME="${DB_NAME:-playtube}"
DB_USER="${DB_USER:-playtube}"
PASS_FILE="$DATA_DIR/.appjet_db_password"

log() { printf '[playtube] %s\n' "$*"; }

mkdir -p /run/mysqld /var/lib/nginx/tmp /var/lib/nginx/logs "$APP_ROOT/upload"

# ---------------------------------------------------------------- DB password
if [ -n "${DB_PASSWORD:-}" ]; then
    DB_PASS="$DB_PASSWORD"
elif [ -f "$PASS_FILE" ]; then
    DB_PASS="$(cat "$PASS_FILE")"
else
    DB_PASS="$(head -c 48 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | cut -c1-24)"
    printf '%s' "$DB_PASS" > "$PASS_FILE"
    chmod 600 "$PASS_FILE"
fi

# ------------------------------------------------------------- MariaDB server
if [ ! -d "$DATA_DIR/mysql" ]; then
    log "initialising MariaDB data directory"
    # The container runs unprivileged, so root must use password auth over the
    # socket instead of unix_socket auth (which would require the root OS user).
    if ! mariadb-install-db --user="$(id -un)" --datadir="$DATA_DIR" --skip-test-db \
            --auth-root-authentication-method=normal >/tmp/mariadb-install.log 2>&1; then
        cat /tmp/mariadb-install.log
        exit 1
    fi
fi

log "starting MariaDB"
mariadbd --user="$(id -un)" --datadir="$DATA_DIR" --socket="$SOCK" --bind-address=127.0.0.1 --port=3306 \
         --skip-networking=0 --skip-name-resolve --skip-log-bin --innodb-buffer-pool-size=128M &
DB_PID=$!
FPM_PID=""

shutdown_all() {
    [ -n "$FPM_PID" ] && kill "$FPM_PID" 2>/dev/null || true
    mariadb-admin --socket="$SOCK" -uroot shutdown 2>/dev/null || kill "$DB_PID" 2>/dev/null || true
}
trap 'shutdown_all; exit 0' INT TERM

i=0
until mariadb --protocol=socket --socket="$SOCK" -uroot -e 'SELECT 1' >/dev/null 2>&1; do
    i=$((i + 1))
    if [ "$i" -gt 60 ]; then
        log "MariaDB did not become ready"
        tail -n 40 "$DATA_DIR"/*.err 2>/dev/null || true
        exit 1
    fi
    sleep 1
done
log "MariaDB ready"

# ------------------------------------------------------------ database + user
mariadb --protocol=socket --socket="$SOCK" -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL

TABLE_COUNT="$(mariadb --protocol=socket --socket="$SOCK" -uroot -N -B \
    -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$DB_NAME'")"
if [ "${TABLE_COUNT:-0}" -eq 0 ]; then
    log "importing playtube.sql schema"
    if ! mariadb --protocol=socket --socket="$SOCK" -uroot "$DB_NAME" < "$APP_ROOT/playtube.sql" 2>/tmp/import.log; then
        log "warning: schema import reported errors"
        tail -n 20 /tmp/import.log || true
    fi
fi

# ------------------------------------------------------------------ config.php
log "writing config.php"
cat > "$APP_ROOT/config.php" <<PHP
<?php
// Generated on container start by the AppJet deployment bootstrap.
\$sql_db_host = "localhost";
\$sql_db_user = "$DB_USER";
\$sql_db_pass = "$DB_PASS";
\$sql_db_name = "$DB_NAME";

// Site URL follows the request host so the same image works on any domain.
// The public deployment terminates TLS at a proxy, so trust proxy headers
// instead of only checking the FastCGI HTTPS flag.
\$__scheme = 'http';
\$__host = isset(\$_SERVER['HTTP_HOST']) ? strtolower(\$_SERVER['HTTP_HOST']) : 'localhost';
\$__proto = isset(\$_SERVER['HTTP_X_FORWARDED_PROTO']) ? strtolower(trim(explode(',', \$_SERVER['HTTP_X_FORWARDED_PROTO'])[0])) : '';
if (!empty(\$_SERVER['HTTPS']) && strtolower(\$_SERVER['HTTPS']) !== 'off') {
    \$__scheme = 'https';
} elseif (\$__proto !== '') {
    \$__scheme = \$__proto === 'https' ? 'https' : 'http';
} elseif (!empty(\$_SERVER['HTTP_X_FORWARDED_SSL']) && \$_SERVER['HTTP_X_FORWARDED_SSL'] === 'on') {
    \$__scheme = 'https';
} elseif (!empty(\$_SERVER['HTTP_CF_VISITOR']) && strpos(\$_SERVER['HTTP_CF_VISITOR'], 'https') !== false) {
    \$__scheme = 'https';
} elseif (strpos(\$__host, 'localhost') === false && strpos(\$__host, '127.0.0.1') === false) {
    \$__scheme = 'https';
}
\$site_url = \$__scheme . '://' . \$__host;

\$purchase_code = "";
\$auto_redirect = false;

// PlayTube predates PHP 8.1: keep mysqli returning false instead of throwing.
mysqli_report(MYSQLI_REPORT_OFF);
PHP

# ------------------------------------------------------------- site + account
log "seeding site configuration"
DB_HOST=localhost DB_PORT=3306 DB_NAME="$DB_NAME" DB_USER="$DB_USER" DB_PASS="$DB_PASS" \
SITE_NAME="${SITE_NAME:-PlayTube}" \
SITE_TITLE="${SITE_TITLE:-PlayTube - Video Sharing Platform}" \
SITE_EMAIL="${SITE_EMAIL:-admin@example.com}" \
ADMIN_USERNAME="${ADMIN_USERNAME:-admin}" \
ADMIN_PASSWORD="${ADMIN_PASSWORD:-admin123456}" \
php /usr/local/bin/seed-config.php || log "warning: seeding reported an error"

# ---------------------------------------------------------------- php-fpm + nginx
log "starting php-fpm"
php-fpm -y /usr/local/etc/php-fpm.conf &
FPM_PID=$!

sed -i -E "s#^([[:space:]]*)listen [0-9]+;#\1listen ${PORT};#" /etc/nginx/nginx.conf

log "starting nginx on port $PORT"
exec nginx -g 'daemon off;'

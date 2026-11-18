#!/bin/sh
# PlayTube container bootstrap: start MariaDB, import the bundled schema,
# seed site settings, generate config.php, then run Apache in the foreground.
set -eu

APP_ROOT=/var/www/html
DATA_DIR=/var/lib/mysql
SOCK=/run/mysqld/mysqld.sock
PORT="${PORT:-8080}"
DB_NAME="${DB_NAME:-playtube}"
DB_USER="${DB_USER:-playtube}"
PASS_FILE="$DATA_DIR/.appjet_db_password"

log() { printf '[playtube] %s\n' "$*"; }

mkdir -p /run/mysqld /run/apache2 /var/log/apache2 "$APP_ROOT/upload"

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
    if ! mariadb-install-db --user="$(id -un)" --datadir="$DATA_DIR" --skip-test-db >/tmp/mariadb-install.log 2>&1; then
        cat /tmp/mariadb-install.log
        exit 1
    fi
fi

log "starting MariaDB"
mariadbd --datadir="$DATA_DIR" --socket="$SOCK" --bind-address=127.0.0.1 --port=3306 \
         --skip-name-resolve --skip-log-bin --innodb-buffer-pool-size=128M &
DB_PID=$!

shutdown_db() {
    mariadb-admin --socket="$SOCK" -uroot shutdown 2>/dev/null || kill "$DB_PID" 2>/dev/null || true
}
trap 'shutdown_db; exit 0' INT TERM

i=0
until mariadb --protocol=socket --socket="$SOCK" -uroot -e 'SELECT 1' >/dev/null 2>&1; do
    i=$((i + 1))
    if [ "$i" -gt 90 ]; then
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
\$sql_db_host = "127.0.0.1";
\$sql_db_user = "$DB_USER";
\$sql_db_pass = "$DB_PASS";
\$sql_db_name = "$DB_NAME";

// Site URL follows the request host so the same image works on any domain.
\$site_url = ((!empty(\$_SERVER['HTTPS']) && \$_SERVER['HTTPS'] !== 'off')
    || (!empty(\$_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower(\$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https'))
    ? 'https://' : 'http://';
\$site_url .= isset(\$_SERVER['HTTP_HOST']) ? \$_SERVER['HTTP_HOST'] : 'localhost';

\$purchase_code = "";
\$auto_redirect = false;

// PlayTube predates PHP 8.1: keep mysqli returning false instead of throwing.
mysqli_report(MYSQLI_REPORT_OFF);
PHP

# ------------------------------------------------------------- site + account
log "seeding site configuration"
DB_HOST=127.0.0.1 DB_PORT=3306 DB_NAME="$DB_NAME" DB_USER="$DB_USER" DB_PASS="$DB_PASS" \
SITE_NAME="${SITE_NAME:-PlayTube}" \
SITE_TITLE="${SITE_TITLE:-PlayTube - Video Sharing Platform}" \
SITE_EMAIL="${SITE_EMAIL:-admin@example.com}" \
ADMIN_USERNAME="${ADMIN_USERNAME:-admin}" \
ADMIN_PASSWORD="${ADMIN_PASSWORD:-admin123456}" \
php83 /usr/local/bin/seed-config.php || log "warning: seeding reported an error"

# ------------------------------------------------------------------- Apache
log "configuring Apache on port $PORT"
sed -i -E 's/^[[:space:]]*Listen[[:space:]].*/# Listen is defined in conf.d\/zz-playtube.conf/' /etc/apache2/httpd.conf
sed -i -E 's/^User[[:space:]].*/User app/; s/^Group[[:space:]].*/Group app/' /etc/apache2/httpd.conf
sed -i -E "s#^Listen .*#Listen ${PORT}#; s#<VirtualHost \*:[0-9]+>#<VirtualHost *:${PORT}>#" /etc/apache2/conf.d/zz-playtube.conf

MODULE_CONF=/etc/apache2/conf.d/00-appjet-modules.conf
: > "$MODULE_CONF"
for mod in mpm_prefork authz_core authz_host authn_core authn_file auth_basic access_compat \
           rewrite headers expires deflate filter setenvif alias dir mime negotiation env; do
    f="/usr/lib/apache2/modules/mod_${mod}.so"
    [ -f "$f" ] || continue
    grep -qs "mod_${mod}\.so" /etc/apache2/httpd.conf && continue
    echo "LoadModule ${mod}_module ${f}" >> "$MODULE_CONF"
done

log "starting Apache"
exec httpd -D FOREGROUND -f /etc/apache2/httpd.conf

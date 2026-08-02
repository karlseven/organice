#!/usr/bin/env sh
# organice DB installer for Linux and macOS — the counterpart to install.ps1.
#
# Reads .env for the database name and credentials, creates the database if it
# is missing, then runs schema.sql and procedures.sql against it.
#
# Order matters: schema.sql sets the database's default collation, and stored
# procedures capture that default at CREATE time. Procedures must always be
# (re)created after it — see the comment at the top of schema.sql.
#
# Neither .sql file contains a USE statement, on purpose: that is what lets you
# install into a database of any name. It also means they cannot be piped in
# without naming one, which is what this script exists to get right.

set -eu

here=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
root=$(dirname "$here")

command -v mysql >/dev/null 2>&1 || { echo "mysql client not found — apt install mysql-client" >&2; exit 1; }
[ -f "$root/.env" ] || { echo "No .env — copy .env.example to .env and fill it in first" >&2; exit 1; }

get() {
  # First match wins, comments and blank lines skipped, value trimmed.
  sed -n "s/^[[:space:]]*$1[[:space:]]*=[[:space:]]*\(.*\)[[:space:]]*$/\1/p" "$root/.env" | head -n 1
}

DB_NAME=$(get DB_NAME); DB_USER=$(get DB_USER)
DB_HOST=$(get DB_HOST); DB_HOST=${DB_HOST:-127.0.0.1}

[ -n "$DB_NAME" ] && [ -n "$DB_USER" ] || { echo "DB_NAME and DB_USER must be set in .env" >&2; exit 1; }

echo "Installing into '$DB_NAME' as '$DB_USER'..."

# MYSQL_PWD keeps the password off the command line, where `ps` would show it
# to every other user on the box.
MYSQL_PWD=$(get DB_PASS); export MYSQL_PWD
trap 'unset MYSQL_PWD' EXIT

# Created with the right collation from the start; schema.sql re-asserts it
# anyway, so a pre-existing database is corrected too.
mysql -u "$DB_USER" -h "$DB_HOST" \
  -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"

mysql -u "$DB_USER" -h "$DB_HOST" -D "$DB_NAME" < "$here/schema.sql"
echo "  schema.sql      ok"

mysql -u "$DB_USER" -h "$DB_HOST" -D "$DB_NAME" < "$here/procedures.sql"
echo "  procedures.sql  ok"

cat <<'NEXT'

Done. Next:
  php scripts/seed.php you@example.com "a-long-password"
  php -S localhost:8080 -t public public/index.php
NEXT

#!/usr/bin/env bash
#
# Design Review, etap 03 — próba migracji 2026_11_03_000100 na kopii bazy, zanim dotknie bazy strony.
#
# 1. Kopia (`db-backup model03-copy`) i odtworzenie na tymczasowym MySQL 8.4 z Local: bez sieci, własny socket,
#    `--skip-grant-tables` (WP łączy się swoimi danymi, których test nie czyta), baza o nazwie bazy strony.
# 2. WP-CLI z socketem kopii. Znacznik `fmi_isolation_marker` istnieje tylko w kopii — bez niego test przerywa
#    pracę przed migracją (dowód, że WP-CLI nie trafił do bazy strony).
# 3. Migawki: przed migracją → `acorn migrate` → po → `acorn migrate:rollback --step=1` → po wycofaniu → ponowna
#    migracja. Porównanie: tests/model03/compare.php (projekty, właściciele, członkowie, hash treści, linki,
#    capabilities, CHECKSUM wszystkich tabel, klucze obce, sieroty).
#
# Baza strony jest tylko czytana (kopia). Katalog kopii zostaje w $TMPDIR (dane bazy) — usuwasz go ręcznie.
#
# Użycie: bash tests/model03/run.sh   Kody: 0 PASS, 1 FAIL, 2 BLOCKED.

set -uo pipefail

THEME="$(cd "$(dirname "$0")/../.." && pwd)"
source "$THEME/tests/bin/env.sh"
fmi_bootstrap || exit 2

MYSQL_BASE="$FMI_LOCAL_DIR/lightning-services/mysql-8.4.0+2/bin/darwin-arm64"
[ -x "$MYSQL_BASE/bin/mysqld" ] || { echo "BLOCKED: brak mysqld 8.4.0 z Local."; exit 2; }
command -v mysqldump >/dev/null || export PATH="$PATH:$MYSQL_BASE/bin"

WORK="$(mktemp -d "${TMPDIR:-/tmp}/fmi-model03.XXXXXX")" || exit 2
chmod 700 "$WORK"
RSOCK="$WORK/mysqld.sock"
MYSQL=("$MYSQL_BASE/bin/mysql" --no-defaults -uroot --socket="$RSOCK")

stop_server() {
  [ -S "$RSOCK" ] && "$MYSQL_BASE/bin/mysqladmin" --no-defaults -uroot --socket="$RSOCK" shutdown >/dev/null 2>&1
}
trap stop_server EXIT

copywp() {
  php -d display_errors=stderr -d "mysqli.default_socket=$RSOCK" -d "pdo_mysql.default_socket=$RSOCK" "$FMI_WP_BIN" --path="$FMI_WP_PATH" "$@"
}

echo "### 1. Kopia i izolowany serwer"
OUT="$(bash "$THEME/tests/bin/wp.sh" db-backup model03-copy)" || { echo "FAIL: db-backup"; exit 1; }
BACKUP="$(printf '%s\n' "$OUT" | sed -n 's/^Plik: //p')"
echo "Kopia: $BACKUP"
fmiwp eval-file "$THEME/tests/restore/live.php" "$WORK/live.json" || exit 1
DBNAME="$(fmiwp eval 'echo DB_NAME;' 2>/dev/null)"
[[ "$DBNAME" =~ ^[A-Za-z0-9_]+$ ]] || { echo "BLOCKED: nazwa bazy"; exit 2; }

"$MYSQL_BASE/bin/mysqld" --no-defaults --initialize-insecure --basedir="$MYSQL_BASE" --datadir="$WORK/data" --log-error="$WORK/init.log" \
  || { echo "BLOCKED: inicjalizacja (log: $WORK/init.log)"; exit 2; }
"$MYSQL_BASE/bin/mysqld" --no-defaults --basedir="$MYSQL_BASE" --datadir="$WORK/data" --socket="$RSOCK" --pid-file="$WORK/mysqld.pid" \
  --skip-networking --skip-grant-tables --mysqlx=OFF --log-error="$WORK/mysqld.log" &
for _ in $(seq 1 60); do "$MYSQL_BASE/bin/mysqladmin" --no-defaults -uroot --socket="$RSOCK" ping >/dev/null 2>&1 && break; sleep 1; done
"$MYSQL_BASE/bin/mysqladmin" --no-defaults -uroot --socket="$RSOCK" ping >/dev/null 2>&1 || { echo "BLOCKED: serwer kopii nie wystartował"; exit 2; }

"${MYSQL[@]}" -e "CREATE DATABASE \`$DBNAME\` CHARACTER SET utf8mb4" || exit 1
"${MYSQL[@]}" "$DBNAME" < "$BACKUP" || { echo "FAIL: import kopii"; exit 1; }
"${MYSQL[@]}" "$DBNAME" -e 'CREATE TABLE fmi_isolation_marker (id INT PRIMARY KEY)' || exit 1
PREFIX="$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["prefix"];' "$WORK/live.json")"
php "$THEME/tests/restore/restored.php" "$RSOCK" "$DBNAME" "$PREFIX" "$WORK/restored.json" || exit 1
php "$THEME/tests/restore/compare.php" "$WORK/live.json" "$WORK/restored.json" | tail -1

echo "### 2. Izolacja WP-CLI"
copywp eval-file "$THEME/tests/model03/projects.php" "$WORK/before.json" || { echo "BLOCKED: WP-CLI na kopii"; exit 2; }
[ "$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["marker"] ? 1 : 0;' "$WORK/before.json")" = 1 ] \
  || { echo "BLOCKED: WP-CLI nie łączy się z kopią — migracja przerwana."; exit 2; }
echo "PASS znacznik izolacji widoczny przez WP-CLI"
fmiwp eval-file "$THEME/tests/model03/projects.php" "$WORK/live-check.json" >/dev/null 2>&1
[ "$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["marker"] ? 1 : 0;' "$WORK/live-check.json")" = 0 ] \
  || { echo "BLOCKED: znacznik w bazie strony?"; exit 2; }

echo "### 3. Migracja, wycofanie, ponowna migracja (kopia)"
copywp acorn migrate:status 2>&1 | grep -E "2026_11_03" || true
copywp acorn migrate --force || { echo "FAIL: migracja na kopii"; exit 1; }
copywp eval-file "$THEME/tests/model03/projects.php" "$WORK/after.json" || exit 1
php "$THEME/tests/restore/restored.php" "$RSOCK" "$DBNAME" "$PREFIX" "$WORK/checks-after.json" || exit 1
copywp acorn migrate:rollback --step=1 --force || { echo "FAIL: wycofanie na kopii"; exit 1; }
copywp eval-file "$THEME/tests/model03/projects.php" "$WORK/rollback.json" || exit 1
php "$THEME/tests/restore/restored.php" "$RSOCK" "$DBNAME" "$PREFIX" "$WORK/checks-rollback.json" || exit 1
copywp acorn migrate --force || { echo "FAIL: ponowna migracja na kopii"; exit 1; }
copywp eval-file "$THEME/tests/model03/projects.php" "$WORK/again.json" || exit 1

echo "### 4. Porównanie"
php "$THEME/tests/model03/compare.php" "$WORK/before.json" "$WORK/after.json" "$WORK/rollback.json" "$WORK/again.json" \
  "$WORK/restored.json" "$WORK/checks-after.json" "$WORK/checks-rollback.json"
CODE=$?
echo "Katalog kopii (usuń ręcznie): $WORK"
exit $CODE

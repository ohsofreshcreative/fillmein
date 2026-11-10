#!/usr/bin/env bash
#
# Kopia zapasowa i próba jej odtworzenia na izolowanej bazie.
#
# 1. `tests/bin/wp.sh db-backup restore-check` → plik ~/Desktop/fillmein-pre-restore-check-*.sql.
# 2. Sprawdzenia bazy strony zaraz po eksporcie (liczebność, CHECKSUM tabel fmi, klucze obce, sieroty).
# 3. Tymczasowy serwer MySQL z Local (ta sama wersja co strona), bez sieci (`--skip-networking`), własny socket
#    i katalog danych w $TMPDIR. Import kopii do bazy `fmi_restore` i te same sprawdzenia.
# 4. Porównanie (compare.php). Serwer jest zatrzymywany zawsze (trap).
#
# Strona Local i jej baza są tylko czytane. Katalog tymczasowy z odtworzoną kopią zostaje w $TMPDIR (prywatny
# katalog użytkownika); ścieżka jest wypisywana, bo kopia zawiera dane bazy. Usuwasz go ręcznie.
#
# Użycie: bash tests/restore/run.sh   Kody: 0 PASS, 1 FAIL, 2 BLOCKED.

set -uo pipefail

THEME="$(cd "$(dirname "$0")/../.." && pwd)"
source "$THEME/tests/bin/env.sh"
fmi_bootstrap || exit 2

MYSQL_BASE="$FMI_LOCAL_DIR/lightning-services/mysql-8.4.0+2/bin/darwin-arm64"
[ -x "$MYSQL_BASE/bin/mysqld" ] || { echo "BLOCKED: brak mysqld 8.4.0 z Local."; exit 2; }
command -v mysqldump >/dev/null || export PATH="$MYSQL_BASE/bin:$PATH"

WORK="$(mktemp -d "${TMPDIR:-/tmp}/fmi-restore.XXXXXX")" || exit 2
chmod 700 "$WORK"
DATA="$WORK/data"
RSOCK="$WORK/mysqld.sock"
PID="$WORK/mysqld.pid"

stop_server() {
  if [ -S "$RSOCK" ]; then
    "$MYSQL_BASE/bin/mysqladmin" --no-defaults -uroot --socket="$RSOCK" shutdown >/dev/null 2>&1
  fi
}
trap stop_server EXIT

echo "### 1. Kopia zapasowa"
OUT="$(bash "$THEME/tests/bin/wp.sh" db-backup restore-check)" || { echo "FAIL: db-backup"; exit 1; }
echo "$OUT"
BACKUP="$(printf '%s\n' "$OUT" | sed -n 's/^Plik: //p')"
[ -s "$BACKUP" ] || { echo "FAIL: brak pliku kopii"; exit 1; }
echo "SHA-256: $(shasum -a 256 "$BACKUP" | cut -d' ' -f1)"

echo "### 2. Sprawdzenia bazy strony"
fmiwp eval-file "$THEME/tests/restore/live.php" "$WORK/live.json" || { echo "FAIL: sprawdzenia strony"; exit 1; }
PREFIX="$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["prefix"];' "$WORK/live.json")"

echo "### 3. Izolowany serwer MySQL i import"
"$MYSQL_BASE/bin/mysqld" --no-defaults --initialize-insecure --basedir="$MYSQL_BASE" --datadir="$DATA" --log-error="$WORK/init.log" \
  || { echo "BLOCKED: inicjalizacja katalogu danych (log: $WORK/init.log)"; exit 2; }
"$MYSQL_BASE/bin/mysqld" --no-defaults --basedir="$MYSQL_BASE" --datadir="$DATA" --socket="$RSOCK" --pid-file="$PID" \
  --skip-networking --mysqlx=OFF --log-error="$WORK/mysqld.log" &

for _ in $(seq 1 60); do
  "$MYSQL_BASE/bin/mysqladmin" --no-defaults -uroot --socket="$RSOCK" ping >/dev/null 2>&1 && break
  sleep 1
done
"$MYSQL_BASE/bin/mysqladmin" --no-defaults -uroot --socket="$RSOCK" ping >/dev/null 2>&1 || { echo "BLOCKED: serwer nie wystartował (log: $WORK/mysqld.log)"; exit 2; }

MYSQL=("$MYSQL_BASE/bin/mysql" --no-defaults -uroot --socket="$RSOCK")
"${MYSQL[@]}" -e 'CREATE DATABASE fmi_restore CHARACTER SET utf8mb4' || exit 1
START=$SECONDS
"${MYSQL[@]}" fmi_restore < "$BACKUP" || { echo "FAIL: import kopii"; exit 1; }
echo "Import: $((SECONDS - START)) s"

php "$THEME/tests/restore/restored.php" "$RSOCK" fmi_restore "$PREFIX" "$WORK/restored.json" || { echo "FAIL: sprawdzenia kopii"; exit 1; }

echo "### 4. Porównanie"
php "$THEME/tests/restore/compare.php" "$WORK/live.json" "$WORK/restored.json"
CODE=$?

echo "Kopia: $BACKUP"
echo "Katalog odtworzenia (usuń ręcznie): $WORK"
exit $CODE

#!/usr/bin/env bash
#
# Nakładka na WP-CLI dla lokalnego LocalWP (fillmein.local) z zamkniętą listą podkomend.
#
# Dozwolone:
#   acorn fmi:check
#   acorn migrate:status
#   acorn route:list [--name=<wzorzec>] [--path=<wzorzec>]
#   acorn view:cache
#   acorn view:clear
#   acorn migrate --backup=<plik.sql>   (plik istnieje, > 0 B, młodszy niż 60 min, poza katalogiem motywu)
#   acorn fmi:import <plik.zip|plik.json> [--apply --backup=<plik.sql>]
#   acorn fmi:import:cleanup [--source=<źródło>] [--apply --backup=<plik.sql>]
#   db-backup <etykieta>                (^[a-z0-9-]{1,40}$ → ~/Desktop/fillmein-pre-<etykieta>-YYYYmmdd-HHMMSS.sql)
#
# Kody: 0 OK, 2 BLOCKED (brak socketu / zła instalacja), 4 odmowa, inne — kod WP-CLI.
# Nakładka nie wypisuje haseł ani zawartości wp-config.php.
#
# Użycie: tests/bin/wp.sh <podkomenda> [argumenty]

set -uo pipefail

THEME="$(cd "$(dirname "$0")/../.." && pwd -P)"

deny() { echo "ODMOWA: $*" >&2; exit 4; }
not_allowed() { deny "Niedozwolona podkomenda: ${*:-(brak)}"; }

# Opcje poleceń importu: --apply wymaga --backup, a --backup bez --apply jest odrzucane. Warunki backupu jak dla migrate.
import_options() {
  local apply=""
  IMPORT_ARGS=()
  for opt in "$@"; do
    case "$opt" in
      --apply) apply=1; IMPORT_ARGS+=("--apply") ;;
      --backup=*) BACKUP="${opt#--backup=}"; [ -n "$BACKUP" ] || deny "pusta ścieżka backupu."; IMPORT_ARGS+=("$opt") ;;
      --source=*)
        [[ "$opt" =~ ^--source=[a-z0-9-]+:[0-9a-f-]{36}$ ]] || deny "--source ma format <slug>:<uuid>."
        IMPORT_ARGS+=("$opt")
        ;;
      *) deny "Niedozwolona opcja importu: $opt" ;;
    esac
  done
  if [ -n "$apply" ]; then
    [ -n "$BACKUP" ] || deny "--apply wymaga --backup=<plik.sql>."
  else
    [ -z "$BACKUP" ] || deny "--backup jest dozwolone tylko z --apply."
  fi
}

# --- 1. Walidacja podkomendy (przed jakimkolwiek dostępem do bazy) ---

MODE=""
ARGS=()
BACKUP=""
LABEL=""

case "${1:-}" in
  acorn)
    case "${2:-}" in
      fmi:check|migrate:status|view:cache|view:clear)
        [ "$#" -eq 2 ] || deny "acorn $2 nie przyjmuje argumentów."
        MODE="acorn"; ARGS=("$2")
        ;;
      route:list)
        MODE="acorn"; ARGS=("route:list")
        for opt in "${@:3}"; do
          [[ "$opt" =~ ^--(name|path)=[A-Za-z0-9._/*{}-]{1,100}$ ]] || deny "route:list: dozwolone tylko --name=<wzorzec> i --path=<wzorzec>."
          ARGS+=("$opt")
        done
        ;;
      migrate)
        [ "$#" -eq 3 ] && [[ "$3" == --backup=* ]] || deny "acorn migrate wymaga dokładnie jednego argumentu --backup=<plik.sql>."
        BACKUP="${3#--backup=}"
        MODE="migrate"
        ;;
      fmi:import)
        [[ " ${*:3} " != *" --source="* ]] || deny "fmi:import nie przyjmuje --source."
        IMPORT_PATH="${3:-}"
        [[ "$IMPORT_PATH" =~ ^/[A-Za-z0-9._/-]+\.(json|zip)$ ]] && [[ "$IMPORT_PATH" != *..* ]] || deny "fmi:import wymaga ścieżki bezwzględnej do pliku .json lub .zip."
        import_options "${@:4}"
        MODE="acorn"; ARGS=("fmi:import" "$IMPORT_PATH" "${IMPORT_ARGS[@]+"${IMPORT_ARGS[@]}"}")
        ;;
      fmi:import:cleanup)
        import_options "${@:3}"
        MODE="acorn"; ARGS=("fmi:import:cleanup" "${IMPORT_ARGS[@]+"${IMPORT_ARGS[@]}"}")
        ;;
      migrate:fresh|migrate:reset|migrate:rollback|migrate:refresh|db:wipe)
        deny "acorn $2 jest jawnie zabronione."
        ;;
      *) not_allowed "$@" ;;
    esac
    ;;
  db-backup)
    [ "$#" -eq 2 ] || deny "db-backup wymaga dokładnie jednej etykiety."
    [[ "$2" =~ ^[a-z0-9-]{1,40}$ ]] || deny "etykieta musi pasować do ^[a-z0-9-]{1,40}\$."
    LABEL="$2"; MODE="backup"
    ;;
  db)
    case "${2:-}" in
      reset|drop|clean|import|query|cli) deny "db ${2} jest jawnie zabronione." ;;
      *) not_allowed "$@" ;;
    esac
    ;;
  *) not_allowed "$@" ;;
esac

# --- 2. Warunki backupu dla migrate i importu (przed dostępem do bazy) ---

if [ "$MODE" = "migrate" ] || [ -n "$BACKUP" ]; then
  [ -n "$BACKUP" ] || deny "pusta ścieżka backupu."
  [ ! -L "$BACKUP" ] || deny "plik backupu nie może być dowiązaniem symbolicznym."
  [ -f "$BACKUP" ] || deny "plik backupu nie istnieje: $BACKUP"
  [ -s "$BACKUP" ] || deny "plik backupu ma 0 B."
  BACKUP_DIR="$(cd "$(dirname "$BACKUP")" && pwd -P)" || deny "nie można ustalić katalogu backupu."
  BACKUP_REAL="$BACKUP_DIR/$(basename "$BACKUP")"
  case "$BACKUP_REAL/" in
    "$THEME"/*) deny "plik backupu leży w katalogu motywu." ;;
  esac
  AGE=$(( $(date +%s) - $(stat -f %m "$BACKUP_REAL") ))
  [ "$AGE" -ge 0 ] && [ "$AGE" -lt 3600 ] || deny "plik backupu jest starszy niż 60 min (${AGE} s)."
  echo "Backup OK: $BACKUP_REAL ($(stat -f %z "$BACKUP_REAL") B, ${AGE} s temu)"
fi

# --- 3. Socket MySQL LocalWP i kontrola instalacji (tests/bin/env.sh) ---

source "$THEME/tests/bin/env.sh"
fmi_bootstrap || exit 2

# --- 4. Wykonanie ---

case "$MODE" in
  acorn)
    fmiwp acorn "${ARGS[@]}"
    exit $?
    ;;
  migrate)
    fmiwp acorn migrate --force
    exit $?
    ;;
  backup)
    OUT="$HOME/Desktop/fillmein-pre-$LABEL-$(date +%Y%m%d-%H%M%S).sql"
    [ ! -e "$OUT" ] || deny "plik już istnieje: $OUT"
    umask 077
    fmiwp db export "$OUT" --socket="$SOCK" --quiet || { echo "BŁĄD: wp db export zakończył się niepowodzeniem." >&2; exit 1; }
    [ -s "$OUT" ] || { echo "BŁĄD: plik backupu jest pusty." >&2; exit 1; }
    grep -q "fillmein\.local" "$OUT" || { echo "BŁĄD: w backupie brak siteurl fillmein.local." >&2; exit 1; }
    echo "Plik: $OUT"
    echo "Rozmiar: $(stat -f %z "$OUT") B"
    echo "Tabele wp_fmi_*: $(grep -c '^CREATE TABLE `wp_fmi_' "$OUT")"
    exit 0
    ;;
esac

not_allowed "$@"

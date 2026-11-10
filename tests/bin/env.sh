# shellcheck shell=bash
#
# Wspólne środowisko testów na lokalnym LocalWP (fillmein.local). Dołączaj przez `source`.
#
# Funkcje:
#   fmi_require_tools   php i wp w PATH (ustawia FMI_WP_BIN)
#   fmi_detect_sock     socket MySQL strony: SOCK → sites.json → skan katalogu run/ (eksportuje SOCK)
#   fmiwp               WP-CLI z socketem dla mysqli i pdo_mysql
#   fmi_check_install   siteurl = http://fillmein.local
#   fmi_load_secrets    FMI_FIGMA_TOKEN i FMI_FIGMA_LIVE_URL z .ai-workflow/secrets/ (gdy puste); na stderr tylko tak/nie
#   fmi_bootstrap       wszystkie powyższe po kolei
#
# Każda funkcja kontrolna przy porażce wypisuje BLOCKED i zwraca 2 (wywołujący robi `|| exit 2`).
# Plik nie wypisuje haseł, tokenu Figmy, linku do Figmy ani zawartości sites.json / wp-config.php.

# Sesje Claude Code startują z okrojonym PATH (bez Homebrew) — wtedy php, wp, node i yarn są niewidoczne.
export PATH="$PATH:/opt/homebrew/bin:/usr/local/bin"

# OPcache w CLI zapisuje blokadę w opcache.lockfile_path (/tmp), a sandbox dopuszcza zapis tylko do $TMPDIR.
# Wyłączenie OPcache w CLI nie zmienia wyniku testów, a usuwa ten zapis. Dotyczy wszystkich wywołań php w testach.
php() { command php -d opcache.enable_cli=0 "$@"; }

FMI_WP_PATH="/Users/Arek/Websites/fillmein/app/public"
FMI_SITEURL="http://fillmein.local"
FMI_DOMAIN="fillmein.local"
FMI_LOCAL_DIR="$HOME/Library/Application Support/Local"

fmi_require_tools() {
  command -v php >/dev/null && command -v wp >/dev/null || { echo "BLOCKED: brak php lub wp w PATH."; return 2; }
  FMI_WP_BIN="$(command -v wp)"
}

# display_errors=stderr: ostrzeżenia Deprecated z phara WP-CLI nie mogą trafiać do stdout (psują porównanie siteurl).
fmi_siteurl_for() {
  php -d display_errors=stderr -d "mysqli.default_socket=$1" -d "pdo_mysql.default_socket=$1" "$FMI_WP_BIN" --path="$FMI_WP_PATH" option get siteurl 2>/dev/null
}

fmi_sock_ok() { [ -S "$1" ] && [ "$(fmi_siteurl_for "$1")" = "$FMI_SITEURL" ]; }

fmi_detect_sock() {
  local source="" candidate id

  # 1. Jawnie podany SOCK — bez cichego przejścia do kolejnych kroków.
  if [ -n "${SOCK:-}" ]; then
    fmi_sock_ok "$SOCK" || { echo "BLOCKED: SOCK wskazuje inną stronę lub nie istnieje."; return 2; }
    source="env"
  fi

  # 2. sites.json: odczyt WYŁĄCZNIE id wpisu o domenie fillmein.local (plik zawiera dane dostępowe do baz).
  if [ -z "$source" ] && [ -f "$FMI_LOCAL_DIR/sites.json" ]; then
    id="$(php -d display_errors=stderr -r '
      $sites = json_decode((string) @file_get_contents($argv[1]), true);
      if (!is_array($sites)) exit(1);
      foreach ($sites as $site) {
        if (is_array($site) && ($site["domain"] ?? null) === $argv[2] && is_string($site["id"] ?? null)) {
          echo $site["id"]; exit(0);
        }
      }
      exit(1);
    ' "$FMI_LOCAL_DIR/sites.json" "$FMI_DOMAIN" 2>/dev/null)"
    if [[ "$id" =~ ^[A-Za-z0-9_-]+$ ]]; then
      candidate="$FMI_LOCAL_DIR/run/$id/mysql/mysqld.sock"
      fmi_sock_ok "$candidate" && { SOCK="$candidate"; source="sites.json"; }
    fi
  fi

  # 3. Skan wszystkich uruchomionych stron Local z weryfikacją siteurl.
  if [ -z "$source" ]; then
    for candidate in "$FMI_LOCAL_DIR/run/"*/mysql/mysqld.sock; do
      fmi_sock_ok "$candidate" && { SOCK="$candidate"; source="scan"; break; }
    done
  fi

  [ -n "$source" ] || { echo "BLOCKED: nie znaleziono socketu MySQL strony fillmein.local (uruchom stronę w Local)."; return 2; }

  export SOCK
  echo "INFO: socket MySQL fillmein.local, źródło: $source" >&2
}

fmiwp() {
  php -d display_errors=stderr -d "mysqli.default_socket=$SOCK" -d "pdo_mysql.default_socket=$SOCK" "$FMI_WP_BIN" --path="$FMI_WP_PATH" "$@"
}

fmi_check_install() {
  [ "$(fmiwp option get siteurl 2>/dev/null)" = "$FMI_SITEURL" ] || { echo "BLOCKED: zła instalacja (siteurl ≠ $FMI_SITEURL)."; return 2; }
}

# Wczytuje jedną zmienną z pliku sekretu, o ile zmienna jest pusta, a plik istnieje i jest czytelny.
# Wartość trafia tylko do eksportowanej zmiennej (bez białych znaków na końcu); na stderr idzie samo tak/nie.
fmi_load_secret() {
  local name="$1" file="$2" value=""

  if [ -n "$(printenv "$name")" ]; then
    echo "INFO: $name już ustawiona w środowisku (plik pominięty)." >&2
    return 0
  fi

  if [ -f "$file" ] && [ -r "$file" ]; then
    value="$(head -n 1 "$file" | sed -e 's/[[:space:]]*$//')"
  fi

  if [ -n "$value" ]; then
    export "$name=$value"
    echo "INFO: $name wczytana z .ai-workflow/secrets: tak" >&2
  else
    echo "INFO: $name wczytana z .ai-workflow/secrets: nie" >&2
  fi
}

fmi_load_secrets() {
  local dir="$FMI_WP_PATH/wp-content/themes/fillmein/.ai-workflow/secrets"

  fmi_load_secret FMI_FIGMA_TOKEN "$dir/figma-token"
  fmi_load_secret FMI_FIGMA_LIVE_URL "$dir/figma-live-url"
}

fmi_bootstrap() {
  fmi_require_tools || return 2
  fmi_load_secrets
  fmi_detect_sock || return 2
  fmi_check_install || return 2
}

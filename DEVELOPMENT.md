# Fill Me In — praca lokalna

Architektura i model danych: [PROJECT.md](PROJECT.md). Zasady dla agentów: [AGENTS.md](AGENTS.md).

## Środowisko

| Element | Wartość |
|---|---|
| Strona Local | `fillmein` → `/Users/Arek/Websites/fillmein`, adres `http://fillmein.local` |
| WordPress | 7.1.3 (`app/public`) |
| Motyw | `app/public/wp-content/themes/fillmein` (to repozytorium) |
| Baza | osobna instancja MySQL tej strony w Local, `DB_NAME = local`, prefiks `wp_` |
| `wp-config.php` | `WP_ENVIRONMENT_TYPE = local`, `DB_CHARSET = utf8` |
| PHP | ≥ 8.2 (wersję strony sprawdzisz w aplikacji Local, zakładka „Overview”) |
| Node / Yarn | Node ≥ 20, Yarn (menedżer pakietów projektu — nie używaj npm/pnpm) |
| Serwer Vite | `fillmein.local:6012` (6011 używa h2otwock) |

Aplikacja nie wymaga ACF, WooCommerce ani innych wtyczek.

## WP-CLI na Local

**Sposób zalecany — „Open site shell”** (aplikacja Local → prawy klik na stronie `fillmein` → *Open site shell*).
Ten terminal ma PHP, MySQL i socket bazy właściwe dla tej strony, więc działa zwykłe `wp …`
(także `wp db export`, które wymaga `mysqldump`). Przed każdą komendą zmieniającą bazę upewnij się,
że jesteś w shellu strony `fillmein`, a nie innej.

**Sposób alternatywny — alias** (zwykły terminal, PHP z Homebrew). Acorn łączy się przez **PDO**, więc trzeba
podać socket dla `pdo_mysql` (sam `mysqli.default_socket` nie wystarczy):

```bash
ls ~/Library/Application\ Support/Local/run/        # ID uruchomionej strony fillmein
SOCK="$HOME/Library/Application Support/Local/run/<ID>/mysql/mysqld.sock"
alias fmiwp='php -d "mysqli.default_socket=$SOCK" -d "pdo_mysql.default_socket=$SOCK" $(which wp) --path=/Users/Arek/Websites/fillmein/app/public'
```

Poniżej `wp` oznacza site shell albo alias `fmiwp`.

## Pierwsze uruchomienie (checklista)

Wykonuj po kolei. Kroki 1–3 niczego nie zmieniają.

1. **Upewnij się, że to właściwa instalacja:**
   ```bash
   wp option get siteurl                    # → http://fillmein.local
   wp theme list --status=active            # → fillmein
   wp eval 'echo DB_NAME, " / ", $GLOBALS["wpdb"]->prefix, PHP_EOL;'   # → local / wp_
   wp db tables 'wp_fmi_*' 'wp_migrations' --all-tables-with-prefix
   # → „Error: Couldn't find any tables matching” = dobrze (tabel jeszcze nie ma)
   ```
   Jeśli którakolwiek z tych tabel już istnieje — przerwij i sprawdź, skąd pochodzi.
2. **Kopia bazy** (site shell):
   ```bash
   wp db export /Users/Arek/Websites/fillmein/fillmein-przed-migracja-$(date +%Y%m%d-%H%M).sql
   ```
   Alternatywnie: Local → zakładka *Database* → *Open Adminer* → *Export*.
3. **Diagnostyka bez zmian:**
   ```bash
   wp acorn migrate:status                  # „Migration table not found” jest OK przed pierwszą migracją
   wp acorn fmi:check                       # połączenie z bazą ✔; brak tabel ✘ — to oczekiwane
   ```
4. **Migracje** — tworzą wyłącznie nowe tabele `wp_fmi_*` i `wp_migrations`, nie dotykają tabel WordPressa:
   ```bash
   wp acorn migrate
   wp acorn fmi:check                       # oczekiwane: „Fundament Fill Me In działa poprawnie.” (poza assetami przed buildem)
   ```
5. **Zależności i assety** (w katalogu motywu):
   ```bash
   cd /Users/Arek/Websites/fillmein/app/public/wp-content/themes/fillmein
   composer remove generoi/sage-woocommerce log1x/acf-composer spatie/pdf-to-image
   yarn remove swiper baguettebox.js gsap jquery react react-dom @types/react @types/react-dom \
     @vitejs/plugin-react @wordpress/dependency-extraction-webpack-plugin @roots/vite-plugin \
     @tailwindcss/forms autoprefixer postcss
   yarn build
   wp acorn view:clear
   wp acorn fmi:check                       # teraz także assety ✔
   ```
   Usunięcie pakietów jest opcjonalne (kod ich nie używa), ale zalecane — to pozostałości po h2otwock.
6. **Przeglądarka:** zaloguj się na `http://fillmein.local/wp-login.php` jako administrator i sprawdź
   `http://fillmein.local/app` oraz `http://fillmein.local/app/design-system`.
7. **Commit:** zmiany w kodzie + `public/build` + `composer.json/lock` + `package.json` + `yarn.lock`.

Wycofanie (gdyby coś poszło nie tak): `wp db import <plik-z-kroku-2>.sql` w site shellu strony `fillmein`.

## Konta i logowanie

- Logowanie do aplikacji: `http://fillmein.local/app/login` (`wp-login.php` też działa).
- Administrator WordPressa ma automatycznie pełne uprawnienia Fill Me In. Kolejnych pracowników dodawaj w
  `/app/users` → „Dodaj użytkownika” (zaproszenie e-mail).
- Role i capabilities aktualizują się same przy pierwszym żądaniu po zmianie `fillmein.roles_version`
  (etap 02: wersja 2 — dochodzi rola `fmi_administrator` i nowe capabilities). Ręcznie: `wp acorn fmi:roles`.
- **Poczta lokalnie**: Local przechwytuje wiadomości w Mailpit (aplikacja Local → zakładka *Tools* → *Mailpit*).
  Tam znajdziesz e-mail z zaproszeniem i link do ustawienia hasła. Jeśli wysyłka się nie uda, aplikacja
  pokaże komunikat i nie oznaczy zaproszenia jako wysłanego.
- Zablokowane logowanie po nieudanych próbach mija samo (5 min). Natychmiastowe odblokowanie lokalnie:
  `wp acorn cache:clear`.

## Migracje

```bash
wp acorn migrate              # nowe migracje (addytywnie)
wp acorn migrate:status
```

- `WP_ENVIRONMENT_TYPE = local`, więc Laravel nie pyta o potwierdzenie. Na produkcji wymagane `--force`.
- Zmiany schematu = **nowa migracja** w `database/migrations/`; nie edytuj migracji już wykonanych.
- `migrate:rollback` usuwa tabele `fmi_*` z danymi — tylko świadomie.
- **Nigdy nie uruchamiaj `migrate:fresh`, `migrate:reset` ani `db:wipe`** — Acorn używa bazy WordPressa,
  więc te komendy usuwają **wszystkie** tabele, także WordPressa (sprawdzone w środowisku testowym).

## Assety

```bash
yarn build     # public/build (śledzone w gicie — commituj razem ze zmianami w resources/)
yarn dev       # serwer Vite z HMR na fillmein.local:6012
```

Jedyne wejścia Vite: `resources/css/fillmein.css` i `resources/js/fillmein.js`.

## Testowanie

PHPUnit/Pest **nie jest** zainstalowany (`require-dev` = tylko `laravel/pint`), a dodanie runnera
zmieniłoby `composer.lock` motywu. Testy uruchamiamy więc na prawdziwej bazie LocalWP przez
`wp eval-file` — z prawdziwymi modelami, serwisami, politykami i Gate.

### Zestaw etapu 03

```bash
# Local musi być uruchomiony (http://fillmein.local). Socket wykrywany automatycznie:
tests/stage03/run.sh

# albo z jawnym socketem:
SOCK="$HOME/Library/Application Support/Local/run/<ID>/mysql/mysqld.sock" tests/stage03/run.sh
```

70 testów w pięciu krokach:

| Krok | Plik | Zakres |
|---|---|---|
| 1 | `tests/stage03/logic.php` | hierarchia, slugi, cykle, duplikowanie, rollback, postęp, cykl życia projektu (27) |
| 2 | `tests/stage03/setup.php` | tworzy dane testowe (konta WP, projekty, podstrony) + manifest w `/tmp` |
| 3 | `tests/stage03/permissions.php` | `ProjectPolicy` i capabilities na realnych kontach (9) |
| 4 | `tests/stage03/http.sh` | kody HTTP, IDOR, CSRF, zapis przez formularz (30) |
| 5 | `tests/stage03/effects.php` | skutki żądań POST w bazie (4) |
| — | `tests/stage03/teardown.php` | usuwa **wyłącznie** dane z manifestu |

Zasady: testy logiki działają w transakcji wycofywanej na końcu, więc nie zostawiają danych.
Dane do testów HTTP muszą być trwałe (żądanie przychodzi z innego procesu), dlatego są usuwane
przez `teardown.php` po identyfikatorach z manifestu — nigdy po wzorcu nazwy.
Po przebiegu stan bazy jest identyczny jak przed. Kolejność ma znaczenie: testy uprawnień dezaktywują
konto testowe (co niszczy jego sesje), więc ciasteczka do testów HTTP odświeża `cookies.php`.

### Zestaw etapu 04 (Figma)

```bash
tests/stage04/run.sh
# test live (wymaga tokenu w wp-config.php: define('FMI_FIGMA_TOKEN', '...');)
FMI_FIGMA_LIVE_URL='https://www.figma.com/design/<klucz>/<nazwa>?node-id=1-2' tests/stage04/run.sh
```

1. `tests/stage04/figma.php` — logika na fixture (38), bez sieci: odpowiedzi API podstawia `Http::fake`
   (`tests/stage04/_fake.php`); zapis w wycofywanej transakcji, pliki podglądów usuwane po teście.
2. `tests/stage03/setup.php` + `tests/stage04/seed.php` (zasiew cache przez atrapę) → `tests/stage04/http.sh` (30)
   → `tests/stage04/cleanup.php` (pliki) → `tests/stage03/teardown.php` (dane).
3. `tests/stage04/live.php` — prawdziwe Figma API; bez tokenu lub `FMI_FIGMA_LIVE_URL` kończy się kodem 3 (NOT_RUN).

Kod wyjścia `run.sh`: 0 — wszystko PASS (z live), 3 — fixture i HTTP PASS, live NOT_RUN, 1 — niepowodzenia.

### Zestaw etapu 05 (analiza warstw i import pól)

```bash
tests/stage05/run.sh
# z testem live (ten sam token i link co w etapie 04)
FMI_FIGMA_LIVE_URL='https://www.figma.com/design/<klucz>/<nazwa>?node-id=1-2' tests/stage05/run.sh
```

1. `tests/stage05/logic.php` — parser na fixture `tests/stage05/fixtures/nodes-landing.json` (hero, akapity, lista,
   karty, menu, stopka, ukryte i techniczne warstwy) oraz import w wycofywanej transakcji (29 testów).
2. `tests/stage03/setup.php` + `tests/stage05/seed.php` (cache frame'u z fixture, bez sieci) → `permissions.php` (3)
   → `http.sh` (47) → `effects.php` (3) → `tests/stage03/teardown.php`.
3. `tests/stage05/live.php` — prawdziwy frame: analiza, import i ponowny import bez duplikatów (kod 3 bez tokenu).

### Diagnostyka

```bash
wp acorn fmi:check            # baza, tabele, migracje, role, trasy, kolumny etapu 03, magazyn plików, assety
wp acorn route:list
php -l <plik.php>
```

Ręcznie (przeglądarka):

| Scenariusz | Oczekiwany wynik |
|---|---|
| `/` (front WordPressa) | przekierowanie do `/app` |
| `/app` bez logowania | przekierowanie do logowania |
| `/app` jako subskrybent | 403 |
| `/app/projects/{id}` projektu, do którego nie jesteś przypisany | 404 |
| `/app/users` jako Project Manager | 403 |
| `/app/login` z błędnym hasłem 6 razy | komunikat o blokadzie |
| Dodanie użytkownika w `/app/users` | e-mail w Mailpit, link → ustawienie hasła → logowanie |
| Dezaktywacja pracownika | wylogowany wszędzie, nie zaloguje się; po aktywacji dostęp wraca |
| Zmiana własnej roli / dezaktywacja siebie | zablokowane z komunikatem |
| `/app/profile` → zmiana hasła | wymaga aktualnego hasła, inne sesje wylogowane |
| `/p/<losowy-ciąg>` | strona „Ten link jest nieaktywny” (404) |
| `/app/design-system` | podgląd komponentów |
| `/app/projects` — filtry, szukanie, sortowanie, paginacja | lista zawęża się, archiwum ukryte bez filtra statusu |
| `/app/projects/create` → nowy projekt | przekierowanie na szczegóły, wpis w dzienniku aktywności |
| Dodanie podstrony z linkiem do **pliku** Figmy (bez `node-id`) | błąd walidacji przy polu linku |
| Dodanie dwóch podstron o tej samej nazwie | drugi slug z sufiksem `-2` |
| Przeniesienie podstrony pod jej własne dziecko | błąd „nie można przenieść… do podstrony podrzędnej” |
| „Duplikuj” na podstronie | nowa podstrona „… (kopia)”, pusta treść, znacznik „Duplikat” |
| Archiwizacja podstrony z dziećmi | całe poddrzewo w archiwum; przywrócenie dziecka wymaga rodzica |
| `/app/projects/{id}/edit` jako przypisany Pracownik | 403 (brak `fmi_create_projects`) |
| `/app/projects/{A}/pages/{id-z-B}/edit` | 404 (wiązanie w zakresie projektu) |

Link klienta do testów:

```bash
wp eval '$p = App\Models\Project::firstOrFail(); echo app(App\Services\ClientAccessService::class)->generate($p, get_user_by("id", 1))->url(), PHP_EOL;'
```

## Najważniejsze komendy

| Komenda | Opis |
|---|---|
| `wp acorn fmi:check` | diagnostyka fundamentu (tylko odczyt) |
| `wp acorn fmi:roles` | dodaje brakujące role/capabilities (addytywnie) |
| `wp acorn migrate` / `migrate:status` | migracje bazy |
| `wp acorn route:list` | lista tras |
| `wp acorn view:clear` | czyszczenie skompilowanych widoków Blade |
| `wp acorn optimize:clear` | czyszczenie cache konfiguracji i tras |
| `yarn build` / `yarn dev` | assety |
| `tests/stage03/run.sh` | pełny zestaw testów etapu 03 (70 testów, sprzątający) |
| `tests/stage04/run.sh` | testy integracji Figma (fixture 39 + HTTP 30; live z tokenem) |
| `tests/stage05/run.sh` | analiza warstw i import pól (logika 29 + uprawnienia 3 + HTTP 47 + skutki 3; live z tokenem) |

## Konfiguracja

- `config/fillmein.php` — ustawienia aplikacji (bez sekretów): ważność linków, okno rewizji,
  limit zagnieżdżenia pól, magazyn i walidacja plików.
- Magazyn plików: domyślnie `/Users/Arek/Websites/fillmein/app/fmi-storage` (obok `app/public`, poza katalogiem
  publicznym). Inną lokalizację ustaw w `wp-config.php`: `define('FMI_STORAGE_PATH', '/ścieżka/poza/public');`.
- Token Figma API: `define('FMI_FIGMA_TOKEN', '...');` w `wp-config.php` (albo zmienna środowiskowa
  `FMI_FIGMA_TOKEN`). Nigdy w repozytorium, bazie ani `config/`. `fmi:check` pokazuje tylko, czy jest ustawiony.
- Połączenie OAuth z Figmą: `/app/settings/figma` (`fmi_manage_integrations`). Stała `FMI_FIGMA_TOKEN` ma przed nim
  pierwszeństwo. Dane są zaszyfrowane w opcji `fmi_figma_connection` kluczem z soli WordPressa, więc zmiana soli wymaga
  ponownego połączenia.
  Token osobisty Figmy wystarczy z zakresem tylko do odczytu plików (`file_content:read`).
- `APP_KEY` nie jest wymagany (wyprowadzany z soli WordPressa); można go nadpisać w `.env` motywu.
- Pliki runtime Acorna (sesje, cache, logi): `wp-content/cache/acorn/`.
- Permalinki nie mogą być „Zwykłe” (Ustawienia → Bezpośrednie odnośniki).

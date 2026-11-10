# Design Review — etap 01: audyt i zabezpieczenie istniejącej aplikacji

Data: 2026-11-03. Zakres: odczyt kodu i bazy, kopia zapasowa, próba jej odtworzenia, bazowa regresja.
Ten etap nie zmienia kodu aplikacji ani schematu bazy. Ścieżki są względne do katalogu motywu.

## 1. Stos (dowody)

| Element | Wersja / źródło |
|---|---|
| WordPress + motyw Sage 11 | `functions.php:21-43` (bootstrap Acorna) |
| Acorn 5.0.5 (Laravel 12, illuminate/* 12.33.0) | `composer.json:41-48`, `composer.lock` |
| PHP ≥ 8.2 | `composer.json` |
| MySQL 8.4.0 (Local, socket) | `sites.json` Local, `tests/bin/env.sh` |
| Vite 8, Tailwind 4, Alpine 3 | `package.json:19-33` |
| Trasy | jeden plik `routes/web.php`, 90 tras `fmi.*` (`acorn route:list`) |
| Migracje | 20, wszystkie `Ran`, partie 1–6 (`acorn migrate:status`) |

Uwierzytelnianie odbywa się przez sesję WordPressa; Gate Laravela działa na `WP_User` (`FillMeInServiceProvider.php:67`, `Support/Staff.php:21-30`). Nie ma guarda Laravel Auth ani osobnego systemu kont.

## 2. Model danych i relacje

Wszystkie tabele aplikacji: `wp_fmi_*`. Kolumny z ID użytkownika WP (`author_id`, `manager_id`, `user_id`, `added_by`, `created_by`, `revoked_by`, `*_user_id`, `review_requested_by`) nie mają kluczy obcych do `wp_users` — spójność pilnuje kod (test odtworzenia liczy je jawnie).

```
wp_users ─(ID, bez FK)─┬─ fmi_projects.author_id / manager_id   (SoftDeletes)
                       └─ fmi_project_members.user_id ── project_id → fmi_projects (cascade)
fmi_projects ─┬─ fmi_client_accesses (token_hash sha256 unique, expires_at, revoked_at)
              ├─ fmi_pages (parent_id self, archived_at) ── fmi_sections (archived_at, figma_node_id, figma_meta)
              │                                              └─ fmi_fields (parent_id self, archived_at, figma_meta)
              │                                                   ├─ fmi_repeater_rows (parent_row_id self, SoftDeletes)
              │                                                   └─ fmi_field_values (version, unique field+row_key, review_*)
              │                                                        ├─ fmi_field_revisions (niezmienne)
              │                                                        └─ fmi_field_value_assets ── fmi_assets (dysk fmi)
              ├─ fmi_figma_frames ── fmi_figma_previews (PNG na dysku fmi)
              ├─ fmi_figma_node_rules (page_id)
              ├─ fmi_wp_mappings (polimorficzne), fmi_exports
              └─ fmi_activity_log (project_id / client_access_id nullOnDelete)
```

Szczegóły kolumn: migracje `database/migrations/2026_10_27_000100` … `2026_10_30_000100`; relacje Eloquent: `app/Models/Project.php:65-125`, `Page.php:79-129`, `Field.php:74-119`, `FieldValue.php:49-72`. Morph map: `FillMeInServiceProvider.php:72-81`.

Pól, sekcji i podstron nie usuwa się fizycznie (`archived_at`); projekty, wiersze i pliki mają `deleted_at`.

## 3. Role, uprawnienia, zakres projektu

- Role (`app/Services/RoleManager.php:20-45`): `fmi_administrator`, `fmi_project_manager`, `fmi_employee` (+ WP `administrator` jako pracownik). 15 capabilities `fmi_*` (`:50-106`), synchronizacja addytywna wersjonowana `fillmein.roles_version` (`:118-153`).
- `ProjectPolicy` (`app/Policies/ProjectPolicy.php`): `fmi_manage_all_projects` → wszystko; pozostałe akcje = przypisanie do projektu + capability; brak przypisania → 404 (`denyAsNotFound`).
- Zakres projektu: `Project::visibleTo()` — autor, opiekun albo członek (`Project.php:114-125`).
- Gate'y globalne: `manage-users`, `view-all-activity`, `manage-settings`, `manage-integrations` (`FillMeInServiceProvider.php:86-89`).
- Organizacji (multi-tenant) w modelu nie ma — jedna firma, OhSoFresh.

## 4. Linki klientów (gość)

- Token: `random_bytes(32)` → base64url, w bazie tylko `sha256` (`ClientAccessService.php:96-106`); regeneracja unieważnia poprzednie linki w transakcji (`:49-57`); unieważnienie `revoked_at` (`:59-71`).
- Wygasanie wspierane (`expires_at`), domyślnie wyłączone (`config/fillmein.php:45` `default_ttl_days => null`).
- Middleware `fmi.client` (`ResolveClientAccess.php:24-40`): zły token → 404; token usuwany z parametrów trasy.
- `Referrer-Policy: no-referrer` w strefie klienta (`ApplyAppDefaults.php:27`); sesja nie zapisuje adresu z tokenem (`Http/StartSession.php:17-24`); zapisy zwracają JSON, bez `Location` z tokenem (`HandlesContentWrites.php:17-19`); limit `fmi-client` 600/min/IP.
- Klient nie ma konta ani sesji „wymienionej” z tokenu — token jest w ścieżce każdego żądania.

## 5. Przepływy

| Przepływ | Ścieżka |
|---|---|
| Logowanie | `POST /app/login` (`throttle:fmi-login`) → sesja WP → `fmi.staff` (`EnsureStaff.php:27-49`) |
| Zaproszenie | `InvitationService` → `wp_mail` (jedyne użycie e-maila), ważność 7 dni → `/app/password/set` |
| Edycja treści (panel) | `StaffContentRequest` (`Gate editContent`) → `ContentService::save` (blokada wiersza, wersja, rewizja) → JSON |
| Edycja treści (klient) | `/p/{token}` → `fmi.client` → `ClientContentRequest` → `ClientEditorService::saveValue` → `ContentService::save` |
| Autozapis | `resources/js/fillmein/client-editor.js` (debounce 800 ms, ponowienie 15 s, konflikt 409) |
| Pliki | `AssetService` (finfo + getimagesize, jpg/png/webp/gif, 15 MB) → prywatny dysk `fmi` poza ABSPATH → trasy z kontrolą dostępu |
| Figma | `FigmaUrl` (tylko `https://(www.)figma.com/(design|file)/…`) → `FigmaClient` (PAT lub OAuth Bearer, lista hostów obrazów) → `fmi_figma_frames` (drzewo, TTL 60 min) + `fmi_figma_previews` (PNG na dysku) → `FrameAnalyzer` → `FigmaImportService` |
| Przegląd treści | `ContentReviewService` (`review_note`, `review_requested_*` w `fmi_field_values`) — notatki widzi tylko zespół |
| Aktywność | `ActivityLogger` → `fmi_activity_log` (~50 zdarzeń) |

CSRF: `ValidateCsrfToken` w grupie `web` (`functions.php:29-31`), nagłówek `X-CSRF-TOKEN` w JS. Wszystkie zapisy to `POST`.

## 6. Inwentaryzacja danych (bez wartości)

Liczebność tabel `wp_*` z chwili kopii: wynik `tests/restore/run.sh` (sekcja 8). Dane wrażliwe w bazie: skróty haseł WP (`wp_users`), skróty tokenów klientów (`fmi_client_accesses.token_hash`), zaszyfrowane połączenie OAuth Figmy (opcja `fmi_figma_connection`, `SecretBox`), treści klientów. Pliki klientów i podglądy Figmy: dysk `fmi` (`app/fmi-storage`, poza ABSPATH) — **nie są w kopii SQL**.

## 7. Ryzyka i niewiadome

| # | Ryzyko / niewiadoma | Waga | Bezpieczny wariant |
|---|---|---|---|
| R1 | Prywatny klucz `key`/`key.pub` w repozytorium i zdalnym repo (incydent znany, `PROJECT.md` §13) | P0 | rotacja klucza i usunięcie z historii — decyzja właściciela |
| R2 | Cała aplikacja jest niezacommitowana: repozytorium ma tylko `6896373 Initial commit` (motyw startowy); `git status`: 37 ścieżek nieśledzonych (m.in. `app/Http`, `app/Models`, `app/Services`, `database/`, `routes/`, `tests/`), 13 zmienionych, 298 usuniętych plików szablonu — brak punktu wycofania kodu | P1 | przed etapem 03 commit (na prośbę właściciela) albo kopia katalogu motywu |
| R3 | Brak CSP i `frame-src`; `X-Frame-Options: SAMEORIGIN` jest ustawiony | P2 | etap 05: CSP z `frame-src` tylko dla potwierdzonej domeny embed Figmy |
| R4 | Brak FK do `wp_users` — usunięcie konta WP zostawia osierocone ID | P2 | nowe tabele: ID użytkownika bez FK, ale odczyt odporny na brak konta (jak dziś) |
| R5 | Linki klientów domyślnie bezterminowe | P2 | etap 09: termin ważności dla linków recenzji |
| R6 | Pliki na dysku `fmi` poza kopią SQL | P2 | kopia katalogu `app/fmi-storage` razem z SQL przed zmianami plików |
| R7 | Brak tabeli powiadomień i Laravel Mail; e-mail tylko `wp_mail` do zaproszeń | info | etap 11: powiadomienia w panelu, e-mail zależny od infrastruktury |
| R8 | Brak komentarzy, wątków i akceptacji; jest tylko notatka zespołu przy polu | info | nowe tabele addytywne, `fmi_field_values.review_*` bez zmian |
| R9 | Możliwości Figma Embed API/zdarzeń i dokładność geometrii osadzenia — niezweryfikowane | niewiadoma | etap 05; do potwierdzenia komentarze bez pinezki |
| U1 | Driver i lifetime sesji Acorna (brak `config/session.php` w motywie) | niewiadoma | sprawdzić przed etapem 09 (wymiana tokenu na sesję) |
| U2 | Stare dane testowe `TEST*`/`fmitest*` w bazie | P3 | sprzątanie tylko na decyzję właściciela |

## 8. Plan migracji i odtworzenia

**Zasada:** tylko migracje addytywne (nowe tabele `fmi_review_*`/kolumny nullable), bez zmian istniejących migracji, bez `migrate:fresh|reset|refresh`, bez usuwania kolumn. Istniejące encje (`fmi_projects`, `fmi_project_members`, `fmi_client_accesses`, WP users) są reużywane — Design Review dowiązuje się do `project_id`, `user_id` i istniejących linków.

**Procedura przed każdą migracją (etapy 03+):**
1. `bash tests/bin/wp.sh acorn migrate:status`.
2. `bash tests/restore/run.sh` — nowa kopia + próba odtworzenia na izolowanym serwerze + porównanie (kod 0 wymagany).
3. Kopia katalogu `app/fmi-storage` (pliki), jeśli etap dotyka plików.
4. `bash tests/bin/wp.sh acorn migrate --backup=<plik z kroku 2>` (nakładka sprawdza wiek i rozmiar kopii).
5. Regresja `bash tests/all/run.sh`.

**Wycofanie:**
- Kod: przywrócenie katalogu motywu z commitu/kopii (R2).
- Schemat: `migrate:rollback --step=1` tylko dla migracji addytywnych, których `down()` usuwa wyłącznie nowe tabele. Jeśli nowe tabele mają już dane klientów — najpierw ich eksport (`mysqldump` tylko nowych tabel), dopiero potem rollback; inaczej wycofanie zostawia tabele i wyłącza funkcję w kodzie.
- Awaria danych: odtworzenie pliku `~/Desktop/fillmein-pre-restore-check-*.sql` do bazy strony wykonuje właściciel (zabronione dla agenta: `wp db import`/`reset`). Plik został sprawdzony w izolowanym odtworzeniu (sekcja 9).

## 9. Wyniki testów (wykonane 2026-11-03)

### Kopia i odtworzenie — `bash tests/restore/run.sh` → kod 0, **78 PASS, 0 FAIL**
- Kopia: `~/Desktop/fillmein-pre-restore-check-20261103-185926.sql`, 911 186 B, 17 tabel `wp_fmi_*`, SHA-256 `475bdd3d…6d17`.
- Odtworzenie na tymczasowym MySQL 8.4.0 z Local (ta sama wersja co strona), `--skip-networking`, własny socket, katalog w `$TMPDIR` (`fmi-restore.F0sOoF`, do ręcznego usunięcia — zawiera dane bazy).
- Zgodne: zestaw 30 tabel; liczebność wszystkich tabel (m.in. projects 29, pages 33, sections 15, fields 155, field_values 18, field_revisions 53, repeater_rows 46, client_accesses 7, activity_log 141, figma_previews 22, users 43, usermeta 692); `CHECKSUM TABLE` każdej tabeli fmi; 34 klucze obce; 0 osieroconych wierszy dla 34 FK i 6 odwołań do `wp_users`.
- Baza strony była tylko czytana; serwer tymczasowy zatrzymany po teście.

### Bazowa regresja — `bash tests/all/run.sh` (przed poprawkami)
17 z 18 zestawów kod 0: lint, client-ux, project-bulk, page-preview, step00, stage03, stage04, stage05, stage05a, stage06, stage07, stage08, stage09, stage10, stage11, stage12, figma-oauth. `stage14` kod 2: 67/68 E2E i audyt UI BLOCKED (brak Playwrighta w `/tmp/fmi-ui-audit`).

### Po poprawkach — `bash tests/stage14/run.sh` → kod 0
E2E 68/68, macierz 94 tras 424/424, audyt UI (headless) 31 widoków × 3 szerokości 93/93, snapshot bazy przed/po zgodny. `page-preview` 17/17, `lint` 0 błędów (218 plików).

### Bazowy wynik wg obszarów
| Obszar | Dowód |
|---|---|
| Logowanie | macierz: `fmi.login` gość 200, `login.store` bez CSRF 419; trasy panelu dla gościa → 302 na logowanie (stage03/stage14) |
| Edycja treści | stage07/stage08: konflikt wersji bez utraty danych, ponowienie bez rewizji, przywrócenie tworzy nową rewizję; E2E: zapis tekstu, zdjęcia, galerii, ALT, wierszy przez HTTP z CSRF |
| Uprawnienia | macierz 94 tras: gość / bez `fmi_access` 403 / spoza projektu 404 / przypisany bez capability 403 / IDOR 404–422 |
| Linki klientów | stage06: w bazie tylko SHA-256, token 256 bitów, złe/unieważnione/wygasłe → odmowa, regeneracja unieważnia stare, dziennik bez tokenu; macierz: obcy token 404 na 10 trasach klienta |

### Poprawki w tym etapie (tylko testy i jeden widok)
1. `tests/stage14/e2e.sh` — przegląd treści filtrowany `?strona=` podstrony z polem E2E. Import Figmy tworzy teraz wiersze list według Figmy, więc strona główna ma ponad 20 pozycji i pole spadało na 2. stronę przeglądu (paginacja 20). Zachowanie aplikacji bez zmian.
2. `resources/views/fillmein/projects/pages/preview.blade.php`, `partials/preview-field.blade.php` — `dl/dt/dd` → `div/p`: zagnieżdżone grupy i listy łamały strukturę listy definicji (axe `definition-list`, `dlitem`). Wygląd bez zmian (te same klasy).
3. Nowy test `tests/restore/` (`run.sh`, `checks.php`, `live.php`, `restored.php`, `compare.php`).

### Niewykonane / ograniczenia
- Odtworzenie kopii **do bazy strony** — nie wykonano celowo (operacja destrukcyjna, poza zakresem; tylko właściciel).
- Pliki dysku `fmi` (`app/fmi-storage`) nie są objęte kopią SQL ani testem odtworzenia (R6).
- Snapshot przebiegu stage14 o 19:01 wykazał 2 nowe załączniki WP „fill-me-in” (ikona witryny ustawiona w WordPressie w trakcie testu) — zmiana spoza testów, pozostawiona bez zmian.
- `tests/restore/run.sh` nie jest w `tests/all` (każdy przebieg zostawia kopię na Pulpicie i katalog w `$TMPDIR`).

## 10. Wycofanie zmian etapu 01
Etap nie zmienia schematu ani danych. Wycofanie: usunąć `tests/restore/` i `docs/design-review/`, przywrócić linię `get "$P/review"` w `tests/stage14/e2e.sh` i znaczniki `dl/dt/dd` w dwóch widokach podglądu. Pliki kopii na Pulpicie i katalog `fmi-restore.*` w `$TMPDIR` usuwa właściciel.

## 11. Gotowość do etapu 02
Gotowe: mapa, ryzyka, plan migracji, sprawdzona kopia (kod 0). Przed etapem 03 (pierwsza migracja) zalecane rozwiązanie R2 (commit lub kopia katalogu motywu) i ponowne `tests/restore/run.sh`.

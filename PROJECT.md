# Fill Me In — dokumentacja projektu

Aplikacja webowa OhSoFresh Creative do zbierania treści od klientów na podstawie projektów graficznych w Figmie.
Działa jako dedykowany motyw WordPress (Sage 11 + Acorn 5) w tym repozytorium, w osobnej instalacji WordPressa
(Local: `/Users/Arek/Websites/fillmein`, repozytorium `ohsofreshcreative/fillmein`). Front WordPressa nie jest
używany — przekierowuje do `/app`.

> Instrukcje uruchomienia, migracji i komend: [DEVELOPMENT.md](DEVELOPMENT.md).

---

## 1. Cel aplikacji

1. Pracownik OhSoFresh loguje się (konto WordPress) i tworzy projekt klienta.
2. Podłącza plik Figmy i wybiera frame'y odpowiadające podstronom.
3. Aplikacja analizuje strukturę: wykrywa sekcje i warstwy tekstowe, generuje pola i pobiera podglądy sekcji.
4. Zespół poprawia wygenerowaną konfigurację i dodaje elementy ręcznie (sekcje, pola, zdjęcia, galerie, grupy, repeatery).
5. Klient dostaje unikalny link (bez konta), widzi podglądy sekcji i uzupełnia pola. Treść zapisuje się automatycznie.
6. Zespół monitoruje postęp, eksportuje treści do JSON i importuje je do bloków ACF przez WP-CLI.
   Docelowo: bezpośrednia synchronizacja z docelowym WordPressem.

Aplikacja obsługuje wielu klientów i wiele projektów jednocześnie.

---

## 2. Stack technologiczny

| Warstwa | Technologia (wersje z `composer.lock` / `package.json`) |
|---|---|
| CMS / uwierzytelnianie | WordPress (natywni użytkownicy, role i capabilities) |
| Motyw | Sage 11, Acorn 5.0 (Laravel 12: routing, kontenery DI, Eloquent, migracje, walidacja, Gate) |
| PHP | ≥ 8.2 |
| Widoki | Blade + anonimowe komponenty `x-ui.*` |
| CSS | Tailwind CSS 4 (osobny punkt wejścia `resources/css/fillmein.css`) |
| JS | Vite 8, Alpine.js 3 (`resources/js/fillmein.js`) |
| Baza | MySQL / MariaDB przez połączenie `wordpress` Acorna (prefiks tabel = prefiks WP) |
| Narzędzia | Composer, Yarn, WP-CLI (`wp acorn …`) |

**Bez osobnej wtyczki.** Cała aplikacja żyje w motywie, bo Acorn daje tu pełny routing, DI i migracje.
Wtyczka miałaby sens dopiero wtedy, gdyby aplikacja musiała działać niezależnie od aktywnego motywu
(np. gdyby ten sam WordPress serwował też publiczną stronę na innym motywie) — obecnie tak nie jest.

---

## 3. Architektura

### Strefy i routing

Trasy obsługuje Acorn (`routes/web.php`, rejestracja w `functions.php` → `withRouting`). Acorn przechwytuje
pasujące żądania na hooku `parse_request`; reszta adresów trafia do zwykłego routingu WordPressa.

| Adres | Strefa | Middleware |
|---|---|---|
| `/app/login` (GET, POST), `/app/logout` (POST) | Logowanie / wylogowanie | `web`, `fmi.app:guest`, `throttle:fmi-login` |
| `/app/password/set` (GET, POST) | Ustawienie hasła z zaproszenia | `web`, `fmi.app:guest`, `throttle:fmi-password-setup` |
| `/app` | Pulpit pracowników | `web`, `fmi.app:staff`, `fmi.staff` |
| `/app/projects` (GET, POST) | Lista / utworzenie projektu | j.w. + `ProjectPolicy` |
| `/app/projects/{id}` | Szczegóły projektu | j.w. + `ProjectPolicy@view` |
| `/app/profile` (GET, POST), `/app/profile/password` (POST) | Profil, zmiana hasła | j.w. (+ `throttle:fmi-password-change`) |
| `/app/users`, `/create`, `/{id}/edit` (GET); `/app/users`, `/{id}`, `/{id}/deactivate`, `/{id}/reactivate`, `/{id}/invitation` (POST) | Panel użytkowników | j.w. + `can:manage-users` |
| `/app/design-system` | Podgląd design systemu | j.w. |
| `/p/{token}` | Publiczny edytor klienta | `web`, `fmi.app:client`, `throttle:fmi-client`, `fmi.client` |

Wszystkie zapisy to `POST` (bez `_method`): Acorn dopasowuje trasę jeszcze przed obsługą nadpisania metody.

- `web` — sesja Laravela + **CSRF** (`ValidateCsrfToken` dopięty w `functions.php`) dla wszystkich tras aplikacji.
- `fmi.app` (`ApplyAppDefaults`) — locale `pl_PL`, nagłówki bezpieczeństwa (`noindex`, `no-store`,
  `nosniff`, `X-Frame-Options`, `Referrer-Policy: no-referrer` dla klienta i ekranów logowania).
- `fmi.staff` (`EnsureStaff`) — zalogowany użytkownik WP z capability `fmi_access`; niezalogowany → `/app/login`
  (z `reason=expired`, gdy ciasteczko logowania WP wygasło); bez dostępu lub nieaktywny → 403. Implementuje
  `AuthenticatesRequests`, więc działa **przed** wiązaniem parametrów tras — anonim nie odróżni istniejącego ID.
- `can:<ability>` — Laravel `Authorize` na Gate z użytkownikiem WP.
- Limity żądań: nazwane limitery (`fmi-login`, `fmi-password-setup`, `fmi-password-change`, `fmi-client`) z osobnymi kluczami.
- `fmi.client` (`ResolveClientAccess`) — rozwiązuje token na aktywny `ClientAccess`, usuwa token z parametrów trasy.

### Struktura kodu

```
app/
├── Console/Commands/      fmi:check (diagnostyka), fmi:roles (synchronizacja ról)
├── Enums/                 ProjectStatus, FieldType, ElementSource, ActorType, AccountStatus, ExportType, ExportStatus, MappableType
├── Exceptions/            ContentConflictException (konflikt autozapisu → 409), UserManagementException (reguły kont)
├── Http/
│   ├── Controllers/App/   Dashboard, Project, User, Profile, DesignSystem (panel pracowników)
│   ├── Controllers/Auth/  Login (logowanie/wylogowanie), PasswordSetup (hasło z zaproszenia)
│   ├── Controllers/Client/Editor (edytor klienta)
│   ├── Middleware/        ApplyAppDefaults, EnsureStaff, ResolveClientAccess
│   └── Requests/          StoreProject, Login, SetPassword, StoreUser, UpdateUser, UpdateProfile, ChangePassword
├── Integrations/Figma/    FigmaUrl (parsowanie linków i ID), FigmaClient (REST API), FigmaException
├── Models/                Eloquent: Project, ProjectMember, Page, Section, Field, RepeaterRow, FieldValue,
│                          FieldRevision, Asset, ClientAccess, WpMapping, Export, Activity
├── Policies/              ProjectPolicy
├── Providers/             ThemeServiceProvider (Sage), FillMeInServiceProvider (Gate na WP_User, morph map, dysk fmi, hooki WP, komendy)
├── Services/              ProjectService, ProjectAssignmentService, ClientAccessService, ContentService, ActivityLogger,
│                          RoleManager, UserService, InvitationService, AccountGuard, LoginThrottle
├── Support/               Staff (pracownicy = WP_User), Actor (kto wykonuje operację), PasswordRules
├── View/Composers/        AppLayout (dane layoutu panelu)
└── setup.php              ustawienia motywu w WP (przekierowanie frontu do /app, robots.txt)
config/fillmein.php        konfiguracja aplikacji (bez sekretów)
database/migrations/       migracje tabel fmi_*
resources/views/fillmein/  widoki aplikacji (layouty, strony)
resources/views/components/ui/  komponenty design systemu
resources/views/errors/    strony błędów tras aplikacji (403/404/419/429/500)
resources/views/index.blade.php  awaryjny szablon WP (w praktyce front przekierowuje do /app)
routes/web.php             trasy aplikacji
```

Zasady:

- **Kontrolery są cienkie**: autoryzacja (Policy/Gate), walidacja (FormRequest), wywołanie serwisu, widok/odpowiedź.
- **Serwisy** obejmują operacje wymagające transakcji, spójności lub dziennika aktywności.
- **Bez repozytoriów** — Eloquent + scope'y (`Project::visibleTo()`) wystarczają; repozytoria dodamy tylko,
  gdy pojawi się realna potrzeba (np. drugie źródło danych).
- Wspólna warstwa eksportu (JSON i synchronizacja) powstanie w `app/Exports/` w etapie eksportu.
- Motyw zawiera wyłącznie aplikację. Pozostałości po motywie h2otwock (z którego powstała kopia startowa)
  zostały usunięte przed etapem 02 — patrz sekcja 12. Front WordPressa (`template_redirect`) przekierowuje
  do `/app`; `robots.txt` blokuje indeksowanie, mapy witryny są wyłączone.

### Uwierzytelnianie i Gate

Laravel Gate jest przepięty na natywnego `WP_User` (`FillMeInServiceProvider::register`). Policy,
`$this->authorize()` i middleware `can` działają bez guarda Laravel Auth — źródłem prawdy jest sesja WordPressa.
Szczegóły: sekcja 14.

### Klucz aplikacji

CSRF i sesje Laravela wymagają `APP_KEY`. Jeśli nie ustawiono go w `.env`, klucz jest wyprowadzany
z soli WordPressa (`wp_salt('auth')`, HMAC). Nie trzeba zarządzać dodatkowym sekretem; rotacja soli
unieważnia jedynie sesje aplikacji. Tym kluczem **nie** szyfrujemy trwałych danych.

---

## 4. Struktura bazy danych

Tabele aplikacji mają prefiks WP + `fmi_` (np. `wp_fmi_projects`). Silnik InnoDB, `utf8mb4`.
Konta pracowników to natywna tabela `wp_users` (bez kluczy obcych do tabel WP — spójność zapewnia hook
`deleted_user` → `ProjectService::detachUser()`).

### Diagram relacji

```
fmi_projects ─┬─< fmi_project_members            (dodatkowi pracownicy; autor/opiekun w projects)
              ├─< fmi_client_accesses            (linki klientów, tylko hash tokenu)
              ├─< fmi_pages ─< fmi_sections ─< fmi_fields ─┐ (parent_id: drzewo grup/repeaterów)
              │                                    │  └─< fmi_fields (dzieci)
              │                                    ├─< fmi_repeater_rows (elementy repeatera, parent_row_id)
              │                                    └─< fmi_field_values ─< fmi_field_revisions
              │                                              └─< fmi_field_value_assets >─ fmi_assets
              ├─< fmi_assets                       (pliki projektu, prywatny dysk)
              ├─< fmi_wp_mappings                  (page/section/field/asset → WordPress/ACF)
              ├─< fmi_site_schemas                 (schemat zakodowanej strony WP, jeden na projekt)
              ├─< fmi_site_mappings                (dopasowanie podstron/sekcji/pól do schematu)
              ├─< fmi_exports                      (eksporty JSON, synchronizacje)
              └─< fmi_activity_log                 (project_id → NULL po usunięciu projektu)
```

### Tabele

| Tabela | Przeznaczenie | Kluczowe kolumny |
|---|---|---|
| `fmi_projects` | Projekt klienta | `uuid` (publiczny ID), `name`, `client_name`, `status`, `author_id`, `manager_id`, `figma_file_key`, `figma_file_url`, `figma_synced_at`, `settings`, `deleted_at` |
| `fmi_project_members` | Dodatkowi pracownicy | `project_id`, `user_id` — unikalne razem |
| `fmi_client_accesses` | Linki klientów | `token_hash` (SHA-256, unikalny), `expires_at`, `revoked_at`, `last_used_at` |
| `fmi_pages` | Podstrony (frame'y) | `uuid`, `source`, `figma_node_id` (unikalny w projekcie), `position`, `archived_at` |
| `fmi_sections` | Sekcje podstrony | `uuid`, `source`, `figma_node_id`, `figma_meta` (etap 05), `position`, `preview_*`, `is_hidden`, `archived_at` |
| `fmi_fields` | **Definicje** pól | `uuid` (stabilny ID), `type`, `name`, `label`, `instructions`, `source`, `parent_id`, `depth`, `is_required`, `is_hidden`, `settings`, `figma_node_id`, `figma_text`, `figma_meta` (etap 05), `position`, `archived_at` |
| `fmi_repeater_rows` | Elementy repeaterów | `uuid` (stabilny ID elementu), `field_id`, `parent_row_id`, `position`, `deleted_at` |
| `fmi_field_values` | **Treść** pól | `field_id`, `row_id` + `row_key`, `value`, `version` (optymistyczna blokada), `updated_by_*` |
| `fmi_field_revisions` | Historia treści | `field_value_id`, `version`, `value`, `actor_*` |
| `fmi_assets` | Pliki (zdjęcia) | `uuid`, `path` (prywatny), `mime_type`, `size`, `width`, `height`, `checksum`, `alt`, `caption`, `variants`, `metadata`, `uploaded_by_*`, `deleted_at` |
| `fmi_field_value_assets` | Zdjęcia w polu | `field_value_id`, `asset_id`, `position` |
| `fmi_wp_mappings` | Mapowanie do WP/ACF | `mappable_type` + `mappable_id` (unikalne), `wp_post_id`, `acf_block`, `acf_field_path` |
| `fmi_site_schemas` | Schemat zakodowanej strony WP (jeden na projekt) | `project_id` (unikalne), `site_url`, `schema_version`, `document` (JSON), `uploaded_at` |
| `fmi_site_mappings` | Dopasowanie do schematu strony | `mappable_type` + `mappable_id` (unikalne), `target`, `position`, `confidence`, `source` (`auto`/`manual`) |
| `fmi_exports` | Eksporty / synchronizacje | `type`, `status`, `target`, `file_path`, `checksum`, `summary`, `error_message` |
| `fmi_figma_frames` | Cache metadanych frame'u (etap 04) | `project_id` + `file_key` + `node_id` (unikalne), `node_name`, `node_type`, `file_version`, `file_last_modified`, `width`, `height`, `nodes_count`, `document` (przycięte drzewo), `fetched_at` |
| `fmi_figma_previews` | Podglądy frame'u i jego dzieci (etap 04) | `frame_id` + `node_id` (unikalne), `remote_url` + `remote_expires_at` (referencja), `path` (dysk `fmi`), `mime_type`, `width`, `height`, `file_version` |
| `fmi_figma_node_rules` | Decyzje o warstwach frame'u (etap 05) | `page_id` + `node_id` (unikalne), `action` (`exclude` / `include`), `node_name`, `created_by` |
| `fmi_activity_log` | Dziennik aktywności | `actor_type`, `user_id` / `client_access_id`, `event`, `subject_type` + `subject_id`, `properties` |

### Kluczowe decyzje modelu danych

- **Definicja pola ≠ treść.** Konfiguracja w `fmi_fields`, treść w `fmi_field_values`. Zmiana etykiety, typu
  czy kolejności nie dotyka wartości. Pola, sekcje i podstrony nie są usuwane fizycznie przez aplikację —
  dostają `archived_at` i zachowują treść.
- **Stabilne identyfikatory.** Projekty, podstrony, sekcje, pola, elementy repeaterów, pliki i eksporty mają
  `uuid` (UUIDv7) używany w eksporcie, mapowaniu i API klienta. Numeryczne `id` są wewnętrzne.
- **Pochodzenie elementów (`source`: `figma` / `manual`).** Import z Figmy zarządza wyłącznie elementami
  `figma` (dopasowanie po `figma_node_id`). Elementy `manual` nigdy nie są przez import usuwane ani nadpisywane.
  Dla elementów `figma` import aktualizuje jedynie dane źródłowe (`figma_text`, `figma_node_name`, podgląd);
  konfiguracja ustawiona przez zespół (etykieta, typ, wymagalność, instrukcje, kolejność) zostaje.
  Element `figma`, którego nie ma już w pliku, dostaje `archived_at` (treść zostaje).
- **Konfiguracja pól**: `type`, `position`, `is_hidden` (widoczność dla klienta), `is_required`,
  `instructions` (dla klienta) + `settings` (JSON) dla opcji zależnych od typu, np.
  `max_length`, `placeholder`, `min_items` / `max_items` (repeater, galeria),
  `image.min_width` / `min_height`, `allowed_types`.
- **Optymistyczna blokada.** Każda wartość ma `version`; zapis podaje wersję bazową. Atomowy
  `UPDATE … WHERE version = ?` wyklucza ciche nadpisanie nowszej zmiany (konflikt → 409 z aktualną treścią).
  Ponowienie identycznego zapisu (np. po zerwanym połączeniu) nie jest konfliktem.
- **Wersjonowanie.** Każda zmiana to dokładnie jedna, niemodyfikowalna rewizja w `fmi_field_revisions`.
  Przywrócenie jest zwykłym zapisem, więc tworzy nową rewizję.
- **Usuwanie projektu.** Domyślnie miękkie (`deleted_at`) — link klienta przestaje działać od razu.
  Twarde usunięcie kaskadowo czyści wszystkie dane projektu (klucze obce `ON DELETE CASCADE`),
  a dziennik aktywności zostaje z `project_id = NULL` i nazwą projektu w `properties`.
- **Dlaczego `row_id` i `row_key` jednocześnie.** MySQL dopuszcza wiele `NULL` w indeksie unikalnym, więc
  unikalność „jedna wartość pola na element repeatera” egzekwuje `(field_id, row_key)` (`row_key` = UUID
  elementu lub pusty string), a integralność zapewnia klucz obcy `row_id`. Rozwiązanie bez kolumn
  generowanych, działa tak samo na MySQL i MariaDB.

### Typy pól i przechowywanie wartości

| Typ | Wartość w `fmi_field_values.value` | Uwagi |
|---|---|---|
| `text`, `textarea`, `wysiwyg` | tekst (WYSIWYG: HTML sanityzowany przy zapisie) | z Figmy (warstwy tekstowe) lub ręcznie |
| `link` | JSON: `{"label": "…", "url": "…"}` | przycisk / odnośnik (etap 05); edycja w edytorze klienta — kolejne etapy |
| `image` | JSON: `["<asset-uuid>"]` | + wiersz w `fmi_field_value_assets` |
| `gallery` | JSON: lista UUID plików w kolejności | + wiersze w `fmi_field_value_assets` z `position` |
| `group` | brak (tylko pola potomne) | mapowanie do ACF Group |
| `repeater` | JSON: lista UUID elementów (`fmi_repeater_rows`) w kolejności | mapowanie do ACF Repeater |

Wartości-listy (galeria, repeater) są wersjonowane tak samo jak tekst — dodanie, usunięcie i zmiana
kolejności elementów też podlegają ochronie przed konfliktem i trafiają do historii.

---

## 5. Moduły

| Moduł | Stan | Elementy |
|---|---|---|
| Authentication & Users | **gotowe (etap 02)** | logowanie `/app/login`, limit prób, zaproszenia, panel `/app/users`, profil, dezaktywacja, role i capabilities |
| Projects | fundament + przypisania | model, `ProjectService`, `ProjectAssignmentService`, `ProjectPolicy` (capability + przypisanie), trasy index/store/show |
| Figma Integration | **połączenie (04) + analiza i import (05)** | `FigmaUrl`, `FigmaClient`, `FigmaFrameService`, cache frame'ów i podglądów; `FrameAnalyzer`, `FigmaImportService` (podgląd zmian, wykluczenia, zatwierdzanie) |
| Content Management | model danych + serwis | pola (7 typów), drzewo grup/repeaterów, `ContentService` (zapis tekstu z blokadą) |
| Media (Image / Gallery) | model danych | `fmi_assets`, `fmi_field_value_assets`, prywatny dysk `fmi`, konfiguracja walidacji |
| Client Access | serwis | `ClientAccessService` (generate, revoke, regenerate, resolve, wygasanie), `/p/{token}` |
| Autosave | **gotowe (etap 08)** | wersje, konflikty, idempotencja, rewizje, endpointy klienta i panelu, autozapis i szkice offline w JS, historia z przywracaniem |
| Activity Log | serwis | `ActivityLogger`, rozróżnienie `user` / `client` / `system` |
| Export & Integration | model danych | `fmi_wp_mappings`, `fmi_exports`; warstwa eksportu — kolejne etapy |

### Ręczne zarządzanie sekcjami i polami

- Administrator i przypisani pracownicy (`ProjectPolicy@update`) tworzą sekcje i pola ręcznie (`source = manual`)
  w dowolnej podstronie — również w projektach bez Figmy (podstronę też można utworzyć ręcznie).
- Konfigurowalne: typ, etykieta, nazwa techniczna, kolejność, widoczność, wymagalność, instrukcje, `settings`.
- Pola z Figmy można po imporcie edytować tak samo; zmiana typu (np. text → wysiwyg) zachowuje wartość,
  o ile typy są zgodne (tekstowe ↔ tekstowe). Niezgodna zmiana typu wymaga potwierdzenia, a stara
  wartość zostaje w historii rewizji.

### Grupy i repeatery

- Drzewo pól przez `parent_id`; `depth` zapisuje poziom (0 = pole sekcji). Limit `fillmein.fields.max_depth`
  (domyślnie 3) sprawdza `Field::canHaveChildren()` — np. repeater → grupa → repeater → tekst.
- Element repeatera (`fmi_repeater_rows`) ma stabilny `uuid`, niezależny od kolejności. Zagnieżdżone
  repeatery wskazują element nadrzędny przez `parent_row_id`.
- Wartość pola wewnątrz repeatera wskazuje **najbliższy** element repeatera (`row_id`); grupy nie tworzą
  własnego poziomu wierszy (`Field::closestRepeater()`).
- Operacje (etap repeaterów): dodanie elementu (nowy wiersz + aktualizacja listy UUID w wartości repeatera),
  usunięcie (miękkie `deleted_at` + usunięcie z listy), zmiana kolejności (nowa lista UUID + `position`),
  walidacja `min_items` / `max_items` z `settings` i twardego limitu `fillmein.fields.max_items`.
  Każda operacja jest wersjonowana jak zapis treści, więc autozapis działa identycznie.
- Mapowanie do ACF: grupa → `g_<slug>` (ACF Group), repeater → `r_<slug>` (ACF Repeater), ścieżka pola
  w `fmi_wp_mappings.acf_field_path`, np. `g_values.r_values.*.header` (konwencja motywów OhSoFresh).

### Zdjęcia i galerie

- **Magazyn prywatny**: dysk `fmi` (`config/fillmein.php → assets.path`, domyślnie katalog `fmi-storage`
  obok katalogu WordPressa, czyli **poza** katalogiem publicznym; nadpisanie stałą `FMI_STORAGE_PATH`).
  Ścieżka pliku: `projects/{project_uuid}/{asset_uuid}/{original|thumb|preview}.{ext}` — losowa, nigdy
  publikowana. `fmi:check` ostrzega, jeśli magazyn leży w katalogu publicznym.
- **Serwowanie** wyłącznie przez trasy aplikacji z kontrolą dostępu (etap mediów):
  pracownik `GET /app/projects/{project}/assets/{uuid}/{variant}` (polityka projektu),
  klient `GET /p/{token}?plik={uuid}&wariant={variant}` (plik musi należeć do projektu linku; względny
  adres w HTML nie zawiera tokenu).
  Odpowiedź: `Cache-Control: private`, `X-Content-Type-Options: nosniff`, `Content-Disposition: inline`.
- **Walidacja przy przesyłaniu**: typ MIME z zawartości (`finfo`, nie z rozszerzenia) z listy
  `assets.mime_types` (JPEG, PNG, WebP, GIF; **bez SVG**), rozmiar `assets.max_size_kb`, wymiary
  `assets.max_dimension`, `getimagesize()` musi się powieść, nazwa pliku nadawana przez aplikację.
- **Podglądy**: warianty z `assets.variants` generowane przez `WP_Image_Editor` (natywny WordPress), zapisane
  w `variants` (JSON).
- **Metadane**: `alt`, `caption`, `metadata` (np. punkt ostrości, źródło); `checksum` SHA-256.
- **Usuwanie i podmiana**: plik usuwany z pola znika z listy w wartości (nowa wersja) i z
  `fmi_field_value_assets`. Rekord pliku nie dostaje `deleted_at`, a plik zostaje w magazynie: aplikacja
  nie kasuje plików użytkownika.
- **Kolejność**: lista UUID w wartości pola + `position` w `fmi_field_value_assets` (ta sama transakcja).
- **Eksport do WordPressa**: paczka eksportu zawiera pliki + JSON z `uuid`, nazwą, ALT, podpisem
  i `checksum`; importer WP-CLI tworzy załączniki w bibliotece mediów i zapisuje ich ID w `fmi_wp_mappings`
  (`mappable_type = asset`), więc ponowny import nie duplikuje mediów (deduplikacja po `checksum`).

---

## 6. Role i uprawnienia

Natywne role WordPressa + capabilities aplikacji (`App\Services\RoleManager`). **Kod sprawdza wyłącznie
capabilities** (`fmi_*`), nigdy nazwy ról. Synchronizacja ról jest addytywna (niczego nie usuwa) i uruchamia się
automatycznie po zmianie `fillmein.roles_version` (obecnie 2) lub przez `wp acorn fmi:roles`.

| Rola aplikacji | Rola WP | Uwagi |
|---|---|---|
| Administrator | `fmi_administrator` | pełne uprawnienia aplikacji, **bez** uprawnień panelu WordPressa (zasada najmniejszych uprawnień) |
| Administrator (WordPress) | `administrator` | automatycznie wszystkie `fmi_*` (filtr `user_has_cap` dla `manage_options`); rolę zmienia się tylko w WP |
| Project Manager | `fmi_project_manager` | |
| Pracownik | `fmi_employee` | |

| Capability | Admin | PM | Pracownik |
|---|:-:|:-:|:-:|
| `fmi_access` — wejście do aplikacji | ✔ | ✔ | ✔ |
| `fmi_create_projects` — tworzenie projektów, ustawienia własnych | ✔ | ✔ | — |
| `fmi_manage_all_projects` — wszystkie projekty | ✔ | — | — |
| `fmi_delete_projects` — usuwanie (PM: jako autor/opiekun) | ✔ | ✔ | — |
| `fmi_manage_members` — przypisywanie pracowników | ✔ | ✔ | — |
| `fmi_configure_fields` — sekcje i pola | ✔ | ✔ | ✔ |
| `fmi_edit_content` — przeglądanie i edycja treści | ✔ | ✔ | ✔ |
| `fmi_manage_client_access` — linki klientów | ✔ | ✔ | — |
| `fmi_export` — eksport | ✔ | ✔ | dodatkowo* |
| `fmi_sync` — synchronizacja z WordPressem | ✔ | dodatkowo* | dodatkowo* |
| `fmi_view_activity` — aktywność przypisanych projektów | ✔ | ✔ | ✔ |
| `fmi_view_all_activity` — aktywność całej aplikacji | ✔ | — | — |
| `fmi_manage_users` — użytkownicy i role | ✔ | — | — |
| `fmi_manage_integrations` — integracje | ✔ | — | — |
| `fmi_manage_settings` — ustawienia aplikacji | ✔ | — | — |

\* „dodatkowo” — Administrator może nadać pojedynczemu użytkownikowi `fmi_export` / `fmi_sync` ponad rolę
(`RoleManager::EXTRA_CAPABILITIES`, capability na poziomie użytkownika WP).

Reguły `ProjectPolicy` (capability **i** przypisanie):

- `fmi_manage_all_projects` → dostęp do wszystkiego (`before`). Konto bez `fmi_access` → odmowa wszystkiego.
- `view` — przypisanie (autor, opiekun lub dodatkowy pracownik). Brak przypisania → **404** dla każdej akcji.
- `editContent`, `configureFields`, `manageMembers`, `manageClientAccess`, `export`, `sync`, `viewActivity` —
  przypisanie + odpowiednia capability.
- `update` (ustawienia projektu), `delete` — autor lub opiekun + capability.
- Listy zawsze filtrowane `Project::visibleTo($user)`.

Gate'y globalne: `manage-users`, `view-all-activity`, `manage-settings`, `manage-integrations`.

---

## 7. Integracja Figma

Stan po etapie 05 (połączenie + analiza warstw i import pól — sekcja 17).

- **Frame per podstrona.** Pobieramy wyłącznie frame z `fmi_pages.figma_frame_url`:
  `GET /v1/files/:key/nodes?ids={node}&depth={node_depth}`. Cały plik (`GET /v1/files/:key`) nie jest pobierany.
  Węzły spoza żądania są ignorowane; odpowiedź z inną warstwą jest odrzucana. Link do strony pliku
  (`CANVAS`) lub warstwy innego typu niż `fillmein.figma.frame_types` → błąd z komunikatem dla pracownika.
- **Parser (`FigmaUrl`).** `design`/`file`, gałęzie (`/branch/{key}` → klucz gałęzi), `node-id` w postaci
  `1-2`, `1%3A2` lub `1:2` → kanoniczne `1:2`; `encodeNodeId()` → `1-2` (adresy i ścieżki plików).
  Odrzuca: inne domeny i schematy, porty, userinfo, FigJam, warstwy instancji (`I1:2;3:4`), wartości nieliczbowe.
- **Klient (`FigmaClient`).** Token: stała `FMI_FIGMA_TOKEN` w `wp-config.php` lub zmienna środowiskowa
  `FMI_FIGMA_TOKEN` — wysyłany tylko w nagłówku `X-Figma-Token` do stałego `api_url`, nigdy w bazie, logach,
  komunikatach ani HTML. Timeout i connect timeout, bez przekierowań, limit rozmiaru odpowiedzi.
  Ponowienia (`max_attempts`, domyślnie 3) wyłącznie dla 429/5xx/błędów połączenia z backoffem;
  429 respektuje `Retry-After` do `max_retry_after` s (dłuższy → błąd od razu). 400/401/403/404 bez ponowień.
  403 z `Invalid scope` (token bez `file_content:read`) ma osobny komunikat — odróżnia brak zakresu od braku dostępu do pliku.
  Ścieżki budowane tylko ze zwalidowanego klucza i ID (brak SSRF).
- **Cache (`FigmaFrameService`).** `fmi_figma_frames` per projekt + plik + warstwa (wiele podstron może
  dzielić frame). Metadane: wersja pliku, `lastModified`, `fetched_at`, wymiary, przycięte drzewo
  (id, nazwa, typ, widoczność, `box`, `characters` dla `TEXT`; od etapu 05 także `text_style` z białej listy,
  `hyperlink` (tylko http/https/mailto/tel), `mixed_styles`, `inline_links`, `list`, `component_id`, `layout`;
  bez wypełnień, efektów, pełnych stylów i adresów obrazów; limity `node_depth`/`max_nodes`). W ciągu `cache_ttl_minutes` ponowne pobranie nie pyta API.
- **Podglądy.** Frame + widoczne bezpośrednie dzieci (kandydaci na sekcje): `GET /v1/images/:key`, pobranie
  z hostów `image_hosts` (HTTPS, bez tokenu, bez przekierowań), walidacja PNG/JPG, zapis na prywatnym dysku
  `fmi` (`figma/{uuid projektu}/{klucz}/{node}-{hash wersji}.png`). Tymczasowy adres jest referencją
  z `remote_expires_at` (`image_url_ttl_hours`); wygasły lub odrzucony (403) jest odświeżany raz.
  Ta sama wersja pliku → bez ponownych renderów; nowa wersja → nowe pliki, stare pliki cache usuwane.
  Serwuje je `GET /app/projects/{p}/pages/{page}/figma/preview/{1-2}` (Policy `view`, tylko warstwy frame'u
  tej podstrony, `Cache-Control: private`, `nosniff`).
- **Pobranie w panelu.** `POST /app/projects/{p}/pages/{page}/figma` (`FetchFigmaFrameRequest`,
  `ProjectPolicy@managePages`, 404 dla nieprzypisanych). Dziennik: `figma.frame_fetched` (bez tokenu i adresów).
- **Analiza i import (etap 05):** opis w sekcji 17.

---

## 8. Założenia eksportu WordPress

- **Wspólna warstwa przygotowania danych** (`ExportService`, etap 11; pierwotnie planowane `app/Exports/`): buduje znormalizowany payload projektu
  (podstrony → sekcje → pola z wartościami, repeatery jako listy elementów, media jako referencje) na podstawie
  `fmi_wp_mappings`. Z tej samej warstwy korzystają eksport JSON i synchronizacja.
- **Eksport JSON**: plik + pliki mediów (paczka), wpis w `fmi_exports` (status, `checksum`, `summary`).
- **Import WP-CLI** w docelowym WordPressie: komenda tworzy/aktualizuje bloki ACF (`acf/<slug>`) na wskazanych
  stronach, wypełniając pola wg `acf_field_path` (grupy `g_*`, repeatery `r_*`) i importując media.
- **Synchronizacja bezpośrednia** (docelowo): REST API docelowego WordPressa (Application Passwords),
  ten sam payload; ID utworzonych obiektów zapisywane w `fmi_wp_mappings`, by kolejna synchronizacja
  aktualizowała zamiast duplikować.

---

## 9. Zasady bezpieczeństwa

- **Uwierzytelnianie**: sesja WordPressa; autoryzacja po stronie serwera (middleware + Policy + Gate) dla
  każdej trasy i każdej operacji zapisu.
- **IDOR**: route model binding + `ProjectPolicy@view` (404 dla nieprzypisanych) + `visibleTo()` w listach.
  Zapytania o elementy projektu zawsze w zakresie projektu (np. `$project->fields()`), nigdy po samym ID.
- **CSRF**: `ValidateCsrfToken` dla całej grupy `web` (pracownicy i klienci); token w `<meta name="csrf-token">`.
- **Walidacja**: FormRequest dla każdego zapisu; komunikaty po polsku (`resources/lang/pl_PL/validation.php`).
- **Escaping**: `{{ }}` w Blade; `{!! !!}` tylko dla zsanityzowanego HTML (WYSIWYG — `wp_kses_post`).
- **Tokeny klientów**: 256 bitów z CSPRNG, w bazie tylko SHA-256; jawny token pokazywany raz przy generowaniu.
  Token jest usuwany z parametrów trasy zaraz po weryfikacji, nie trafia do dziennika ani logów aplikacji,
  a `Referrer-Policy: no-referrer` blokuje wyciek w nagłówku Referer. Linki wygasają (`expires_at`),
  można je unieważnić i wygenerować ponownie. Trasa klienta ma limit `throttle:120,1`.
  Uwaga: logi dostępowe serwera WWW zapisują ścieżkę URL — na produkcji warto je maskować dla `/p/*`.
- **Sekrety**: żadnych haseł/tokenów/kluczy w repozytorium ani w bazie w postaci jawnej.
- **Pliki**: prywatny magazyn poza katalogiem publicznym, losowe ścieżki, walidacja typu po zawartości,
  bez SVG, serwowanie tylko przez trasy z kontrolą dostępu.
- **Błędy**: w trybie produkcyjnym generyczne strony błędów (`resources/views/errors`), szczegóły tylko w logu.
- **Nagłówki**: `noindex`, `no-store`, `nosniff`, `X-Frame-Options: SAMEORIGIN`.
- **Incydent — klucz SSH w repozytorium**: patrz sekcja 13.

---

## 10. Roadmapa (14 etapów)

Propozycja kolejności — do korekty przy kolejnych promptach.

| # | Etap | Zakres |
|---|---|---|
| 01 | Fundament | architektura, baza, modele, serwisy, routing, layout, design system, dokumentacja ✅ |
| 02 | Użytkownicy | logowanie, role i capabilities, panel użytkowników, zaproszenia, profil, przypisania (serwis) ✅ |
| 03 | Panel projektów | lista z filtrami, tworzenie/edycja projektu, statusy, opiekun i przypisania (interfejs) |
| 04 | Figma: połączenie | klient API, token, pobranie struktury pliku, lista i wybór frame'ów |
| 05 | Figma: analiza | wykrywanie sekcji i warstw tekstowych, generowanie pól, podglądy sekcji |
| 06 | Konfigurator | ręczne sekcje i pola, typy, kolejność, widoczność, wymagalność, instrukcje |
| 07 | Grupy i repeatery | drzewo pól, elementy repeaterów, limity, operacje na elementach |
| 08 | Dostęp klientów | generowanie, kopiowanie, unieważnianie i ważność linków w panelu |
| 09 | Edytor klienta | podglądy sekcji + pola, nawigacja po podstronach, walidacja |
| 10 | Autozapis | endpointy zapisu, obsługa konfliktów, kolejka offline, historia wersji |
| 11 | Zdjęcia i galerie | upload, podglądy, ALT, kolejność, podmiana, kontrola dostępu |
| 12 | Postęp i aktywność | wskaźniki uzupełnienia, dziennik zdarzeń, powiadomienia |
| 13 | Eksport | wspólna warstwa danych, eksport JSON z mediami, mapowanie ACF, import WP-CLI |
| 14 | Synchronizacja | bezpośrednia synchronizacja z WordPressem, aktualizacja z Figmy bez utraty treści |

---

## 11. Aktualny stan implementacji

**Eksport uniwersalny ZIP + JSON (2026-11-05).** Zakładka **Treści → Eksport** (zakładki modułu: Przegląd treści, Mapowanie ACF, Eksport): cały projekt albo wybrane podstrony, podsumowanie przed eksportem (sekcje, pola, zdjęcia, brakujące treści, stany pól), przycisk „Eksportuj ZIP”, historia z zakresem i „Wygeneruj ponownie”. Paczka: `content.json` (format `fmi-content` 2.0 — drzewo projekt → podstrony → sekcje → pola, stany `filled`/`empty`/`cleared`, ID warstw Figmy, bez nazw ACF; `ContentExportBuilder`, schemat `resources/schemas/fmi-content.schema.json`, `ContentExportValidator`), `export.json` (profil ACF 1.1 dla `fmi:import`) i `media/<sha256>.<ext>`. Zakres w `fmi_exports.summary` — bez migracji. Dokumentacja i przykład: `docs/export-format.md`, `docs/examples/content.example.json`. Testy: `tests/stage11/content.php`, `tests/stage11/content-http.sh`. Wtyczki WordPress jeszcze nie ma.

**Schemat strony WordPress i dopasowanie (2026-11-09).** Wgrywanie schematu `fmi-site-schema` z wtyczki Fill Me In Sync (`wp fmi-sync schema`) w ustawieniach projektu i w zakładce **Mapowanie ACF**. Automat (`SiteMatcher`, wyrównanie sekwencji sekcji ↔ bloków i pól ↔ pól ACF, grupy przezroczyste, repeatery z zachowaniem zagnieżdżenia) z pewnością 0–1, ekran przeglądu z listami rozwijanymi, ręczne poprawki (`source: manual`, nigdy nie nadpisywane przez automat, przetrwają nową wersję schematu; wskazujące usunięty blok/pole są nieaktualne), „Przywróć automat”, ostrzeżenia (treść bez pary, wymagane pola ACF bez źródła, nieaktualne). `export.json` 1.2 bierze nazwy, ścieżki, klucze i `occurrence` ze schematu oraz ma klucz `schema`; projekt bez schematu eksportuje się jak dotąd. Migracja `2026_11_09_000100_create_fmi_site_schema_tables` (`fmi_site_schemas`, `fmi_site_mappings`). Opis: `docs/site-schema.md`. Testy: `tests/site-schema/run.sh`.

**Design Review, etap 09 — udostępnianie klientowi (2026-11-03).** Linki recenzji w istniejącej tabeli linków klientów (`scope` = `content` | `review`, migracja `2026_11_03_000500`): `/r/{token}` → sesja gościa `/review`, jawne możliwości (podgląd, komentowanie, akceptacja), zawsze z terminem, opcjonalny kod dostępu z limitem prób, deklarowana nazwa gościa; link nie daje dostępu do Figmy. Raport: `docs/design-review/09-linki-klienta.md`, test `tests/review-links/run.sh`.

**Design Review, etap 10 — akceptacja rewizji (2026-11-03).** Tabela `fmi_review_decisions` (migracja `2026_11_03_000600`): jedna decyzja (akceptacja / poprawki) na rewizję, aktor, sposób identyfikacji (konto albo link z nazwą deklarowaną), czas serwera i utrwalony kontekst Figmy (`version`/`lastModified` z API albo jawne „nieustalona”). Decyzja nie przechodzi na nową rewizję; nowa tworzona jawnie, czeka na decyzję. Równoległe decyzje: pierwsza wygrywa, reszta 409. Raport: `docs/design-review/10-akceptacja.md`, test `tests/review-decisions/run.sh`.

**Design Review, etap 11 — powiadomienia i historia (2026-11-03).** Tabela `fmi_notifications` (migracja `2026_11_03_000700`): powiadomienia w panelu o uwagach, odpowiedziach i decyzjach dla przypisanych osób z dostępem do recenzji (bez autora zdarzenia), deduplikacja (`user_id`, `event_key`), odczyt, link do kontekstu z ponowną autoryzacją. Historia: filtr „Design Review” w dzienniku projektu, kontekst i nazwa deklarowana gościa. E-mail odroczony (brak kolejki i ponowień). Raport: `docs/design-review/11-powiadomienia.md`, test `tests/notifications/run.sh`.

**Design Review, etap 12 — QA i finalizacja (2026-11-03).** Scenariusz odbiorczy (`tests/review-acceptance`, 50/50), macierz przeglądarek Chromium/WebKit × 5 widoków × 3 role (`tests/review-qa`, 270/270; Firefox nie startuje w piaskownicy), regresja 30/30, próba wdrożenia 8 migracji na kopii (`REPLAY_STEPS=8 tests/migrate-copy`, 79/79). Naprawa: migracja `2026_11_03_000800` (indeks `fmi_review_comments.project_id` — wycofywalność `000400`). Raport `docs/design-review/12-qa.md`, instrukcje `INSTRUKCJA-ADMINISTRATORA.md`, `INSTRUKCJA-KLIENTA.md`, plan `WDROZENIE.md`.

**Design Review, etap 08 — dyskusje i statusy uwag (2026-11-03).** Odpowiedzi w wątkach, statusy Nowy / W trakcie / Rozwiązany z ponownym otwarciem, filtry z licznikami, edycja własnych wiadomości; historia `fmi_review_thread_events` (migracja `2026_11_03_000400`), optymistyczna kontrola `lock_version`, idempotentne `request_id`. Uprawnienia: status — `fmi_manage_reviews`, autor może ponownie otworzyć swoją uwagę. Raport: `docs/design-review/08-dyskusje.md`, test `tests/review-discussions/run.sh`.

**Design Review, etap 07 — komentarze przypięte do projektu (2026-11-03).** Ekrany rewizji (`fmi_review_screens`, frame'y z Figmy przez OAuth, `surface_confirmed`) i uwagi (`fmi_review_threads` + `fmi_review_comments`, migracja `2026_11_03_000300`): numerowane pinezki x, y 0–1 względem granic frame'u (`frame_bounds_v1`) tylko na potwierdzonych ekranach; bez ekranu — opis kontekstu, bez współrzędnych. Raport: `docs/design-review/07-komentarze.md`, test `tests/review-comments/run.sh`.

**Design Review, etap 06 — tryby Przeglądaj / Komentuj (2026-11-03).** Komponent `reviewComposer`: przełącznik trybów, w Komentuj ramka `inert` + warstwa wskazania (znacznik tylko na czas pisania, bez pinezki), formularz uwagi do opisanego ekranu, Escape/Anuluj, szkic w `sessionStorage`, obsługa błędów zapisu; zapis na serwerze — etap 07. Raport: `docs/design-review/06-tryby.md`, test `tests/review-modes/run.sh`.

**Design Review, etap 05 — interaktywny prototyp Figmy (2026-11-03).** Strona recenzji osadza prototyp przez Embed Kit 2.0 (`FigmaEmbed`, `embed.figma.com/proto`, CSP `frame-src`, `referrerpolicy=no-referrer`); komponent `figmaEmbed` przyjmuje tylko udokumentowane zdarzenia od `https://www.figma.com` z tej ramki. Zdarzenia niepotwierdzone (origin niezarejestrowany w aplikacji OAuth) — wariant bez synchronizacji ekranu. Raport z macierzą: `docs/design-review/05-prototyp.md`, test `tests/review-embed/run.sh`.

**Design Review, etap 04 — panel Design Review (2026-11-03).** Lista `/app/design-review` z licznikami (otwarte uwagi, status akceptacji, ostatnia aktywność — `ReviewService::overview`), recenzja `/app/design-review/{project}`, zapis linku prototypu (`FigmaUrl::prototypeError`, bez pobierania adresu przez serwer; nowa rewizja po opublikowanej). Tabela `fmi_review_threads` (migracja `2026_11_03_000200`, próba `tests/migrate-copy`). Raport: `docs/design-review/04-panel.md`, test `tests/review-panel/run.sh`.

**Design Review, etap 03 — wspólny model projektów (2026-11-03).** Moduły projektu `fmi_projects.content_enabled` / `review_enabled` (middleware `fmi.module`), tabela `fmi_review_revisions`, capability `fmi_manage_reviews` (`roles_version` 3). Migracja `2026_11_03_000100` wykonana po próbie na kopii (`tests/model03`). Raport: `docs/design-review/03-model.md`, test `tests/modules/run.sh`.

**Design Review, etap 02 — nowa nawigacja (2026-11-03).** Menu: Pulpit, Projekty (`/app/design-review`, Design Review), Treści (`/app/projects/*`, dotychczasowy moduł — adresy bez zmian), Użytkownicy, Figma. Raport: `docs/design-review/02-nawigacja.md`, test `tests/nav/run.sh`.

**Design Review, etap 01 — audyt i zabezpieczenie (2026-11-03).** Raport: `docs/design-review/01-audyt.md` (mapa relacji i tras, ryzyka, plan migracji i wycofania). Nowy test `tests/restore/run.sh`: kopia bazy i odtworzenie na izolowanym MySQL z porównaniem liczebności, sum kontrolnych i relacji (78/78 PASS). Bez zmian schematu.

**Etap 10 — zaimplementowany i przetestowany lokalnie** (aktualizacje z Figmy, przegląd treści, dziennik aktywności — sekcja 22).

**Etap 09 — zaimplementowany i przetestowany lokalnie** (zdjęcia, galerie, bezpieczne serwowanie plików — sekcja 21).

**Etap 08 — zaimplementowany i przetestowany lokalnie** (autozapis, konflikty, historia, postęp, edytor treści w panelu — sekcja 20).
Odbiór 08 (2026-10-30): `tests/stage08/run.sh` i audyt UI `tests/ui-stage08/run.sh` z kodem 0.

**Etap 07 — zaimplementowany i przetestowany lokalnie** (linki klienta i edytor `/p/{token}` — sekcja 19).
Odbiór 07 (2026-10-30): `tests/stage07/run.sh` i audyt UI `tests/ui-stage07/run.sh` z kodem 0.

**Etap 03 — zaimplementowany i przetestowany lokalnie** (projekty, podstrony, hierarchia, duplikowanie — sekcja 15).
Odbiór 03 (2026-10-27): `yarn build` wykonany, testy `tests/stage03/run.sh` 70/70 PASS, audyt UI w przeglądarce
(360/768/1280 px, 4 role, axe WCAG 2.1 AA) bez naruszeń i błędów JS. `<x-ui.progress>` ma prop `name` — nazwę
dostępną dla czytników, gdy pasek nie ma widocznej etykiety.
**Etap 02 — zakończony** (użytkownicy, logowanie, role, zespół — sekcja 14). Etap 01 i porządkowanie — zakończone.

- Routing Acorna z rozdzieleniem stref `/app` (pracownicy) i `/p/{token}` (klienci), CSRF, nagłówki bezpieczeństwa.
- 14 migracji (`fmi_*`) z kluczami obcymi, indeksami i kaskadami; modele Eloquent z relacjami i enumami.
- Role i capabilities WordPressa (Administrator, Project Manager, Pracownik) z automatyczną synchronizacją.
- `ProjectPolicy` (z ochroną IDOR), `ProjectService`, `ClientAccessService`, `ContentService`
  (optymistyczna blokada, idempotencja, rewizje, wiersze repeaterów), `ActivityLogger`.
- Model danych dla: ręcznych sekcji/pól (`source`), pól Image/Gallery (prywatny magazyn plików),
  grup i repeaterów (stabilne ID elementów, limit głębokości).
- Szkielety widoków: pulpit, lista i szczegóły projektu, użytkownicy, edytor klienta, strony błędów.
- Design system: tokeny (kolory, typografia, promienie, cienie, ruch), komponenty `x-ui.*`
  (button, input, textarea, alert, badge, spinner, skeleton, card, page-header, empty-state, logo),
  podgląd na `/app/design-system`.
- Komendy: `wp acorn fmi:check`, `wp acorn fmi:roles`.
- Etap 02: logowanie `/app/login` z limitem prób, wylogowanie, obsługa wygasłej sesji, panel `/app/users`
  (wyszukiwanie, filtry, dodawanie z zaproszeniem e-mail, edycja, rola, dodatkowe uprawnienia, dezaktywacja,
  ponowna aktywacja, historia), ustawienie hasła z zaproszenia, profil `/app/profile` (dane, hasło),
  rola `fmi_administrator`, capabilities v2, `ProjectAssignmentService`, `ProjectPolicy` na capabilities.
  Komponenty: `select`, `checkbox`, `confirm`, `pagination`, `avatar`.

- Etap 03: pulpit z realnymi statystykami, lista projektów (szukanie, filtry, sortowanie, paginacja, archiwum),
  CRUD projektów, zespół, dziennik aktywności w UI, CRUD podstron z hierarchią (`parent_id`), slugami unikalnymi
  w obrębie rodzica, ochroną przed cyklami, osobnym linkiem do frame'u Figmy **per podstrona** (`figma_frame_url`),
  flagą `client_can_duplicate` oraz transakcyjnym duplikowaniem (`PageDuplicationService`).
  Komponent: `progress`. Komendy diagnostyczne pokrywają nowe trasy i kolumny.

- Etap 04: integracja Figma API — parser linków/ID, bezpieczny klient REST (token poza bazą, limity, ponowienia,
  brak SSRF), pobranie wyłącznie frame'u podstrony, cache metadanych z wersją (`fmi_figma_frames`), podglądy
  frame'u i dzieci w prywatnym cache z odświeżaniem wygasłych adresów (`fmi_figma_previews`), karta „Frame z Figmy”
  w edycji podstrony. Testy: fixture 39/39, HTTP 30/30, live 3/3 PASS (2026-10-27, sekcja 16).

- Etap 05: analiza warstw frame'u (`FrameAnalyzer`) — sekcje, pola text/textarea/wysiwyg/link z uzasadnieniem,
  propozycje grup i repeaterów, wykluczenia (nawigacja, stopka, breadcrumbs, ukryte, techniczne) z przywracaniem;
  podgląd zmian i zatwierdzanie importu (`FigmaImportService`), import idempotentny, bez naruszania pól `manual`
  i treści. Widok `/app/projects/{p}/pages/{page}/figma/import`. Testy: sekcja 17.

- Etap 05a: kolumny ID warstw Figmy poszerzone do VARCHAR(255) w 6 tabelach (migracja
  `2026_10_29_000100_widen_fmi_figma_node_ids`, `down()` odmawia przy wartościach dłuższych niż 64 znaki), limit 255
  w walidacji ID. `tests/bin/env.sh` wczytuje `FMI_FIGMA_TOKEN` i `FMI_FIGMA_LIVE_URL` z `.ai-workflow/secrets/`.
  Testy: `tests/stage05a/run.sh` (w tym LIVE).

- Etap 06: konfigurator sekcji i pól `/app/projects/{p}/pages/{page}/structure` — ręczne sekcje, grupy, pola
  wszystkich typów, repeatery z zagnieżdżeniem, sortowanie (przeciąganie i przyciski), archiwizacja i przywracanie,
  zmiana typu z potwierdzeniem. Logika w `SectionService`, komponent `x-ui.sort-controls`. Testy: sekcja 18.

Nie zaimplementowano (kolejne etapy): edytor klienta i UI duplikowania po stronie klienta (07),
endpointy autozapisu (08), upload plików (09), przegląd aktywności całej aplikacji (10), eksport i import (11–13).

---

## 12. Weryfikacja fundamentu (przed etapem 02)

### Niezależność od h2otwock

| Element | Stan |
|---|---|
| Instalacja WordPress | osobna strona Local `/Users/Arek/Websites/fillmein` (WordPress 7.1.3), niezależna od `/Users/Arek/Websites/h2otwock` |
| Baza danych | osobna instancja MySQL tej strony w Local (`DB_NAME = local`, prefiks `wp_`) |
| Repozytorium | `git@github.com:ohsofreshcreative/fillmein.git`, gałąź `main`, jeden commit „Initial commit” (`6896373`) |
| Motyw | powstał jako kopia motywu h2otwock — pozostałości usunięte (niżej) |
| Konfiguracja WP | `WP_ENVIRONMENT_TYPE = local`, `WP_DEBUG = false`, `DB_CHARSET = utf8`; MySQL `character-set-server = utf8mb3` (obsłużone przez FillMeInServiceProvider) |
| Zawartość strony | `wp-content/uploads` pusty, w `themes` tylko `fillmein` — świeża instalacja |

### Usunięte pozostałości po h2otwock

Wszystkie usunięte pliki zostają w historii gita (commit `6896373`).

- Kod strony: 55 bloków ACF (`app/Blocks`), strony opcji (`app/Options`), grupy pól (`app/Fields`),
  walkery menu, `SectionClasses`, `SectionBackgrounds`, composery `App`/`Archive`/`Post`/`Comments`,
  `app/filters.php`, `app/helpers.php`, `app/post-types.php` (CPT `offer`), `config/acf.php`.
- Integracje strony: WooCommerce (`archive-product.php`, `taxonomy-product_cat.php`, `patterns/`), CF7, GSAP z CDN,
  sidebary, whitelista bloków edytora w `functions.php`, dotychczasowy `app/setup.php`.
- Widoki strony: `blocks/`, `layouts/`, `sections/`, `partials/`, `forms/`, `components/{button,alert,icon}`,
  szablony `page`, `single`, `category`, `search`, `404`.
- Assety strony: `resources/css/{app.css,editor.css,variables.scss,blocks/}`,
  `resources/js/{app.js,editor.js,menubar.js,footer-accordion.js,blocks/}`, `resources/images` (24 SVG),
  `resources/fonts` (PolySans), `theme.json`, `tailwind.config.js`.
- Konfiguracja: wejścia Vite strony, `wordpressPlugin`/`wordpressThemeJson`, proxy `/wp-content/uploads`,
  domyślny host `h2otwock.local`, port serwera Vite 6011 → **6012** (bez kolizji z h2otwock).
- Dokumenty: `PRZENOSINY.md`, README Sage; `AGENTS.md` przepisany dla Fill Me In.
- Nieaktualne lockfile'e: `package-lock.json`, `pnpm-lock.yaml`, `pnpm-workspace.yaml`.

Do usunięcia przez użytkownika (wymaga `composer`/`yarn` z dostępem do sieci — komendy w `DEVELOPMENT.md`):

- Composer: `generoi/sage-woocommerce`, `log1x/acf-composer`, `spatie/pdf-to-image`.
- Yarn: `swiper`, `baguettebox.js`, `gsap`, `jquery`, `react`, `react-dom`, `@types/react`, `@types/react-dom`,
  `@vitejs/plugin-react`, `@wordpress/dependency-extraction-webpack-plugin`, `@roots/vite-plugin`,
  `@tailwindcss/forms`, `autoprefixer`, `postcss`.
- Wtyczki w instalacji Local (poza repozytorium, dezaktywacja w panelu WP — aplikacja żadnej nie wymaga):
  `contact-form-7`, `drag-and-drop-multiple-file-upload-contact-form-7`, `classic-widgets`, `duplicate-post`,
  `svg-support`, `litespeed-cache`, `peek-a-boo`, `show-current-template`, `audyt-blokow-acf`,
  `wp-blocks-csv-export`, `wp-blocks-csv-import`, `ohsofresh-ai-content-importer-v061`, `advanced-custom-fields-pro`.
  `all-in-one-wp-migration` zostaw, jeśli służy do kopii zapasowych.

Zachowane z Sage: `functions.php` (bootstrap Acorna), `index.php` + `resources/views/index.blade.php`, `style.css`,
`screenshot.png`, `ThemeServiceProvider`, `composer.json` (`roots/acorn`), Vite + Tailwind 4 + `laravel-vite-plugin`, Alpine.js.

---

## 13. Bezpieczeństwo — klucz SSH w repozytorium

**Ustalenia** (bez odczytu zawartości klucza):

- `key` (prywatny klucz OpenSSH, ED25519) i `key.pub` są śledzone przez git od pierwszego commita `6896373`.
- Commit jest na `origin/main` (`ohsofreshcreative/fillmein` na GitHubie) — **klucz trafił do zdalnego repozytorium**.
- Repozytorium odpowiada na anonimowe `git ls-remote`, więc **najpewniej jest publiczne** — potwierdź na GitHubie.
- Klucz jest **zabezpieczony hasłem** (passphrase). To ogranicza ryzyko, ale go nie eliminuje
  (hasło można łamać offline, bez limitu prób).
- Odcisk klucza publicznego: `SHA256:G3BAf5zZ4Vff3QXCHmboFPmVKNlR5KSQ5Rlm8UlpVy0` (ED25519). Komentarz w `key.pub`
  to szablonowe `twoj_email@domena.pl` — pliki prawdopodobnie pochodzą z szablonu, z którego powstał h2otwock.

**Plan naprawy** (wykonuje właściciel, w tej kolejności):

1. **Ustal, gdzie klucz jest autoryzowany**: serwery (`~/.ssh/authorized_keys` — szukaj odcisku powyżej przez
   `ssh-keygen -lf ~/.ssh/authorized_keys`), GitHub (Settings → SSH keys oraz Deploy keys repozytoriów), panele hostingu.
2. **Unieważnij klucz** wszędzie tam (usuń wpis z `authorized_keys` i deploy keys).
3. **Wygeneruj nowy klucz poza repozytorium**, np. `ssh-keygen -t ed25519 -C "deploy-fillmein" -f ~/.ssh/fillmein_deploy`,
   i dodaj go tylko tam, gdzie jest potrzebny. W CI używaj sekretów (np. GitHub Actions secrets), nie plików w repo.
4. **Ustaw repozytorium jako prywatne**, jeśli jest publiczne (GitHub → Settings → Danger Zone).
5. **Usuń pliki z repozytorium** bez kasowania lokalnej kopii: `git rm --cached key key.pub`, dopisz `key` i `key.pub`
   do `.gitignore`, commit i push. Lokalną kopię przenieś do `~/.ssh/` albo usuń po rotacji.
6. **Opcjonalnie wyczyść historię** (`git filter-repo --path key --path key.pub --invert-paths` + force push).
   To przepisanie historii — wymaga zgody zespołu i ponownego sklonowania repo. Po rotacji (kroki 1–3)
   nie jest konieczne, bo wyciekły klucz niczego już nie otwiera.
7. Sprawdź to samo w repozytorium h2otwock i innych projektach z tego szablonu.
8. Po zakończeniu usuń notatkę o incydencie z `AGENTS.md`.

---

## 14. Użytkownicy, uwierzytelnianie i zespół (etap 02)

### Konta

- Jedno źródło kont: **natywni użytkownicy WordPressa**. Brak własnych tabel użytkowników i haseł.
- Konto Fill Me In = użytkownik z rolą `administrator`, `fmi_administrator`, `fmi_project_manager` lub `fmi_employee`
  (`RoleManager::STAFF_ROLES`). Panel aplikacji nie pokazuje ani nie edytuje innych kont WP (404).
- Status (meta użytkownika, `App\Enums\AccountStatus`):

| Status | Warunek | Skutek |
|---|---|---|
| Aktywne | brak dezaktywacji, hasło ustawione lub było logowanie | normalny dostęp |
| Zaproszone | `fmi_invited_at`, brak `fmi_password_set_at` i `fmi_last_login_at` | czeka na ustawienie hasła |
| Nieaktywne | `fmi_deactivated_at` | brak dostępu; konto, historia i przypisania zostają |

- Dezaktywacja (`AccountGuard`): filtr `user_has_cap` odbiera wszystkie `fmi_*` — jedno miejsce odcina dostęp
  w middleware, politykach i Gate'ach. Dodatkowo: natychmiastowe zniszczenie wszystkich sesji użytkownika
  i blokada logowania (także `wp-login.php`, jeśli konto nie ma innych uprawnień w WP, np. `edit_posts`).
- Konta nie są usuwane fizycznie z aplikacji. Gdyby ktoś usunął konto w panelu WP, hook `deleted_user`
  czyści przypisania (`ProjectAssignmentService::detachUser`).

### Logowanie (`/app/login`)

- `wp_authenticate()` (natywna weryfikacja hasła i filtry `authenticate`) → sprawdzenie `fmi_access` →
  dopiero wtedy `wp_set_auth_cookie()` + akcja `wp_login`. Konto bez dostępu nie dostaje sesji WP.
- Login lub e-mail, „Zapamiętaj mnie” (trwałe ciasteczko WP), przekierowanie do `/app` lub `redirect_to`
  (tylko adresy w obrębie `/app` — ochrona przed open redirect), sesja Laravela regenerowana po zalogowaniu.
- Komunikaty: wspólny dla złego loginu i hasła (bez ujawniania, które się nie zgadza), konto bez dostępu,
  konto nieaktywne, blokada po zbyt wielu próbach, wygasła sesja.
- **Limit prób** (`LoginThrottle`, hooki `authenticate` / `wp_login_failed` / `wp_login`) — wspólny dla `/app/login`
  i `wp-login.php`: 5 nieudanych prób na konto + IP w 5 min, 30 prób na IP w 15 min (`config/fillmein.php` → `auth`).
  Dodatkowo limiter trasy `fmi-login` (30 żądań/min/IP).
- Wylogowanie: `POST /app/logout` (CSRF) → `wp_logout()` + unieważnienie sesji Laravela.
- Standardowe `wp-login.php` działa dalej (nie jest wyłączane); pracownicy bez `edit_posts` są po nim kierowani do `/app`.

### Zaproszenia i ustawienie hasła

1. Administrator dodaje pracownika (imię, nazwisko, e-mail, rola, opcjonalnie dodatkowe uprawnienia).
2. `UserService::invite()` tworzy konto WP (`wp_insert_user`, login z e-maila, losowe hasło 32 znaki, którego nikt nie zna).
3. `InvitationService` generuje klucz natywnym `get_password_reset_key()` (WP przechowuje tylko jego hash w
   `user_activation_key`) i wysyła e-mail HTML (`wp_mail`, widok `fillmein.emails.invitation`).
4. Sukces wysyłki → `fmi_invitation_sent_at` + zdarzenie `user.invitation_sent`. Porażka → konto zostaje,
   zaproszenie **nie** jest oznaczane jako wysłane, zdarzenie `user.invitation_failed`, Administrator widzi
   komunikat z przyczyną i przycisk „Wyślij zaproszenie ponownie”.
5. Link `/app/password/set?login=…&key=…` → klucz weryfikowany `check_password_reset_key()` i przenoszony do
   sesji, przeglądarka trafia na adres bez klucza. Ważność: 7 dni dla zaproszeń (`invitations.expiration_days`),
   24 h dla zwykłego resetu.
6. Nowe hasło (min. 12 znaków, potwierdzenie, różne od loginu/e-maila) → `reset_password()` (natywnie unieważnia klucz).
7. Pracownik loguje się na `/app/login`.

„Nie pamiętasz hasła?” prowadzi do natywnego resetu WordPressa.

### Panel użytkowników (`/app/users`, Gate `manage-users`)

- Tabela: imię i nazwisko, e-mail, rola, status, data dodania, ostatnie logowanie, akcje; wyszukiwanie
  (imię/nazwisko, e-mail, login), filtr roli i statusu, paginacja.
- Dodawanie (zaproszenie), edycja (imię, nazwisko, rola, dodatkowe uprawnienia), dezaktywacja / ponowna
  aktywacja z potwierdzeniem, ponowne wysłanie zaproszenia, historia zdarzeń konta.
- E-mail jest unikalny w całym WordPressie (nie przejmujemy istniejących kont) i nie jest edytowany w aplikacji.

Reguły (egzekwowane w `UserService`, niezależnie od interfejsu):

- nadawane są wyłącznie role aplikacji — nie da się z aplikacji nadać roli WordPressa (`administrator` → walidacja);
- roli administratora WordPressa nie zmienia się z aplikacji;
- nikt nie zmienia własnej roli ani statusu (panel i profil);
- zawsze zostaje co najmniej jeden aktywny administrator (degradacja / dezaktywacja ostatniego jest blokowana);
- Project Manager i Pracownik nie mają dostępu do panelu (403, także dla konkretnych ID — bez ujawniania, które istnieją).

### Profil (`/app/profile`)

Dane konta, rola i lista uprawnień (tylko podgląd), zmiana imienia i nazwiska, zmiana hasła (wymaga
aktualnego hasła, limit 5 błędnych prób / 5 min; po zmianie pozostałe sesje są wylogowywane, bieżąca dostaje
nowe ciasteczko), wylogowanie. Formularze profilu nie przyjmują roli ani statusu.

### Przypisania do projektów (`ProjectAssignmentService`)

`setManager`, `addMember`, `removeMember`, `syncMembers`, `assignedUserIds`, `detachUser`. Tylko aktywni
pracownicy, bez duplikatów (opiekun/autor nie są dublowani jako członkowie), każda zmiana w dzienniku
(`project.manager_changed`, `project.member_added`, `project.member_removed`, `project.members_changed`).
Kto może zmieniać przypisania: `ProjectPolicy@manageMembers` (wywołujący). Interfejs — etap 03.

### Historia aktywności

Zdarzenia kont (`subject_type = user`): `user.created`, `user.invitation_sent`, `user.invitation_failed`,
`user.password_set`, `user.password_changed`, `user.updated` (nazwy zmienionych pól), `user.role_changed`
(z → na), `user.capabilities_changed`, `user.deactivated`, `user.reactivated`. Każde z `user_id` wykonawcy.
Nigdy hasła, klucze ani tokeny.

### Wyniki testów etapu 02 (środowisko testowe: WordPress 6.8.3, PHP 8.3, MariaDB)

| Zestaw | Wynik |
|---|---|
| HTTP etapu 02 (logowanie, wylogowanie, błędne dane, limit prób w `/app/login` i `wp-login.php`, open redirect, wygasła sesja, dostęp według ról, IDOR, tworzenie użytkowników, walidacja, eskalacja ról, zaproszenia i ustawienie hasła, nieudana wysyłka, zmiana ról, ochrona własnego konta, dezaktywacja/aktywacja, profil i zmiana hasła, CSRF, escaping, nagłówki) | 106/106 |
| Serwisy etapu 02 (macierz capabilities, polityka projektów, przypisania, ostatni administrator, role WP) | 26/26 |
| Regresja etapu 01 (serwisy + HTTP) | 38/38, 25/25 |
| Dostępność axe-core (WCAG 2.0/2.1/2.2 A i AA) — logowanie, użytkownicy, dodawanie, edycja, profil, design system, link nieaktywny | 0 naruszeń |
| Build Vite, kompilacja wszystkich widoków Blade, `php -l`, błędy JS w konsoli, poziomy scroll na 390 px | bez błędów |

---

## 15. Projekty, podstrony, hierarchia i duplikowanie (etap 03)

### Pulpit (`/app`)
Wszystkie liczby pochodzą z bazy i z zakresu widoczności użytkownika (`Project::visibleTo`) — brak danych
przykładowych. Pokazuje: liczniki projektów per status, projekty z dostępem, aktywne podstrony, aktywne linki
klientów, „Moje projekty” (autor lub opiekun) i ostatnio aktualizowane.

### Lista projektów (`/app/projects`)
Szukanie po nazwie projektu i klienta (znaki wieloznaczne są escapowane), filtr statusu, filtr „tylko moje”,
sortowanie z **listy zamkniętej** (`ProjectController::SORTS` — parametr z adresu nigdy nie trafia do SQL),
ręczna paginacja (brak `illuminate/pagination`). Bez jawnego filtra statusu projekty archiwalne są ukryte.
Kolumny: projekt, klient, opiekun, autor, status, liczba podstron, postęp, data aktualizacji.

### Postęp (`App\Support\ProjectProgress`)
Liczony wsadowo (dwa zapytania na listę, bez N+1). Mianownik: aktywne, widoczne pola mogące mieć własną
wartość (grupy i repeatery jej nie mają). Licznik: te z nich, które mają niepustą wartość.
Świadome uproszczenie: pola wewnątrz repeatera liczymy w mianowniku raz, a ich wartości (po jednej na wiersz)
mogą dać licznik wyższy — dlatego wynik jest przycięty do 0–100. Dokładny postęp per wiersz repeatera
będzie możliwy po etapach 05–06.

### Podstrony (`fmi_pages`)
- **Hierarchia:** `parent_id` (NULL = korzeń). Kolumna `parent_key` to techniczne odbicie `parent_id`
  (0 dla korzenia) utrzymywane przez model `Page` (hook `saving`). Potrzebna, bo MySQL dopuszcza wiele NULL
  w indeksie unikalnym, więc `unique(project_id, parent_id, slug)` nie chroniłby poziomu głównego.
  Ten sam wzorzec co `row_key` w `fmi_field_values`.
- **Slug** jest unikalny w obrębie rodzica (`fmi_pages_parent_slug_unique`); kolizje rozwiązuje sufiks `-2`, `-3`…
- **Brak cykli:** `PageService::assertNoCycle()` nie pozwala przenieść podstrony pod siebie ani pod własnego
  potomka; obowiązuje też limit `fillmein.pages.max_depth`.
- **Frame Figmy per podstrona:** `figma_frame_url` musi wskazywać konkretny frame (adres z `node-id`),
  a `figma_node_id` jest z niego wyliczany i normalizowany do postaci `1:23` (`FigmaUrl::nodeId`).
  `fmi_projects.figma_file_url` pozostaje opcjonalnym plikiem nadrzędnym.
  Pierwotny `unique(project_id, figma_node_id)` zamieniono na zwykły indeks, bo **wiele podstron może
  wskazywać ten sam frame** (ustalenie produktowe) — dopasowanie przy imporcie (etap 04) musi brać pod uwagę
  również `source` i `id` podstrony.
- **Archiwizacja** (`archived_at`) obejmuje całe poddrzewo; przywrócenie wymaga aktywnego rodzica.
  Podstrony nie są usuwane fizycznie.
- **Rodzic jest zawsze weryfikowany w zakresie projektu** — podmiana `parent_id` w żądaniu nie daje dostępu
  do obcego projektu.

### Duplikowanie (`App\Services\PageDuplicationService`)
Jedna transakcja; błąd w dowolnym kroku wycofuje całość.
- Kopiowane są **definicje**: podstrona, sekcje (z podglądami), pola wraz z zagnieżdżeniem (`parent_id`
  przemapowany) i ustawieniami.
- **Treść jest kopiowana tylko na żądanie** (`with_values`); domyślnie duplikat jest pusty.
  Przy kopiowaniu treści przemapowywane są: wiersze repeaterów (`parent_row_id`), `row_id`/`row_key` wartości
  oraz listy UUID wierszy w JSON. Wersja startuje od 0 — historia rewizji źródła duplikatu nie dotyczy.
- **Pliki nie są kopiowane**: duplikat wskazuje te same zasoby przez referencję (`fmi_field_value_assets`
  i te same UUID w wartości). Pliki należą do projektu, nie do podstrony.
- Każdy wiersz dostaje **nową tożsamość** (`id` i `uuid`), więc duplikat jest niezależny od źródła.
- Duplikat ma `source = manual`, żeby import z Figmy nie mógł go usunąć ani nadpisać, mimo że może
  wskazywać ten sam frame. `duplicated_from_id` zapisuje pochodzenie.
- Opcjonalnie kopiowane jest poddrzewo (`with_children`).

### Duplikowanie przez klienta
`PageDuplicationService::duplicateForClient()` autoryzuje **wyłącznie token**: dostęp musi być aktywny,
dotyczyć projektu tej podstrony, a administrator musi jawnie włączyć `client_can_duplicate`.
Klient nigdy nie kopiuje treści ani poddrzewa. Interfejs klienta powstanie w etapie 07 — serwis jest gotowy.
Tokeny nie trafiają do dziennika aktywności.

### Autoryzacja
Nowa zdolność `ProjectPolicy@managePages` opiera się na istniejącej capability `fmi_configure_fields`
(struktura podstron to konfiguracja projektu) — **nie dodano nowej capability**, więc `fillmein.roles_version`
pozostaje bez zmian. Podstrony w trasach są wiązane w zakresie projektu (`scopeBindings`), więc obce `id`
daje 404, nie 403.

### Testy etapu 03 (uruchomione lokalnie na LocalWP)
W repozytorium nie ma PHPUnit/Pest (`require-dev` = tylko `laravel/pint`), a instalacja runnera zmieniłaby
`composer.lock` motywu. Testy uruchamiane są więc przez `wp acorn`/`wp eval-file` na prawdziwej bazie:
`tests/stage03/run.sh` — 70 testów (logika 27, uprawnienia 9, HTTP 30, skutki POST 4).
Testy logiki działają w wycofywanej transakcji; dane do testów HTTP są tworzone i **sprzątane** na podstawie
manifestu. Szczegóły uruchomienia: `DEVELOPMENT.md`.

---

## 16. Figma API, frame'y i podglądy (etap 04)

Szczegóły architektury: sekcja 7. Konfiguracja: `config/fillmein.php` → `figma`.

### Testy (uruchomione lokalnie na LocalWP)
`tests/stage04/run.sh`:
- logika na fixture — 38 testów (parser i ID, SSRF, 400/401/403/404/429/5xx/timeout, limity ponowień,
  rozmiar odpowiedzi, import tylko frame'u, cache i TTL, wersje, odświeżanie adresów, zakres projektu, dziennik),
- HTTP — 30 testów (podglądy, IDOR, CSRF, 404 dla nieprzypisanych, widok bez wycieku adresów i tokenu),
- **live — wymaga tokenu** (`FMI_FIGMA_TOKEN`) i `FMI_FIGMA_LIVE_URL`; bez nich `NOT_RUN` (kod wyjścia 3).
  Token musi mieć zakres `file_content:read`. Brakujący węzeł Figma zwraca jako `200` z `nodes: {"<id>": null}`,
  więc `NOT_FOUND` zgłasza `FigmaFrameService`, nie `FigmaClient` (test live sprawdza to przez serwis).

Fixture w `tests/stage04/fixtures/` to syntetyczne dane w kształcie odpowiedzi API, nie wynik testu live.

---

## 17. Analiza warstw Figmy i generowanie pól (etap 05)

Konfiguracja: `config/fillmein.php` → `figma.analysis`. Kod: `App\Integrations\Figma\FrameAnalyzer` (czysta logika),
`App\Services\FigmaImportService` + `FigmaImportState` (diff i zapis), `FigmaImportController`.

### Analiza (`FrameAnalyzer`, na przyciętym drzewie z cache etapu 04)
- **Sekcje** = widoczne dzieci frame'u w kolejności czytania (góra → dół, lewo → prawo; pasy po 8 px). Kontener
  obejmujący ≥ 90% wysokości frame'u (wrapper) jest rozpakowywany (maks. 2 poziomy). W `figma_meta` zapisujemy
  nazwę i typ warstwy, indeks w pliku, `box`, `text_style`, `component_id`.
- **Pola z warstw `TEXT`** z uzasadnieniem (`reason`): hiperłącze → `link`; krótki jednowierszowy tekst w warstwie
  „button/btn/przycisk/cta/link” (lub jedyny tekst takiego kontenera) → `link`; lista punktowana/numerowana lub
  formatowanie w tekście → `wysiwyg`; kilka wierszy lub ≥ 120 znaków → `textarea`; w pozostałych przypadkach `text`.
  Etykieta = nazwa warstwy, a gdy nazwa jest domyślna (= treść, „Text 2”, „Label”) — początek treści.
- **Repeater**: ≥ 2 sąsiednie kontenery o identycznej strukturze tekstów (karty, instancje) albo ≥ 3 sąsiednie teksty
  o tej samej nazwie (punkty listy). Pola elementu z pierwszego elementu; wszystkie elementy (ID, próbki tekstów)
  w `figma_meta.items`. **Grupa**: nazwany kontener z ≥ 2 tekstami (nie jedyny w rodzicu; grupa z jednym elementem
  jest rozwijana). Kontenery o nazwach domyślnych („Frame 12”, „Kontener”, `__wrapper`) są spłaszczane. Limit
  `fields.max_depth`. Gdy struktura nie jest jednoznaczna, pola zostają płaskie.
- **Wykluczenia automatyczne**: ukryte (`visible: false`), nawigacja (`nav`, `navbar`, `navigation`, `nawigacja`,
  `topbar`; „header”/„menu” tylko dla warstw ≤ 200 px w górnych 300 px frame'u), stopka, breadcrumbs, warstwy
  techniczne (słowa `grid`, `guide`, `note`…, typy `SLICE`/`STICKY`/…, prefiks `_` lub `.` — ale nie BEM `__`),
  puste teksty. Reguły słowne nie dotyczą warstw `TEXT` (ich nazwa to zwykle treść). Lista wykluczonych pokazuje
  warstwy z tekstami (oraz wszystkie na poziomie sekcji) z powodem i ścieżką.
- **Reguły pracownika** (`fmi_figma_node_rules`): `exclude` (pomiń warstwę) i `include` (przywróć wykluczoną
  automatycznie) mają pierwszeństwo; `reset` wraca do reguł automatycznych. Tylko warstwy frame'u tej podstrony.

### Import (`FigmaImportService`)
- `plan()` — podgląd bez zapisu: dla każdej sekcji i pola operacja `create` / `update` (zmiana tekstu z Figmy,
  przeniesienie, pozycja/styl) / `restore` (wraca zarchiwizowany element) / `unchanged`; elementy Figmy, których
  zabrakło → `archive`, a gdy zawierają aktywne pola ręczne → `keep`. Sekcja ręczna z tym samym ID warstwy → `skipped`.
- **Dopasowanie**: sekcje po `figma_node_id` w podstronie; pola po `figma_node_id` + roli (pole / kontener) w sekcjach
  podstrony i w tym samym repeaterze (przeniesienie do innego repeatera = nowe pole, stare do archiwum — wartości
  należą do wierszy konkretnego repeatera). Każdy istniejący element dopasowany co najwyżej raz → brak duplikatów, UUID stałe.
- **Co aktualizuje**: tylko `figma_text`, `figma_node_name`, `figma_meta`, położenie w drzewie i `archived_at`.
  Etykieta, nazwa techniczna, typ, wymagalność, instrukcje i kolejność ustawione przez zespół zostają
  (różnica typu jest tylko informacją w podglądzie). Elementów `manual` i `fmi_field_values` import nie dotyka.
- `apply()` — w transakcji z blokadą wiersza podstrony; przyjmuje odcisk podglądu (SHA-256 z wersji frame'u, wyniku
  analizy i pełnego stanu sekcji/pól). Jeśli coś zmieniło się od podglądu → odrzucenie z komunikatem.
  Dziennik: `figma.import_applied` (liczniki, bez treści), `figma.node_rule_changed`.
- Trasy: `GET|POST /app/projects/{p}/pages/{page}/figma/import`, `POST …/figma/rules` — `ProjectPolicy@configureFields`
  (404 dla nieprzypisanych), CSRF, FormRequesty (`ApplyFigmaImportRequest`, `UpdateFigmaNodeRuleRequest`), podstrona
  w zakresie projektu (`scopeBindings`). Teksty z Figmy wyłącznie przez `{{ }}`.
- Nowy typ pola `link` (`FieldType::Link`) — wartość `{"label","url"}`; edycja po stronie klienta w kolejnych etapach.

### Testy (uruchomione lokalnie na LocalWP, 2026-10-27)
`tests/stage05/run.sh` → exit 0: logika 29/29 (parser na fixture + import: idempotencja, nieaktualny podgląd,
zmiany w Figmie, ochrona konfiguracji zespołu, pól ręcznych i wartości, wykluczenia i przywracanie, niezależność
podstron, duplikat, dziennik), uprawnienia 3/3, HTTP 47/47, skutki 3/3, live 2/2 (frame „Homepage” pliku Rokola:
460 warstw → 9 sekcji, 52 pola, 5 grup, 10 repeaterów, 7 wykluczeń; ponowny import bez zmian).
Regresja: `tests/stage03/run.sh` exit 0, `tests/stage04/run.sh` exit 0 (z live). Audyt UI: Playwright + axe,
360/768/1280 px, administrator i przypisany pracownik — 27/27 bez naruszeń, błędów JS i przewijania poziomego.

---

## 18. Konfigurator sekcji i pól (etap 06)

- Wejście: przycisk „Sekcje i pola” w edycji podstrony. Autoryzacja: `ProjectPolicy@configureFields`.
- `{section}` i `{field}` są wiązane w zakresie podstrony (`Page::sections`, `Page::fields`), więc obce ID daje 404.
  Pole nadrzędne i listy kolejności sprawdza `SectionService`. `FieldConfigException` staje się błędem walidacji
  (formularz wraca z komunikatem, JSON dostaje 422).
- Zagnieżdżenie: `fillmein.fields.max_depth` = 3 pod polem sekcji, czyli np. repeater → grupa → repeater → pole.
  Kontener na ostatnim poziomie jest odrzucany, bo nie mógłby mieć pól.
- Klucz (`name`) jest unikalny wśród rodzeństwa łącznie z archiwum, żeby przywrócenie nie dawało kolizji.
- Archiwizacja kontenera nie zmienia potomków. Widoki muszą iść po drzewie od aktywnych korzeni.
- Zmiana typu: kontener z polami podrzędnymi (także archiwalnymi) nie zmienia typu. Zmiana usuwająca treść wymaga
  potwierdzenia i idzie przez `ContentService::clearForTypeChange`.
- Testy: `tests/stage06/run.sh`, audyt UI `tests/ui-stage06/run.sh`.

---

## 19. Linki klienta i edytor `/p/{token}` (etap 07)

- Panel: karta „Linki dla klienta” w szczegółach projektu. Autoryzacja: `ProjectPolicy@manageClientAccess`.
- Jawny adres pokazuje tylko odpowiedź na POST generowania lub regeneracji, bez przekierowania i flasha,
  bo `with()` zapisałoby token w pliku sesji. W bazie jest wyłącznie SHA-256 tokenu.
- Edytor nie zna swojego adresu: linki podstron to `?strona=ID`, a adresy zapisu buduje JS z `location.pathname`.
  HTML i JSON nie zawierają tokenu. Zapisy zwracają JSON, nigdy przekierowanie (`Location` zawierałby token).
- Każdy nieważny token (także w złym formacie) trafia do `fmi.client` i dostaje ten sam widok 404.
  CSRF działa przed `fmi.client`, więc POST bez CSRF dostaje 419 niezależnie od ważności tokenu.
- Trasy klienta nie wiążą modeli. ID pól i wierszy rozwiązuje `ClientEditorService` po weryfikacji tokenu:
  pole spoza projektu → 404, niewidoczne (także przez rodzica, sekcję, podstronę) → 403, wiersz spoza
  najbliższego repeatera pola → 404, typ bez wartości → 422.
- `ContentService::save` przyjmuje też link (JSON `{label, url}`), a `addRow`, `removeRow` i `reorderRows`
  prowadzą wersjonowaną listę UUID. Lista wierszy nie scala się jak tekst: inna wersja bazowa to zawsze konflikt.
- Usunięcie wiersza jest miękkie, jego treść zostaje w bazie.
- Autozapis i rozwiązywanie konfliktów: sekcja 20. Pliki: etap 09.
- Testy: `tests/stage07/run.sh`, audyt UI `tests/ui-stage07/run.sh`.

---

## 20. Autozapis, konflikty, historia i postęp (etap 08)

- **Zapis.** `ContentService::save` sprawdza pole i wiersz, potem w transakcji blokuje wiersz wartości (`FOR UPDATE`),
  porównuje wersję i wykonuje `UPDATE … WHERE version = ?`. Brak rekordu: `INSERT IGNORE`, nie blokada pustego zakresu,
  bo blokady luk prowadziłyby do zakleszczeń. Transakcja ponawia się do 3 razy.
- **Konflikt.** 409 z obiema wersjami (`mine`, `current`). Wiersze listy nie mają rozstrzygania: odświeżenie strony.
- **Rewizje.** Każdy udany zapis to jedna rewizja. Model `FieldRevision` odrzuca `update` i `delete`.
  Ponowienie identycznej wartości niczego nie zmienia. Okno łączenia dotyczy tylko dziennika aktywności
  (`fillmein.content.activity_coalesce_seconds`).
- **Autozapis (`client-editor.js`).** 800 ms po wpisaniu albo przy opuszczeniu pola, jedno żądanie naraz. Tekst leży
  w szkicu `localStorage`, dopóki serwer nie potwierdzi zapisu. Klucz szkicu zawiera ID projektu i pola, nigdy adres strony.
  Ponowienie: zdarzenie `online` i co 15 s. Po ponownym otwarciu szkic wraca ze starą wersją bazową, więc zmiana
  w międzyczasie daje konflikt, a nie nadpisanie.
- **Panel.** `/app/projects/{project}/pages/{page}/content` używa tych samych partiali i endpointów co klient
  (`HandlesContentWrites`, `ClientEditorService`), z Policy `editContent`, i pokazuje też pola ukryte.
- **Historia.** Klient: `?historia=ID&wiersz=UUID` na adresie edytora, bez nazwisk zespołu. Panel: trasa `content.history`.
  Dotyczy pól z wartością prostą. Listy i pliki nie mają przywracania.
- **Postęp.** `ProjectProgress` idzie po drzewie od aktywnych korzeni. Mianownik: pola wymagane. Projekt bez wymagań liczy
  każde pole raz. Szczegóły repeatera opisuje nagłówek klasy.
- Testy: `tests/stage08/run.sh`, audyt UI `tests/ui-stage08/run.sh`.

---

## 21. Zdjęcia i galerie (etap 09)

- `AssetService`: przyjęcie (rozmiar, `finfo`, `getimagesize`, wymiary, limit pikseli, `min_width`/`min_height` pola), SHA-256 z deduplikacją w projekcie, warianty `thumb` i `preview` przez `wp_get_image_editor`, serwowanie. Obraz mniejszy od wariantu nie jest powiększany; bez wariantu serwowany jest oryginał.
- Typy: JPG, PNG, WebP, GIF. SVG jest odrzucany, bo projekt nie ma sanitizera. Limity w `config/fillmein.php → assets` (`max_size_kb`, `max_dimension`, `max_pixels`, `uploads_per_minute`, `cache_seconds`).
- `ContentService`: `attachAsset`, `detachAsset`, `reorderAssets`. Lista UUID w `fmi_field_values.value` i `position` w `fmi_field_value_assets` zmieniają się w jednej transakcji, a każda zmiana to jedna rewizja.
- Usuwanie: odpięcie od pola. Wiersz `fmi_assets` (bez `deleted_at`) i plik zostają, co pozwala na deduplikację i wgląd w stare rewizje.
- Tekst alternatywny: `fmi_assets.alt`, wspólny dla pól używających tego samego pliku. `settings.require_alt` działa przez interfejs i postęp: pole jest uzupełnione dopiero z opisem każdego zdjęcia.
- Postęp: galeria wymaga `max(1, min_items)` zdjęć. `min_items` nie blokuje odpinania.
- Trasy: `fmi.projects.assets.show`, `fmi.client.assets.{store,reorder,detach,alt}`, `fmi.projects.pages.content.assets.{store,reorder,detach,alt}`. Przesyłanie: limit `fmi-uploads`; limit `fmi-client` podniesiony do 600/min, bo galeria ładuje miniatury przez tę samą trasę.
- `app/setup.php`: adresy `/fmi-storage/*` i `/wp-content/uploads/fmi-private/*` dają 404 zamiast przekierowania na `/app`.
- Historia i przywracanie wersji pól mediów nie są dostępne (etap 08 zwraca dla nich 404).

---

## 22. Aktualizacje z Figmy, przegląd treści i dziennik (etap 10)

- **Baza porównania.** `figma_meta.proposed` trzyma etykietę, typ i pozycję z ostatniego importu. Diff porównuje Figmę z Figmą, więc ustawienia zespołu nie są zmianą i zostają. Box, indeks i styl warstwy to dane techniczne: odświeżają się przy zastosowaniu, ale nie są liczone jako zmiana. Element bez `proposed` (sprzed etapu 10) raz pokazuje „uzupełnienie danych warstwy”.
- **Dopasowanie.** Pełne `figma_node_id` (dla instancji ścieżka `I…;…`) plus rola. Repeater zapamiętuje wzorzec w `meta.pattern`, a `FrameAnalyzer` wybiera go spośród elementów listy, więc dopisanie lub przestawienie kart nie zmienia ID pól. Gdy wzorzec zniknie, repeater jest rozpoznawany po `meta.items`, a jego pola po nazwie warstwy.
- **Kategorie.** Nowe, zmienione (`label`, `type`, `text`, `items`), przeniesione (`move`, `order`), do archiwum, bez zmian. `order` wynika z najdłuższego rosnącego podciągu pozycji, więc nowa warstwa nie oznacza sąsiadów.
- **Wybór.** Podgląd ma pole wyboru na zmianę; element nowy lub przywracany niesie swoje dzieci. Zmiana typu pola z treścią ma osobny klucz `t:…`, jest domyślnie odznaczona i po wyborze używa `clearForTypeChange`. Niewybrane zmiany wracają w kolejnym podglądzie.
- **Ochrona.** Kolejność zmienia tylko pozycje elementów `figma`, ręczne nie są zapisywane. Kontener z polami ręcznymi nie jest przenoszony. `truncated` opróżnia kategorię archiwum, a UI ostrzega. Odcisk podglądu obejmuje frame, analizę, wiersze i listę zmian ryzykownych.
- **Przegląd treści:** `fmi.projects.review` (Policy `editContent`): pola z wartością, statusem, autorem i datą, filtry podstrony, statusu i autora. Oznaczenie `review_*` w `fmi_field_values` nie zmienia wersji treści i zostaje do zdjęcia przez zespół. Komentarz widzi tylko zespół: MASTER_PLAN nie przewiduje widoku dla klienta, więc to odłożone.
- **Dziennik:** `fmi.projects.activity` (Policy `viewActivity`): filtry typu, autora, zakresu dat i podstrony, stronicowanie po 30. Podstrona obejmuje zdarzenia jej sekcji i pól. Wpis `figma.import_applied` ma liczniki wykonanych operacji.
- Testy: `tests/stage10/run.sh` (z LIVE), audyt UI `tests/ui-stage10/run.sh`.

## 23. Mapowanie ACF i eksport (etap 11)

- **Format:** `docs/export-format.md`, schemat `resources/schemas/fmi-export.schema.json`, wersja `ExportService::SCHEMA_VERSION`.
- **Mapowanie:** `AcfMappingService`. `fmi_wp_mappings` trzyma tylko nadpisania; domyślne wartości wynikają z typu i klucza pola. Sekcja to blok `acf/<slug>`, grupa `g_<klucz>`, repeater `r_<klucz>`.
- **Schemat strony:** `SiteMappingService` + `SiteMatcher` (`docs/site-schema.md`). Ze schematem `export.json` bierze nazwy i ścieżki ACF z dopasowania zamiast z `AcfMappingService`.
- **Nowy `FieldType`** wymaga decyzji w `AcfMappingService::definition()` i wpisu w schemacie; `match` bez `default` wymusza to błędem.
- **Pliki:** `exports/<uuid projektu>/<uuid eksportu>/{export.json,content.json,export.zip}` na prywatnym dysku `fmi`, 0600, pobieranie przez `ExportController`.
- **Uprawnienia:** `fmi_export` i przypisanie do projektu (`ProjectPolicy@export`), także dla edycji mapowania.
- **Walidator:** `ExportValidator` interpretuje podzbiór JSON Schema i sprawdza odwołania. `vendor/` nie ma walidatora, a nowa zależność nie była potrzebna.

## 24. Importer WP-CLI (etap 12)

- **Dokumentacja:** `docs/importer.md`. Polecenia `fmi:import` i `fmi:import:cleanup`, domyślnie dry-run, wyłącznie `http://fillmein.local`.
- **Kod:** `app/Services/Import/` (`WpImporter`, `ExportPackage`, `ImportCleanup`, `ImportEnvironment`, `ImportRefused`).
- **ACF Pro jest nieaktywny.** Importer ładuje go tylko w swoim procesie i blokuje zapisy modułu Site Health, bo aktywacja i te zapisy zmieniłyby opcje WP.
- **Testy:** transakcja `DB::` Laravela nie obejmuje `$wpdb`. Funkcje WordPressa w testach trzeba cofać przez `$wpdb->query('ROLLBACK')`.

## 25. Końcowe QA (etap 14)

- **Testy:** `tests/all/run.sh` (pełna regresja) i `tests/stage14/` (E2E, macierz tras z routera, sekrety, audyt UI). Opis: `docs/testy.md`.
- **Instrukcje:** `docs/instrukcja-pracownika.md`, `docs/instrukcja-klienta.md`.
- **Etap 13 jest odłożony.** Eksport i importer wystarczają do pracy.
- **Blokuje push:** incydent klucza z sekcji 13 i niezacommitowany kod aplikacji. Raport: `.ai-workflow/reports/FINAL.md`.

## 26. Połączenie OAuth z Figmą

- **Decyzja właściciela z 2026-10-30:** jedno wspólne połączenie dla całej aplikacji. Zmienia wcześniejszą zasadę „token nigdy w bazie”.
- **Kod:** `FigmaConnection`, `SecretBox`, `FigmaSettingsController`, trasy `/app/settings/figma` (`fmi_manage_integrations`).
- **Przepływ:** kod autoryzacji z PKCE, stan w sesji, tokeny szyfrowane w opcji `fmi_figma_connection`, automatyczne odświeżanie dzień przed wygaśnięciem.
- **Pierwszeństwo:** stała `FMI_FIGMA_TOKEN` wygrywa z połączeniem. Tak działają testy LIVE.
- **Sprawdzone z prawdziwą Figmą:** logowanie i odczyt frame'u przez OAuth. **Niezweryfikowane:** odświeżanie tokenu (dotąd tylko na atrapie, `tests/figma-oauth/run.sh`).

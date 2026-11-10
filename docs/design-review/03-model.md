# Design Review — etap 03: wspólny model projektów

Data: 2026-11-03. Zależności: 01 (audyt, kopia), 02 (nawigacja).

## Fakty i decyzje
- Projekt (`fmi_projects`, `id` + `uuid`) jest jeden dla obu modułów. Reużyte bez zmian: klient (`client_name`,
  linki `fmi_client_accesses`), użytkownicy (konta WP), członkostwo (`author_id`, `manager_id`, `fmi_project_members`),
  plik Figmy projektu (`figma_file_url`/`figma_file_key`), dziennik (`fmi_activity_log`), `ProjectPolicy` i `Project::visibleTo()`.
- Organizacji w modelu nie ma (jedna firma — etap 01). Izolację zapewnia zakres projektu: brak przypisania → 404.
- Moduły to **cecha projektu**: `content_enabled` (domyślnie 1) i `review_enabled` (domyślnie 0). Istniejące projekty
  zachowują się dokładnie jak wcześniej. Projekt musi mieć co najmniej jeden moduł (walidacja formularza).
- Sprawdzenie modułu to middleware `fmi.module:<moduł>` (`App\Http\Middleware\EnsureProjectModule`), a nie Policy —
  `ProjectPolicy::before` przepuszcza Administratora, a wyłączony moduł ma być niedostępny dla wszystkich. Wyłączony
  moduł → 404 (panel) lub strona nieaktywnego linku (klient).
- Brakujące encje: tylko `fmi_review_revisions` (rewizja projektu: numer w projekcie, tytuł, status, wskazanie
  pliku/frame'u Figmy, `figma_version`, notatki, autor, publikacja). Komentarze, wątki, decyzje i powiadomienia
  dojdą w etapach 07–11, każda jako osobna migracja addytywna powiązana z `project_id` / rewizją.
- Uprawnienia: nowa capability `fmi_manage_reviews` (Administrator, Project Manager; Pracownik — nie), `roles_version` 2 → 3.
  `ProjectPolicy::viewReview` (przypisanie do projektu) i `manageReviews` (przypisanie + `fmi_manage_reviews`).
  Moduły projektu zmienia `update` (ustawienia projektu: autor/opiekun z `fmi_create_projects`, Administrator).
- Wyłączenie modułu niczego nie usuwa (podstrony, treści, rewizje zostają). Treści: lista `/app/projects` pokazuje
  projekty z modułem Treści; Projekty `/app/design-review` — z Design Review; karta `/app/projects/{id}` jest wspólna.

## Zmiany
| Plik | Zmiana |
|---|---|
| `database/migrations/2026_11_03_000100_add_modules_and_review_revisions.php` | nowa migracja addytywna |
| `app/Enums/ProjectModule.php`, `app/Enums/ReviewRevisionStatus.php` | nowe |
| `app/Models/ReviewRevision.php` | nowy model |
| `app/Models/Project.php` | kolumny modułów, `usesModule()`, `modules()`, `scopeWithModule()`, `reviewRevisions()` |
| `app/Http/Middleware/EnsureProjectModule.php`, `functions.php` | middleware `fmi.module` |
| `routes/web.php` | trasy Treści w grupie `fmi.module:content` (mapowanie, eksport, przegląd treści, pliki, podstrony z treścią/strukturą/Figmą); klient `/p/{token}` z `fmi.module:content`. Adresy i nazwy tras bez zmian |
| `app/Http/Requests/ValidatesProjectModules.php`, `StoreProjectRequest.php`, `UpdateProjectRequest.php` | walidacja modułów |
| `app/Services/ProjectService.php` | zapis modułów przy tworzeniu i edycji (dziennik `project.updated`) |
| `app/Services/RoleManager.php`, `config/fillmein.php` | `fmi_manage_reviews`, `roles_version` 3 |
| `app/Policies/ProjectPolicy.php` | `viewReview`, `manageReviews` |
| `app/Support/ActivityPresenter.php` | etykiety zmian modułów |
| `app/Http/Controllers/App/ProjectController.php`, `DesignReviewController.php` | filtrowanie list po module |
| `resources/views/fillmein/projects/partials/modules.blade.php`, `create`, `edit`, `show` | wybór modułów, plakietki, karta bez Treści |
| `resources/views/fillmein/design-review/index.blade.php` | lista projektów z Design Review |
| `tests/model03/`, `tests/modules/`, `tests/nav/extra.php`, `tests/all/run.sh` | testy |

## Procedura bezpieczeństwa i migracja
1. `migrate:status`: `2026_11_03_000100` Pending.
2. Próba na kopii — `bash tests/model03/run.sh` → **78 PASS, 0 FAIL**:
   - kopia `fillmein-pre-model03-copy-20261103-192330.sql` odtworzona na izolowanym MySQL 8.4 (bez sieci, `--skip-grant-tables`, nazwa bazy jak na stronie); odtworzenie zgodne (78/78 jak w `tests/restore`);
   - znacznik `fmi_isolation_marker` widoczny dla WP-CLI tylko w kopii (dowód, że migracja nie trafiła do bazy strony);
   - migracja → 29 projektów z tymi samymi `id`, `uuid`, autorem, opiekunem, członkami, liczbą podstron, hashem treści (`fmi_field_values`) i linkami; wszystkie `content_enabled=1`, `review_enabled=0`;
   - `CHECKSUM TABLE` wszystkich tabel fmi poza `fmi_projects` bez zmian; liczebność tabel bez zmian (`migrations` +1); 0 sierot; FK `fmi_review_revisions.project_id → fmi_projects.id`;
   - capabilities: `fmi_manage_reviews` dla Administratora i PM, nie dla Pracownika; żadna dotychczasowa nie zniknęła;
   - `migrate:rollback --step=1` → CHECKSUM wszystkich tabel fmi (także `fmi_projects`) identyczny jak przed migracją; ponowna migracja działa.
3. Świeża kopia strony sprawdzona odtworzeniem: `tests/restore/run.sh` → 78/78, plik `fillmein-pre-restore-check-20261103-192351.sql`.
4. `bash tests/bin/wp.sh acorn migrate --backup=…192351.sql` → DONE (partia 7). Po migracji na stronie: 29 → 29 projektów, dane projektów zgodne z migawką sprzed migracji, moduły domyślne, capability jak wyżej.

## Testy
- `bash tests/modules/run.sh` → **70 PASS, 0 FAIL**:
  - projekty A (Treści), R (Design Review), D (oba): listy Treści/Projekty, ten sam `/app/projects/{id}` w obu modułach;
  - R: karta 200 z informacją o wyłączonych Treściach; treść, struktura, przegląd treści, eksport → 404 (także Administrator); link klienta R → strona nieaktywnego linku 404; A i D — treść 200;
  - zakres: pracownik spoza projektu → 404 na kartach R, D, A i treści D, brak na liście Projekty; Policy: `viewReview` członek/opiekun tak, spoza → 404; `manageReviews` PM tak, pracownik nie;
  - formularze: brak modułu i nieznany moduł odrzucone (projekt nie powstaje), „tylko Design Review” zapisuje `content=0, review=1`, formularz bez pól modułów (stare skrypty) → same Treści; edycja A: włączenie obu (dziennik), wyłączenie Treści (treść 404, podstrony zachowane), ponowne włączenie (200); pracownik nie zmienia modułów (403);
  - brak nowych kont (liczba użytkowników bez zmian).
- `bash tests/nav/run.sh` → 84/84 (A ma teraz oba moduły, więc jest w Projektach).
- `yarn build` — bez zmian w plikach wynikowych.
- `bash tests/all/run.sh` → 20/20 zestawów kod 0; stage14: E2E 68/68, macierz 95 tras 428/428, audyt UI 96/96.

Niewykonane: test „projekt z innej organizacji” w dosłownym sensie — organizacji nie ma w modelu; zastępuje go test zakresu projektu (pracownik spoza projektu, konto bez dostępu — etap 02).

## Ryzyka i ograniczenia
- Projekt tylko z Design Review nie ma jeszcze działającego linku klienta (link klienta = edytor treści); linki recenzji — etap 09.
- `fmi_review_revisions` jest na razie pusta; zapis rewizji i ich API — etap 04+.
- `Project::usesModule()` przy braku kolumny (kod bez migracji / po wycofaniu) traktuje projekt jak „tylko Treści” — kod działa także po rollbacku.

## Wycofanie
1. Jeśli `fmi_review_revisions` ma dane: eksport tabeli (`mysqldump` tylko tej tabeli) przed rollbackiem.
2. `wp acorn migrate:rollback --step=1` (przez `tests/bin/wp.sh` po dopisaniu do listy dozwolonych lub ręcznie przez właściciela) — usuwa kolumny modułów i tabelę rewizji; sprawdzone na kopii (sumy kontrolne jak przed migracją).
3. Kod: przywrócić pliki z listy „Zmiany” (bez kolumn `usesModule()` zwraca Treści, więc stary i nowy kod działają na starym schemacie).
4. Awaryjnie: odtworzenie `fillmein-pre-restore-check-20261103-192351.sql` (wykonuje właściciel; traci dane zapisane po 19:23).

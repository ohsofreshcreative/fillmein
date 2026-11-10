# Design Review — etap 04: panel Design Review

Data: 2026-11-03. Zależności: 01 (kopia/odtworzenie), 02 (nawigacja), 03 (model, moduły).

## Fakty i decyzje
- Lista recenzji: pozycja menu **Projekty** (`/app/design-review`). Zakres: `Project::visibleTo()` + moduł Design Review,
  bez zarchiwizowanych; paginacja 20 na stronę. Kolumny: nazwa i klient (zawijanie długich nazw, escapowanie),
  otwarte uwagi, status akceptacji (ostatnia rewizja), ostatnia aktywność, przycisk „Otwórz”.
- Liczniki liczone trzema zapytaniami grupowymi (`ReviewService::overview`), bez N+1:
  otwarte wątki `fmi_review_threads.status = open`, ostatnia rewizja (`MAX(id)` w projekcie),
  ostatnia aktywność = później z `MAX(fmi_activity_log.created_at)` i `fmi_projects.updated_at`.
- Do liczników potrzebna była encja wątków: minimalna tabela `fmi_review_threads` (projekt, rewizja, status, autor,
  rozwiązanie). Treść, pinezki i odpowiedzi — etapy 07–08, kolejne migracje addytywne.
- Recenzja projektu `/app/design-review/{project}` (`fmi.module:review` + `ProjectPolicy@viewReview`): liczniki,
  link prototypu, lista rewizji. Zapis linku `POST …/prototype`: `SavePrototypeRequest` → `manageReviews`
  (`fmi_manage_reviews`) → `ReviewService::savePrototype`.
- Walidacja linku (`FigmaUrl::prototypeError/prototype`): tylko `https://figma.com` / `www.figma.com`, ścieżka
  `/proto/{key}` lub `/design/{key}` (gałęzie `/branch/{key}`), bez portu i danych logowania, poprawne `node-id`
  / `starting-point-node-id`, maks. 500 znaków. Komunikaty po polsku, konkretne dla przyczyny.
- **Serwer nie otwiera linku** (BEZPIECZENSTWO.md: bez pobierania dowolnych adresów). Wersję pliku Figmy utrwali etap
  akceptacji; teraz `figma_version` = null. Brak sieci nie wpływa na zapis.
- Szkic rewizji jest aktualizowany; gdy ostatnia rewizja jest już w recenzji/zaakceptowana — powstaje nowa rewizja
  (szkic, numer +1), poprzednia zostaje nietknięta (nowa rewizja wymaga nowej decyzji).
- Link w widoku: `target=_blank`, `rel="noopener noreferrer"`, `referrerpolicy="no-referrer"`. Osadzenia (iframe) nie ma — etap 06.
- Pracownik bez `fmi_manage_reviews` widzi recenzję w trybie „Tylko podgląd” (bez formularza); POST → 403.
  Spoza projektu → 404, projekt bez Design Review → 404 (także Administrator).
- Stan ładowania: formularz z `data-loading-form` (blokada przycisku). Puste stany: lista, brak linku, brak rewizji.

## Zmiany
| Plik | Zmiana |
|---|---|
| `database/migrations/2026_11_03_000200_create_fmi_review_threads_table.php` | nowa tabela (addytywnie) |
| `app/Enums/ReviewThreadStatus.php`, `app/Models/ReviewThread.php` | nowe |
| `app/Models/Project.php`, `ReviewRevision.php` | relacje `reviewThreads()`, `threads()` |
| `app/Enums/ReviewRevisionStatus.php` | `badge()` |
| `app/Enums/MappableType.php`, `app/Providers/FillMeInServiceProvider.php` | aliasy `review_revision`, `review_thread` |
| `app/Integrations/Figma/FigmaUrl.php` | walidacja linku prototypu |
| `app/Services/ReviewService.php` | nowy: liczniki, zapis linku |
| `app/Http/Requests/SavePrototypeRequest.php` | nowy |
| `app/Http/Controllers/App/DesignReviewController.php` | `index` z licznikami, `show`, `savePrototype` |
| `routes/web.php` | `fmi.design-review.show`, `fmi.design-review.prototype` |
| `app/Support/ActivityPresenter.php` | `review.prototype_saved`, `review.revision_created` |
| `resources/views/fillmein/design-review/{index,show}.blade.php`, `partials/status.blade.php` | widoki |
| `resources/views/components/ui/page-header.blade.php` | akcje nagłówka zawijają się na wąskich ekranach |
| `tests/review-panel/`, `tests/migrate-copy/`, `tests/all/run.sh`, `tests/modules/run.sh`, `tests/stage14/{seed.php,security.sh}` | testy |

## Migracja
1. Próba na izolowanej kopii — `bash tests/migrate-copy/run.sh` (uogólnienie `tests/model03`) → **79 PASS, 0 FAIL**:
   znacznik izolacji, 0 zmian w dotychczasowych tabelach (CHECKSUM, liczebności, `migrations` +1), nowa tabela pusta,
   3 nowe FK, 0 sierot, rollback przywraca sumy, ponowna migracja identyczna.
2. Świeża kopia sprawdzona odtworzeniem: `tests/restore/run.sh` → 82/82, `fillmein-pre-restore-check-20261103-195227.sql`.
3. `tests/bin/wp.sh acorn migrate --backup=…195227.sql` → DONE (partia 8).

## Testy
- `bash tests/review-panel/run.sh` → **71 PASS, 0 FAIL**:
  - zbiory: pusty (pracownik bez projektów → pusty stan), mieszany (członek widzi R i D, nie A bez modułu ani cudzych),
    duży (45+ projektów opiekuna: liczba pozycji = DB, 20/strona, ostatnia strona, strona poza zakresem), Administrator;
  - liczniki z HTML = baza: otwarte uwagi, status akceptacji, ostatnia aktywność; po rozwiązaniu i ponownym otwarciu wątków;
    wątek innego projektu nie wpływa na liczniki;
  - długa nazwa (190 znaków) z zawijaniem, nazwa z `<script>` escapowana;
  - uprawnienia: członek tylko podgląd (POST 403), spoza projektu 404, projekt bez Design Review 404, nieistniejący 404, gość → logowanie, brak CSRF 419;
  - błędne linki z komunikatem: pusty, http, obca domena podszywająca się pod figma.com, `javascript:`, dane logowania, port, FigJam, zły `node-id`, za długi — żadna rewizja nie powstaje;
  - zapis: rewizja 1 (szkic), komunikat, link z `noopener noreferrer`; po zaakceptowanej rewizji — nowa rewizja 2;
  - brak sieci (`tests/review-panel/network.php`, `Http::fake` rzucający `ConnectionException`): zapis działa, 0 żądań HTTP.
- `yarn build`, `view:cache`, `fmi:check` — OK.
- `bash tests/all/run.sh` → 19/21 zestawów kod 0. Dwa padły z powodu nowych tras, nie regresji działania:
  - `modules` oczekiwał linku listy Projekty do `/app/projects/{id}`; lista prowadzi teraz do recenzji
    `/app/design-review/{id}` (ten sam identyfikator) → test zaktualizowany, **70/70**;
  - `stage14`: projekt testowy nie miał modułu Design Review (nowe trasy → 404) — seed włącza oba moduły; macierz traktuje
    `/app/design-review/{id}` jak trasę projektu (spoza projektu → 404). Audyt wykrył przepełnienie 3 px na
    `fmi.projects.show` przy 375 px (dwie plakietki modułów) → akcje `<x-ui.page-header>` zawijają się (`flex-wrap`).
    Ponownie: E2E 68/68, audyt UI 99/99, macierz 97 tras 436/436.

Niewykonane: pomiar „wczytywania” w przeglądarce — lista renderowana po stronie serwera, stan ładowania dotyczy tylko formularza.

## Ryzyka i ograniczenia
- Brak tworzenia wątków w UI (etap 07–08) — liczniki są realne, ale dziś pochodzą tylko z danych testowych.
- Status akceptacji zmieni się dopiero, gdy pojawi się publikacja i decyzje (etapy 09–10).
- Projekt tylko z Design Review nie ma jeszcze linku klienta do recenzji (etap 09).

## Wycofanie
1. Jeśli `fmi_review_threads` / `fmi_review_revisions` mają dane — eksport tych tabel.
2. `wp acorn migrate:rollback --step=1` — usuwa tylko `fmi_review_threads` (sprawdzone na kopii).
3. Kod: przywrócić pliki z tabeli „Zmiany”; trasy `show`/`prototype` usunąć z `routes/web.php`.
4. Awaryjnie: odtworzenie `fillmein-pre-restore-check-20261103-195227.sql` (właściciel).

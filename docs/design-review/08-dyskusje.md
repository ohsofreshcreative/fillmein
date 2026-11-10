# Design Review — etap 08: dyskusje i statusy uwag (2026-11-03)

## Fakty i decyzje

- **Statusy:** Nowy (`open`, wartość z etapu 04 — bez migracji danych), W trakcie (`in_progress`), Rozwiązany (`resolved`).
  „Otwarte uwagi” w panelu = Nowy + W trakcie (`ReviewThreadStatus::unresolved()`).
- **Uprawnienia** (`ProjectPolicy`, tylko capabilities, zawsze w zakresie projektu — obcy wątek/wiadomość → 404):

  | Operacja | Kto |
  |---|---|
  | Odpowiedź w wątku | każda osoba przypisana do projektu (`viewReview`) |
  | Zmiana statusu (dowolna) | `fmi_manage_reviews` (opiekun, administrator) |
  | Ponowne otwarcie rozwiązanej uwagi | jak wyżej **albo** autor uwagi |
  | Edycja wiadomości | wyłącznie jej autor — także opiekun nie zmienia cudzych wypowiedzi |

- **Zasady edycji:** tylko w wątku nierozwiązanym; poprzednia treść trafia do historii (`previous_body`), wiadomość dostaje
  znacznik „edytowano”. Bez usuwania wiadomości. W wątku rozwiązanym nie ma odpowiedzi ani edycji — najpierw ponowne otwarcie.
- **Historia** (`fmi_review_thread_events`, tylko dopisywana): `status_changed`, `reopened`, `comment_edited` z autorem,
  czasem i statusami z → do. Utworzenie = pierwsza wiadomość (autor, data). Dziennik aktywności: `review.reply_added`,
  `review.comment_edited`, `review.thread_status` (bez treści).
- **Jednoczesne zmiany:** `lock_version` wątku i wiadomości z chwili wczytania formularza. Inna wersja → 409 / komunikat
  pod wątkiem, nic nie zapisano, treść użytkownika zostaje w formularzu. Jeśli stan docelowy już obowiązuje (ponowienie
  po timeoucie, ta sama decyzja z dwóch kart) — sukces bez drugiego wpisu w historii.
- **Ponowienia bez duplikatów:** każda odpowiedź (i nowa uwaga z etapu 07) ma `request_id` (UUID z formularza / przeglądarki),
  unikalny w projekcie; ponowienie zwraca istniejący wpis. Odpowiedzi z dwóch sesji to różne wpisy — zapisują się obie
  (wątek blokowany w transakcji). Limity: wiadomości 20/min, statusy 60/min na osobę.
- **Filtry:** `?status=nowe|w-trakcie|rozwiazane` z licznikami; nieznana wartość = wszystkie. Formularze wracają do wątku z tym samym filtrem.
  Pinezki na ekranach pokazują wszystkie uwagi rewizji niezależnie od filtra.
- Formularze działają bez JavaScriptu (POST + przekierowanie), JSON przy `Accept: application/json`.

## Zmiany

- Migracja `2026_11_03_000400_add_review_discussions` (addytywna; próba na kopii 99/99 i 86/86, kopia z odtworzeniem 99/99:
  `~/Desktop/fillmein-pre-restore-check-20261103-214110.sql`; wykonana, batch 10).
- `ReviewThreadStatus` (3 statusy, etykiety, filtr), `ReviewThreadEvent`, `ReviewConflictException`, `ReviewDiscussionService`.
- `ProjectPolicy::changeReviewStatus`, `editReviewComment`; FormRequesty `StoreReviewReplyRequest`, `ChangeReviewStatusRequest`,
  `UpdateReviewCommentRequest` (+ `Concerns/ResolvesReviewThread`); `request_id` w `StoreReviewCommentRequest`/`ReviewCommentService`.
- Trasy: `threads/{thread}/replies`, `threads/{thread}/status`, `threads/{thread}/comments/{comment}`; limiter `fmi-review-status`.
- Widok `partials/threads.blade.php` (filtry, wątki, odpowiedzi, edycja, statusy, historia); `review-composer.js` — `request_id`.

## Testy

`tests/review-discussions/run.sh`: serwis 29/29, HTTP (pełny cykl statusów z filtrami i licznikami, konflikty 409, ponowienia,
dwie sesje równolegle, 5 równoległych ponowień → 1 wpis, przerwane żądanie po 10 ms + ponowienie → 1 wpis, odmowy 403/404,
CSRF 419, XSS, limit 429), przeglądarka 24/24 (klawiatura, podwójne kliknięcie, statusy, filtry, historia, edycja, axe, 375 px).

## Ograniczenia

- Klienci (link gościa) nie odpowiadają jeszcze w wątkach — etap linków klientów; kolumny autora `access` są gotowe.
- Brak usuwania wiadomości (świadomie — historia dyskusji). Brak powiadomień — etap powiadomień.

## Wycofanie

`bash tests/bin/wp.sh acorn migrate:rollback --step=1` usuwa historię i nowe kolumny (odpowiedzi i statusy W trakcie
zostają w tabelach wątków/wiadomości — przed wycofaniem zrób kopię; status `in_progress` po wycofaniu kodu zamień na `open`)
oraz przywrócenie plików z etapu 07; ewentualnie odtworzenie kopii wskazanej wyżej.

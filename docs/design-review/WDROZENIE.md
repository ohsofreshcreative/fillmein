# Design Review — plan wdrożenia, kontrola po uruchomieniu, wycofanie

**Status: przygotowany, NIE wykonany.** Wdrożenie wymaga osobnego upoważnienia właściciela do konkretnego wdrożenia
(środowisko, termin, osoba). Repozytorium nie opisuje środowiska produkcyjnego ani sposobu publikacji. Kroki poniżej
trzeba dopasować do serwera przed użyciem.

## Warunki wstępne (blokady)
1. **Incydent klucza SSH** (PROJECT.md → „Bezpieczeństwo”): przed publikacją usuń plik `key` z repozytorium i historii
   oraz zrotuj klucz. Do tego czasu nie wdrażaj z tego repozytorium.
2. Upoważnienie właściciela do wdrożenia oraz okno serwisowe (migracje są addytywne i krótkie, liczone w milisekundach na kopii lokalnej).
3. Zielona regresja na wdrażanej wersji: `bash tests/all/run.sh` (30 zestawów) i próba migracji na kopii produkcji
   (`REPLAY_STEPS` niepotrzebne: na produkcji migracje są oczekujące, `bash tests/migrate-copy/run.sh`).
4. Decyzje właściciela (opcjonalne, nie blokują):
   - dodanie originu produkcji w aplikacji OAuth Figmy (zdarzenia Embed API, etap 05);
   - maskowanie ścieżek `/p/*` i `/r/*` w logach dostępowych serwera WWW;
   - infrastruktura e-mail (kolejka), jeśli powiadomienia mają iść mailem.

## Kroki wdrożenia
1. **Kopia bazy produkcji i próba odtworzenia** (procedura `tests/restore` na kopii). Zapisz nazwę pliku kopii.
2. **Kod:** opublikuj motyw z `public/build` (artefakty `yarn build` są w repozytorium). Bez `composer`/`yarn` na serwerze,
   chyba że tak wygląda proces wdrożenia.
3. **Migracje** (8 nowych, wszystkie addytywne): `wp acorn migrate:status`, potem `wp acorn migrate --force`.
   - `2026_11_03_000100` moduły projektu + rewizje, `000200` wątki, `000300` ekrany i wiadomości, `000400` dyskusje,
     `000500` linki recenzji (`scope`; istniejące linki dostają `content`), `000600` decyzje, `000700` powiadomienia,
     `000800` indeks wycofywalności.
   - Istniejące projekty dostają moduł Treści włączony i Design Review wyłączony. Treści i stare linki `/p/…` się nie zmieniają.
4. **Role:** `wp acorn fmi:roles` (nowe capabilities `fmi_manage_reviews`, `fmi_manage_client_access`), jeśli proces
   wdrożenia nie robi tego sam przy zmianie `fillmein.roles_version`.
5. **Cache widoków:** `wp acorn view:cache` (albo `view:clear`).
6. **Kontrola:** `wp acorn fmi:check`.

## Kontrola po uruchomieniu (pierwsza godzina)
| # | Sprawdzenie | Oczekiwane |
|---|---|---|
| 1 | Stary link klienta Treści `/p/…` (istniejący projekt) | edytor otwiera się, zapis działa |
| 2 | Logowanie, Pulpit, Treści, Użytkownicy | bez zmian, bez błędów 500 |
| 3 | Projekt testowy: włącz Design Review, zapisz prototyp | ramka Figmy (albo komunikat Figmy dla prywatnego pliku) |
| 4 | Uwaga, odpowiedź, zmiana statusu | zapis, licznik, historia |
| 5 | Link recenzji (7 dni, komentarz) → okno prywatne | `/review` bez tokenu w adresie, informacja o Figmie |
| 6 | Unieważnij link testowy | kolejne żądanie gościa → 403 |
| 7 | Decyzja + nowa rewizja | stary status w historii, nowa „Oczekuje na decyzję” |
| 8 | Powiadomienia innej osoby w projekcie | jedno na zdarzenie, link do kontekstu |
| 9 | Log aplikacji (`laravel.log`) i log serwera z czasu testu | brak tokenów linków, brak błędów 500 |
| 10 | Nagłówki `/review`: `Referrer-Policy: no-referrer`, CSP `frame-src` Figmy | obecne |
Projekt testowy usuń po kontroli (lub archiwizuj). **Nie wysyłaj linków testowych prawdziwym klientom.**

## Wycofanie
- **Szybkie (bez zmian bazy):** wyłącz moduł Design Review w projektach. Treści działają niezależnie. Albo przywróć
  poprzednią wersję motywu: nowe tabele i kolumny nie przeszkadzają staremu kodowi, bo są addytywne.
- **Pełne (z bazą):** najpierw zrób kopię z danymi recenzji, bo to dowód decyzji. `fmi_review_decisions.down()` odmawia
  usunięcia tabeli z decyzjami. Po zabezpieczeniu danych: `wp acorn migrate:rollback --step=8` (sprawdzone na kopii, etap 12)
  i przywrócenie poprzedniej wersji motywu.
- **Awaryjne:** odtworzenie kopii z kroku 1. Dane zapisane po wdrożeniu przepadają, więc wcześniej je wyeksportuj.

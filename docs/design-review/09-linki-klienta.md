# Design Review — etap 09: udostępnianie klientowi (2026-11-03)

## Fakty

- Fill Me In ma już linki klientów (`fmi_client_accesses`, `ClientAccessService`): token 256 bitów z CSPRNG (base64url),
  w bazie tylko SHA-256, termin ważności, unieważnienie, `Referrer-Policy: no-referrer`, layout bez panelu.
  Do tej pory otwierały wyłącznie edytor Treści `/p/{token}` (token w każdym adresie).
- Ograniczenia infrastruktury: brak wysyłki e-mail (etap powiadomień), nginx w Local nie prowadzi access.log.

## Decyzje

- **Jeden system linków:** ta sama tabela i serwis, nowa kolumna `scope` (`content` — wszystkie dotychczasowe linki,
  `review` — recenzja). Link jednego zakresu nie otwiera tras drugiego (`/p` ⇄ `/r`), regeneracja linków Treści nie
  dotyka linków recenzji.
- **Jawne możliwości:** odczyt zawsze; `can_comment` (uwagi, pinezki, odpowiedzi), `can_approve` (zapisane i egzekwowane
  przez `fmi.review-guest:approve`; sama akcja akceptacji rewizji — etap 10). Gość nie zmienia statusów i nie edytuje.
- **Zawsze z terminem:** 7 / 30 / 90 dni (`fillmein.review.link_ttl_days`).
- **Opcjonalny kod dostępu** (6–64 znaki, `password_hash`), przekazywany innym kanałem; 5 błędnych prób na link i adres IP → blokada 15 min.
- **Wymiana tokenu na ograniczoną sesję:** `/r/{token}` weryfikuje token, regeneruje ID sesji, zapisuje w sesji wyłącznie
  ID linku i przekierowuje na `/review` — dalsze adresy, widoki i dziennik nie zawierają tokenu. Wejście nie zapisuje adresu
  z tokenem jako „poprzedniego URL” sesji. Link jest sprawdzany przy każdym żądaniu: po unieważnieniu/wygaśnięciu → 403.
- **Zakres projektu na serwerze:** projekt wynika tylko z linku w sesji; wątki i ekrany szukane w jego zakresie (obce ID → 404/422),
  pole `project_id` w żądaniu jest ignorowane. Sesja gościa nie loguje do panelu (`/app/*` → logowanie).
- **Tożsamość deklarowana:** gość podaje imię/nazwę (≤ 80 znaków, bez znaczników) przed komentowaniem; zapis
  `author_type = client`, `author_access_id`, `author_name`; w panelu i u gościa zawsze z dopiskiem „Gość · nazwa deklarowana”.
- **Figma:** link nie daje dostępu do pliku. Prototyp osadza Figma według ustawień udostępniania pliku (strona pokazuje to
  wprost); żądania do Figmy bez Referer. Ekrany rewizji to obrazy z Fill Me In (kopia z chwili dodania) — widoczne w recenzji.
- **Limity:** wejście przez token 20/min na IP, zapisy gościa 20/min na link i IP, żądania strony jak linki Treści.

## Zmiany

- Migracja `2026_11_03_000500_add_review_client_links` (addytywna; kopia 105/105 i 88/88, odtworzenie 105/105,
  `~/Desktop/fillmein-pre-restore-check-20261103-220759.sql`; batch 11).
- `ClientAccess` (zakresy, możliwości, kod), `ClientAccessService::generateReview/resolveReview/activeReview/checkCode`,
  `resolve()` tylko zakres `content`; `IssuedClientAccess` z prefiksem adresu.
- `ResolveReviewGuest` (`fmi.review-guest[:comment|approve]`), `ReviewGuestController`, `ReviewGuest`, FormRequesty gościa,
  autor-gość w `ReviewCommentService` i `ReviewDiscussionService`, `ReviewComment::author_name/isGuest`.
- Panel: karta „Linki dla klienta” na stronie recenzji (`fmi_manage_client_access`), jednorazowy widok adresu, unieważnienie.
- Widoki gościa: `client/review`, `client/review-unlock`, `client/review-left`, `client/review-forbidden`, `client/partials/review-threads`.
- Testy: `tests/review-links` (nowy), strefa `review` w macierzy stage14.

## Testy

`tests/review-links/run.sh` — 78/78 (HTTP + headless Chromium 19/19): podmiana ID projektu, wątku i ekranu; wygasły,
odwołany, odwołany i wygaszony w trakcie sesji; bez prawa komentarza; bez prawa akceptacji (middleware); kod dostępu
z blokadą po 5 próbach; limit wejść 429; rozdzielenie zakresów Treści/recenzji; panel niedostępny z sesją gościa;
odmowa Figmy w ramce przy działającej recenzji; brak tokenów w HTML, laravel.log, logach nginx/PHP, plikach sesji i dzienniku.

## Ograniczenia

- Akcja akceptacji rewizji nie istnieje do etapu 10 — tam powstanie trasa za `fmi.review-guest:approve`.
- Ekrany rewizji (obrazy z Fill Me In) widzi każdy z linkiem recenzji niezależnie od uprawnień Figmy — to celowy podgląd projektu.
- Nazwa gościa nie jest weryfikowana; to nie podpis. Dane gościa: tylko nazwa przy wiadomości; retencja jak dla uwag (do usunięcia projektu).

## Wycofanie

Unieważnij linki recenzji, potem `bash tests/bin/wp.sh acorn migrate:rollback --step=1` (usuwa zakres, możliwości, kod
i `author_name`; wiadomości gości zostają jako „Gość”) i przywróć pliki z etapu 08; ewentualnie odtworzenie kopii.

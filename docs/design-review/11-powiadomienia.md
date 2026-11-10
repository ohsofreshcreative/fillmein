# Design Review — etap 11: powiadomienia i historia

## Powiadomienia w panelu
- Tabela `fmi_notifications` (migracja `2026_11_03_000700`, addytywna; batch 13): odbiorca, projekt, typ
  (`review.comment` | `review.reply` | `review.decision`), `event_key` = typ + ID zdarzenia, `subject_id`, `data`
  (numer uwagi / rewizji, decyzja, aktor, fragment ≤ 140 znaków), `read_at`, `created_at`. Bez adresów i tokenów.
- Zdarzenia: nowa uwaga (`ReviewCommentService::create`), odpowiedź (`ReviewDiscussionService::reply`), decyzja
  (`ReviewDecisionService::decide`) — także od gościa z linkiem. `NotificationService::notify` w tej samej transakcji.
- Odbiorcy: osoby przypisane do projektu (`ProjectAssignmentService::assignedUserIds`: autor, opiekun, członkowie)
  z aktywnym kontem aplikacji (bez dezaktywowanych), które przechodzą `ProjectPolicy@viewReview`; moduł Design Review
  musi być włączony. Autor zdarzenia nie dostaje powiadomienia o sobie. Administratorzy z `fmi_manage_all_projects`
  bez przypisania — bez powiadomień (dostęp ≠ udział).
- Deduplikacja: unikalne (`user_id`, `event_key`) + `insertOrIgnore` — ponowna obsługa zdarzenia nic nie dodaje;
  ponowienie żądania z tym samym `request_id` nie dochodzi do zapisu zdarzenia (etap 08).
- Strona `/app/notifications` (pozycja „Powiadomienia” z licznikiem nieprzeczytanych w nawigacji), filtr
  „Nieprzeczytane”, „Oznacz jako przeczytane”, „Oznacz wszystkie jako przeczytane”.
- Link bezpośredni `/app/notifications/{id}`: tylko własne (inaczej 404); **przy każdym wejściu** ponowna kontrola
  `viewReview` i modułu — odebrany dostęp daje 404 także z wcześniejszego powiadomienia. Po kontroli: oznaczenie
  jako przeczytane i przekierowanie do kontekstu (`#uwaga-N` albo `#decyzja`). Lista i licznik pomijają projekty,
  których osoba już nie widzi.

## Historia projektu
- Istniejący dziennik `fmi_activity_log` i strona `/app/projects/{project}/activity` (ProjectPolicy@viewActivity).
  Nowy filtr obszaru „Design Review” (`review.*`), etykieta `client_access.review_generated`.
- Kontekst zdarzeń recenzji: „Rewizja N · Uwaga M”, decyzja („Do poprawy” / „Zaakceptowana”), status uwagi.
- Aktor: konto pracownika; gość — nazwa z dopiskiem „(gość, nazwa deklarowana)” (`guest_name` w zdarzeniu od tego
  etapu; starsze wpisy gościa: „Klient (link dostępowy)”). Bez tokenów (sprawdzone testem).

## E-mail — odroczony
Audyt istniejącej wysyłki: jedyne użycie to `wp_mail()` synchronicznie w `InvitationService` (zaproszenia), bez
kolejki (brak `config/queue.php` i tabel zadań), bez ponowień i bez rejestru błędów dostarczenia poza
`wp_mail_failed` w chwili wysyłki; brak skonfigurowanego SMTP w motywie (Local: Mailpit). Powiadomienia e-mail
wymagałyby kolejki z ponowieniami, obsługi błędów i ochrony listy odbiorców (osobne wiadomości) — **odroczone**
do decyzji o infrastrukturze. Testy kolejki, retry i dostaw: nie dotyczy.

## Testy
- `tests/notifications/run.sh` — serwis (odbiorcy, deduplikacja, dezaktywacja, moduł wyłączony, historia) i HTTP
  (lista, licznik, odczyt, link do kontekstu, cudze powiadomienia, CSRF, odebranie dostępu, historia, XSS, tokeny).
- `tests/stage14` — trasy powiadomień w macierzy (powiadomienie jako zasób jednej osoby) i audycie UI.

## Wycofanie
`wp acorn migrate:rollback --step=1` (powiadomienia to dane pochodne; historia zostaje w dzienniku), przywrócenie
plików z etapu 10 albo odtworzenie kopii `~/Desktop/fillmein-pre-restore-check-20261103-230457.sql`.

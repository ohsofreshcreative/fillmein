# Instrukcja pracownika

Fill Me In zbiera treści od klientów na podstawie projektu w Figmie. Pracujesz w panelu `/app`, a klient wypełnia pola pod osobnym linkiem i nie zakłada konta.

## 1. Projekt i podstrony
1. W **Projekty** utwórz projekt. Nazwa jest wymagana, a klient, opiekun, członkowie i link do pliku Figmy są opcjonalne.
2. Na karcie projektu dodaj podstrony. Podstronę możesz zagnieździć pod inną, a kolejność zmienisz przeciąganiem lub przyciskami wyżej/niżej.
3. Podstrony i pola nie są usuwane, tylko archiwizowane, więc można je przywrócić.

Status projektu to szkic, zbieranie treści, przegląd, zakończony lub archiwum. Pracownik nieprzypisany do projektu go nie widzi.

## 2. Figma
Połączenie z Figmą jest jedno, wspólne dla całej aplikacji. Zakłada je raz administrator w **Figma** (menu boczne): zapisuje Client ID i Client Secret aplikacji OAuth, klika **Połącz z Figmą**, loguje się i zatwierdza dostęp. Aplikacja widzi tylko pliki dostępne dla połączonego konta.

1. W podstronie wklej link do konkretnego frame'u, czyli adres z parametrem `node-id`.
2. Pobierz frame. Dane trafiają do podglądu, a nie od razu do struktury.
3. W **Import z Figmy** sprawdź propozycję sekcji i pól, wybierz zmiany i zastosuj.
4. Warstwy, których nie chcesz, wyklucz regułą. Ręcznie zarchiwizowane pole wraca przy imporcie, jeśli warstwa nadal jest w pliku.

Ponowny import pokazuje różnice i nie zmienia elementów dodanych ręcznie, z jednym wyjątkiem: przeniesienie kontenera w Figmie przenosi też jego ręczne pola potomne.

## 3. Konfiguracja sekcji i pól
W **Struktura** dodajesz sekcje i pola. Dostępne typy to tekst, tekst wielowierszowy, WYSIWYG, link, zdjęcie, galeria, grupa i repeater.

- **Pole wymagane** liczy się do postępu.
- **Pole ukryte** widzi tylko zespół.
- Zdjęcie może wymagać tekstu alternatywnego i minimalnego rozmiaru, a galeria i repeater mogą mieć limit elementów.
- Zmiana typu pola z treścią wymaga potwierdzenia. Poprzednia treść zostaje w historii.

## 4. Link dla klienta
Na karcie projektu wygeneruj link i wybierz ważność: 7, 30 albo 90 dni, rok lub bez terminu.

**Link jest widoczny tylko raz.** Skopiuj go od razu, bo w bazie leży wyłącznie skrót. Zgubiony link wygeneruj ponownie, a stary przestanie działać. Link można też odwołać.

## 5. Przegląd treści

Przycisk **Podgląd** przy podstronie pokazuje ją sekcja po sekcji: projekt z Figmy, a obok treść klienta i braki. Opcja „Pokaż tylko to, czego brakuje” zostawia same luki, a przyciski na dole prowadzą do kolejnej podstrony. Obok podstrony na liście widać też jej postęp. Przycisk **Edytuj sekcję** przenosi do edytora tej sekcji, gdzie zmiany zapisują się same, a screen z projektu zostaje obok pól.
**Przegląd** pokazuje treści wpisane przez klienta. Do wartości możesz dodać uwagę i później ją zdjąć. Własne poprawki wpisujesz w edytorze treści podstrony, gdzie jest też historia wersji. **Aktywność** to dziennik zmian w projekcie.

## 6. Mapowanie i eksport
1. W **Mapowanie** ustaw dla każdej podstrony typ wpisu, adres i szablon oraz nazwy i typy pól ACF.
2. W **Eksporty** wygeneruj eksport. Przed zapisem przechodzi walidację, a błędy widać na liście.
3. Pobierz JSON lub ZIP z plikami. Format opisuje [export-format.md](export-format.md).

## 7. Import do WordPressa
Import wykonuje się w terminalu i działa tylko na stronie testowej `http://fillmein.local`. Tryb produkcyjny nie istnieje. Domyślnie polecenie niczego nie zapisuje i pokazuje plan. Pełny opis: [importer.md](importer.md).

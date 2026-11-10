# Fixture Figma API (etap 04)

Pliki JSON są **syntetycznymi danymi wejściowymi testów**, przygotowanymi ręcznie w kształcie odpowiedzi
Figma REST API (`GET /v1/files/:key/nodes`, `GET /v1/images/:key`) według publicznej dokumentacji.
Nie pochodzą z prawdziwego pliku klienta i nie są wynikiem testu live — ten wymaga tokenu
(`tests/stage04/live.php`). Adresy obrazów wskazują dozwolony host, ale nigdy nie są pobierane
z sieci: testy podstawiają odpowiedzi przez `Illuminate\Http\Client\Factory::fake()`.

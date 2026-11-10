<?php

/*
|--------------------------------------------------------------------------
| Fill Me In — konfiguracja aplikacji
|--------------------------------------------------------------------------
|
| Wartości bez sekretów. Klucze zewnętrzne (np. token Figma API) nie trafiają
| do tego pliku ani do repozytorium — patrz PROJECT.md, sekcja „Bezpieczeństwo”.
|
*/

return [

	// Locale komunikatów Laravela (walidacja) na trasach aplikacji.
	'locale' => 'pl_PL',

	// Wersja definicji ról i capabilities. Podbij po zmianie w App\Services\RoleManager,
	// aby role zsynchronizowały się przy najbliższym żądaniu (lub `wp acorn fmi:roles`).
	'roles_version' => 3,

	'auth' => [
		// Limit prób logowania na konto (login + IP) i okno blokady w sekundach.
		'max_attempts' => 5,
		'decay_seconds' => 300,

		// Limit prób logowania z jednego adresu IP (niezależnie od konta).
		'max_attempts_per_ip' => 30,
		'ip_decay_seconds' => 900,

		// Minimalna długość hasła ustawianego w aplikacji.
		'password_min_length' => 12,
	],

	'invitations' => [
		// Ważność linku z zaproszenia (natywny klucz resetu hasła WP; domyślnie WP daje 1 dzień).
		'expiration_days' => 7,
	],

	'client_access' => [
		// Liczba losowych bajtów tokenu (32 B = 256 bitów, ~43 znaki base64url).
		'token_bytes' => 32,

		// Domyślna ważność linku w dniach; null = bez limitu (można ustawić per link).
		'default_ttl_days' => null,

		// Jak często (w sekundach) aktualizować last_used_at, by nie zapisywać przy każdym żądaniu.
		'touch_interval' => 300,
	],

	'content' => [
		// Okno (w sekundach), w którym kolejne zapisy pola tej samej osoby dają jeden wpis w dzienniku aktywności.
		'activity_coalesce_seconds' => 300,
	],

	'pages' => [
		// Maksymalny poziom zagnieżdżenia podstron (0 = podstrona w korzeniu).
		'max_depth' => 3,

		// Liczba podstron na stronę listy w panelu projektu.
		'per_page' => 50,
	],

	// Design Review: limity uwag i ekranów rewizji.
	'review' => [
		// Nowe uwagi, odpowiedzi i edycje (wspólny limit) oraz zmiany statusu — na osobę i adres IP.
		'comments_per_minute' => 20,
		'status_per_minute' => 60,
		// Linki recenzji dla klienta: próby wejścia (na IP / min), błędne kody dostępu (na link i IP / 15 min),
		// linki recenzji nie mają terminu ważności (działają do unieważnienia).
		'link_attempts_per_minute' => 20,
		'code_attempts' => 5,
		'max_screens' => 30,
	],

	'projects' => [
		// Liczba projektów na stronę listy /app/projects.
		'per_page' => 20,
	],

	'fields' => [
		// Maksymalny poziom zagnieżdżenia pól (0 = pole sekcji). 3 pozwala np. na
		// repeater → grupa → repeater → pole tekstowe.
		'max_depth' => 3,

		// Twardy limit elementów repeatera i zdjęć galerii (niezależnie od ustawień pola).
		'max_items' => 100,
	],

	'figma' => [
		// Stały adres API. Ścieżki budujemy wyłącznie ze zwalidowanego klucza pliku i ID warstwy (brak SSRF).
		'api_url' => 'https://api.figma.com/v1/',

		// Wspólne połączenie OAuth. `refresh_margin`: token odświeżamy tyle sekund przed wygaśnięciem.
		// Osadzanie prototypów (Embed Kit 2.0, developers.figma.com/docs/embeds). Adres osadzenia budujemy ze zwalidowanego
		// klucza pliku. Zdarzenia Embed API wymagają `client-id` aplikacji OAuth i originu strony dodanego w tej aplikacji
		// w Figmie; `embed_api` => false wyłącza dołączanie `client-id` (osadzenie działa dalej, bez zdarzeń).
		'embed' => [
			'url' => 'https://embed.figma.com/',
			'host' => 'fillmein',
			'event_origin' => 'https://www.figma.com',
			'frame_src' => 'https://embed.figma.com https://www.figma.com',
			'embed_api' => true,
		],

		'oauth' => [
			'authorize_url' => 'https://www.figma.com/oauth',
			'token_url' => 'https://api.figma.com/v1/oauth/token',
			'refresh_url' => 'https://api.figma.com/v1/oauth/refresh',
			'scope' => 'file_content:read',
			'refresh_margin' => 86400,
		],

		// Limity czasu jednego żądania w sekundach.
		'timeout' => 20,
		'connect_timeout' => 5,

		// Łączna liczba prób (1 = bez ponowień). Ponawiamy tylko 429, 5xx i błędy połączenia.
		'max_attempts' => 3,

		// Najdłuższe oczekiwanie na 429 (Retry-After) w sekundach. Dłuższa blokada = błąd bez czekania,
		// bo pobranie działa w żądaniu HTTP pracownika.
		'max_retry_after' => 10,

		// Jak długo metadane frame'u są świeże (w minutach) — wcześniej nie pytamy API ponownie.
		'cache_ttl_minutes' => 60,

		// Głębokość drzewa poniżej frame'u i twardy limit liczby warstw zapisywanych w cache.
		'node_depth' => 8,
		'max_nodes' => 5000,

		// Typy warstw, które mogą być frame'em podstrony (CANVAS = strona pliku, DOCUMENT = cały plik).
		'frame_types' => ['FRAME', 'SECTION', 'COMPONENT', 'COMPONENT_SET', 'INSTANCE', 'GROUP'],

		// Podglądy: frame i jego bezpośrednie dzieci (kandydaci na sekcje).
		'preview_format' => 'png',
		'preview_scale' => 1,
		'max_previews' => 40,

		// Adres obrazu z /v1/images jest tymczasowy (Figma: do 30 dni). Odświeżamy go wcześniej.
		'image_url_ttl_hours' => 24 * 14,

		// Hosty, z których wolno pobierać wyrenderowane obrazy (bez tokenu, tylko HTTPS, bez przekierowań).
		'image_hosts' => [
			'figma-alpha-api.s3.us-west-2.amazonaws.com',
			's3-alpha-sig.figma.com',
			's3-alpha.figma.com',
		],

		// Limity rozmiaru odpowiedzi w kilobajtach.
		'max_response_kb' => 30 * 1024,
		'max_image_kb' => 25 * 1024,

		// Analiza warstw i generowanie pól (etap 05). Nazwy warstw porównujemy jako słowa
		// (małe litery, bez znaków specjalnych). Każde wykluczenie pracownik może cofnąć w podglądzie importu.
		'analysis' => [
			'exclude_words' => [
				'navigation' => ['nav', 'navbar', 'navigation', 'nawigacja', 'topbar'],
				'footer' => ['footer', 'stopka'],
				'breadcrumbs' => ['breadcrumb', 'breadcrumbs', 'okruszki'],
				'technical' => ['guide', 'guides', 'grid', 'annotation', 'annotations', 'adnotacja', 'notatka', 'note', 'notes', 'komentarz', 'debug', 'redline', 'redlines', 'spec'],
			],

			// „header” i „menu” bywają też treścią (np. menu restauracji, nagłówek sekcji) — wykluczamy je
			// jako nawigację tylko dla niskich warstw (wysokość w px) blisko góry frame'u (odstęp w px).
			'navigation_weak_words' => ['header', 'menu'],
			'navigation_max_height' => 200,
			'navigation_top_offset' => 300,

			// Typy warstw, które nie są treścią strony.
			'technical_types' => ['SLICE', 'STICKY', 'CONNECTOR', 'WIDGET', 'SHAPE_WITH_TEXT', 'STAMP', 'EMBED', 'LINK_UNFURL'],

			// Tekst od tej długości (lub z podziałem wierszy) → textarea.
			'textarea_min_length' => 120,

			// Najkrótszy tekst przycisku/linku rozpoznawany po nazwie warstwy.
			'link_max_length' => 60,

			// Minimalna liczba powtórzeń o tej samej strukturze, aby zaproponować repeater
			// (karty) oraz liczba sąsiednich warstw tekstowych o tej samej nazwie (punkty listy).
			'min_repeat_items' => 2,
			'min_text_run' => 3,
			'icon_max_size' => 96,
		],
	],

	'assets' => [
		// Katalog prywatnego dysku `fmi`. Domyślnie poza katalogiem publicznym WordPressa
		// (obok ABSPATH, nie w wp-content/uploads). Nadpisanie: stała FMI_STORAGE_PATH w wp-config.php.
		'path' => defined('FMI_STORAGE_PATH') ? FMI_STORAGE_PATH : dirname(rtrim(ABSPATH, '/\\')) . '/fmi-storage',

		// Rozpoznawane po zawartości pliku (finfo), nie po rozszerzeniu. SVG celowo poza listą (ryzyko XSS).
		'mime_types' => [
			'image/jpeg' => 'jpg',
			'image/png' => 'png',
			'image/webp' => 'webp',
			'image/gif' => 'gif',
		],

		// Limit rozmiaru pliku w kilobajtach (nie może przekraczać upload_max_filesize / post_max_size PHP).
		'max_size_kb' => 15 * 1024,

		// Limit wymiarów (ochrona przed „bombami” pikselowymi).
		'max_dimension' => 12000,

		// Limit liczby pikseli: dekodowanie pliku do miniatury musi się zmieścić w memory_limit.
		'max_pixels' => 40000000,

		// Limit przesyłania na minutę z jednego adresu IP.
		'uploads_per_minute' => 60,

		// Czas (w sekundach) trzymania obrazu w prywatnym cache przeglądarki.
		'cache_seconds' => 300,

		// Podglądy generowane przez WP_Image_Editor po przesłaniu: [szerokość, wysokość, przycięcie].
		'variants' => [
			'thumb' => [400, 400, true],
			'preview' => [1600, 1600, false],
		],
	],

];

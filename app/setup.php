<?php

/**
 * Ustawienia motywu w WordPressie.
 *
 * Motyw nie ma publicznego frontu: WordPress służy jako zaplecze (konta pracowników, panel WP,
 * logowanie), a interfejs aplikacji obsługują trasy Acorna (/app, /p/{token}).
 */

namespace App;

/*--- FRONT WORDPRESSA → APLIKACJA ---*/

// Każdy widok frontu WP (strona główna, wpisy, archiwa, 404) przekierowuje do /app.
// Trasy aplikacji obsługuje Acorn wcześniej (parse_request), więc ich to nie dotyczy;
// panel WP, logowanie, REST API i cron nie przechodzą przez template_redirect.
add_action('template_redirect', function () {
	// Adresy magazynu nie przekierowują: plików tam nie ma, a próba dojścia do nich ma dostać zwykłą odmowę.
	$path = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);

	if (preg_match('~^/(fmi-storage|wp-content/uploads/fmi-private)(/|$)~', $path)) {
		status_header(404);
		nocache_headers();
		exit;
	}

	if (is_robots() || is_favicon()) {
		return;
	}

	wp_safe_redirect(home_url('/app'), 302);
	exit;
});

// Narzędzie wewnętrzne — bez indeksowania i bez map witryny.
add_filter('robots_txt', fn () => "User-agent: *\nDisallow: /\n", 99);
add_filter('wp_sitemaps_enabled', '__return_false');

/*--- MOTYW ---*/

add_action('after_setup_theme', function () {
	remove_theme_support('block-templates');
	remove_theme_support('core-block-patterns');
	add_theme_support('title-tag');
}, 20);

<?php

use Roots\Acorn\Application;

/*
|--------------------------------------------------------------------------
| Fill Me In — bootstrap motywu
|--------------------------------------------------------------------------
|
| Motyw jest aplikacją Fill Me In (Sage 11 + Acorn). Logika aplikacji:
| routes/web.php, app/, config/fillmein.php. Dokumentacja: PROJECT.md.
|
*/

if (! file_exists($composer = __DIR__ . '/vendor/autoload.php')) {
	wp_die(__('Error locating autoloader. Please run <code>composer install</code>.', 'fillmein'));
}

require $composer;

Application::configure()
	->withProviders([
		App\Providers\ThemeServiceProvider::class,
		App\Providers\FillMeInServiceProvider::class,
	])
	->withRouting(web: __DIR__ . '/routes/web.php')
	->withMiddleware(function (Roots\Acorn\Configuration\Middleware $middleware) {
		// CSRF dla wszystkich tras aplikacji (grupa `web` obejmuje wyłącznie trasy Acorna: /app, /p).
		$middleware->web(append: [
			Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
		], replace: [
			// Bez zapisu adresu /p/{token} w sesji (token klienta to sekret).
			Illuminate\Session\Middleware\StartSession::class => App\Http\Middleware\StartSession::class,
		]);

		$middleware->alias([
			'can' => Illuminate\Auth\Middleware\Authorize::class,
			'fmi.app' => App\Http\Middleware\ApplyAppDefaults::class,
			'fmi.staff' => App\Http\Middleware\EnsureStaff::class,
			'fmi.client' => App\Http\Middleware\ResolveClientAccess::class,
			'fmi.review-guest' => App\Http\Middleware\ResolveReviewGuest::class,
			'fmi.module' => App\Http\Middleware\EnsureProjectModule::class,
		]);
	})
	->boot();

collect(['setup'])
	->each(function ($file) {
		if (! locate_template($file = "app/{$file}.php", true, true)) {
			wp_die(
				/* translators: %s is replaced with the relative file path */
				sprintf(__('Error locating <code>%s</code> for inclusion.', 'fillmein'), $file)
			);
		}
	});

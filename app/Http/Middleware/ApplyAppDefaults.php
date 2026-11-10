<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wspólne ustawienia odpowiedzi aplikacji: locale walidacji i nagłówki bezpieczeństwa.
 *
 * Konteksty `client` (token w adresie) i `guest` (logowanie, klucz ustawienia hasła w adresie)
 * dostają `Referrer-Policy: no-referrer`, żeby sekret z URL nie wyciekł w nagłówku Referer.
 */
class ApplyAppDefaults
{
	public function handle(Request $request, Closure $next, string $context = 'staff'): Response
	{
		app()->setLocale(config('fillmein.locale', 'pl_PL'));

		$response = $next($request);

		$response->headers->set('X-Content-Type-Options', 'nosniff');
		$response->headers->set('X-Frame-Options', 'SAMEORIGIN');
		$response->headers->set('X-Robots-Tag', 'noindex, nofollow');
		$response->headers->set('Referrer-Policy', in_array($context, ['client', 'guest'], true) ? 'no-referrer' : 'same-origin');
		$response->headers->set('Cache-Control', $response instanceof BinaryFileResponse
			? 'private, max-age=' . (int) config('fillmein.assets.cache_seconds', 300)
			: 'no-store, private');

		return $response;
	}
}

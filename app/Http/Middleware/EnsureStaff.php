<?php

namespace App\Http\Middleware;

use App\Enums\AccountStatus;
use App\Support\Staff;
use Closure;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dostęp do /app wyłącznie dla zalogowanych użytkowników WP z capability `fmi_access`.
 *
 * - niezalogowany → /app/login (z informacją o wygaśnięciu sesji, jeśli przeglądarka miała
 *   ciasteczko logowania WP, które przestało być ważne),
 * - zalogowany bez dostępu lub z dezaktywowanym kontem → 403.
 *
 * Autoryzację konkretnych operacji sprawdzają Policy/Gate w kontrolerach i middleware `can`.
 *
 * Interfejs AuthenticatesRequests sprawia, że Laravel uruchamia to middleware przed wiązaniem
 * parametrów tras (SubstituteBindings) — anonimowy użytkownik nie odróżni istniejącego ID od
 * nieistniejącego (zawsze przekierowanie do logowania, nigdy 404).
 */
class EnsureStaff implements AuthenticatesRequests
{
	public function handle(Request $request, Closure $next): Response
	{
		if (! is_user_logged_in()) {
			$expired = defined('LOGGED_IN_COOKIE') && $request->cookies->has(LOGGED_IN_COOKIE);

			if ($request->expectsJson()) {
				return response()->json(['message' => 'Sesja wygasła. Zaloguj się ponownie.'], 401);
			}

			return redirect()->route('fmi.login', array_filter([
				'redirect_to' => $request->isMethod('GET') ? $request->getRequestUri() : null,
				'reason' => $expired ? 'expired' : null,
			]));
		}

		if (! Staff::current()) {
			abort(403, AccountStatus::isDeactivated(get_current_user_id())
				? 'Twoje konto Fill Me In zostało dezaktywowane. Skontaktuj się z administratorem.'
				: 'Twoje konto nie ma dostępu do Fill Me In.');
		}

		return $next($request);
	}
}

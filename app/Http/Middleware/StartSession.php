<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession as BaseStartSession;

/**
 * Sesja Laravela bez zapisu adresu tras klienta.
 *
 * Bazowy middleware zapisuje w sesji (`_previous.url`) pełny adres każdego żądania GET. Dla `/p/{token}`
 * oznaczałoby to token klienta jawnym tekstem w pliku sesji na dysku — a token jest sekretem
 * (w bazie trzymamy wyłącznie jego hash). Trasy `fmi.client.*` pomijamy.
 */
class StartSession extends BaseStartSession
{
	protected function storeCurrentUrl(Request $request, $session)
	{
		if (str_starts_with((string) $request->route()?->getName(), 'fmi.client.')) {
			return;
		}

		parent::storeCurrentUrl($request, $session);
	}
}

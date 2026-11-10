<?php

namespace App\Http\Middleware;

use App\Models\ClientAccess;
use App\Services\ClientAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rozwiązuje token klienta z adresu /p/{token} na aktywny rekord dostępu.
 *
 * Token jest usuwany z parametrów trasy zaraz po weryfikacji, żeby nie trafił dalej
 * (do kontrolerów, widoków, logów). Kontroler dostaje wyłącznie model ClientAccess
 * przez atrybut żądania `clientAccess`.
 */
class ResolveClientAccess
{
	public function __construct(private ClientAccessService $accesses)
	{
	}

	public function handle(Request $request, Closure $next): Response
	{
		$route = $request->route();
		$token = (string) $route->parameter('token');
		$route->forgetParameter('token');

		$access = $this->accesses->resolve($token);
		unset($token);

		if (! $access instanceof ClientAccess) {
			return response()->view('fillmein.client.invalid', [], 404);
		}

		$request->attributes->set('clientAccess', $access);

		return $next($request);
	}
}

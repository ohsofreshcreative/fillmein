<?php

namespace App\Http\Middleware;

use App\Services\ClientAccessService;
use App\Support\ReviewGuest;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sesja gościa recenzji (`/review/*`). Token linku został wymieniony na sesję w ReviewGuestController@enter,
 * więc adresy dalej go nie zawierają (logi, historia, Referer). Sesja trzyma wyłącznie ID linku i podaną nazwę;
 * link jest sprawdzany przy każdym żądaniu — po unieważnieniu albo wygaśnięciu kolejne żądania dostają 403.
 *
 * Wymagana możliwość linku (`comment`, `approve`) — parametr middleware, egzekwowany na serwerze.
 */
class ResolveReviewGuest
{
	public const SESSION_KEY = 'fmi_review_guest';

	public function __construct(private ClientAccessService $accesses)
	{
	}

	public function handle(Request $request, Closure $next, ?string $ability = null): Response
	{
		$session = $request->session()->get(self::SESSION_KEY);
		// `unlocked` = false: link z kodem dostępu, kod jeszcze nie podany (ReviewGuestController@unlock).
		$access = is_array($session) && isset($session['access']) && ($session['unlocked'] ?? false) === true
			? $this->accesses->activeReview((int) $session['access'])
			: null;

		if (! $access) {
			// Link czekający na kod zostaje w sesji (formularz /review/unlock); nieważny — usuwamy.
			if (! is_array($session) || ($session['unlocked'] ?? null) !== false) {
				$request->session()->forget(self::SESSION_KEY);
			}

			return $request->expectsJson()
				? response()->json(['message' => 'Link recenzji wygasł lub został unieważniony.'], 403)
				: response()->view('fillmein.client.invalid', [], 403);
		}

		$allowed = match ($ability) {
			null => true,
			'comment' => $access->can_comment,
			'approve' => $access->can_approve,
			default => false,
		};

		if (! $allowed) {
			return $request->expectsJson()
				? response()->json(['message' => 'Ten link recenzji nie pozwala na tę operację.'], 403)
				: response()->view('fillmein.client.review-forbidden', [], 403);
		}

		$name = is_string($session['name'] ?? null) ? $session['name'] : null;
		$request->attributes->set('reviewGuest', new ReviewGuest($access, $name));

		return $next($request);
	}
}

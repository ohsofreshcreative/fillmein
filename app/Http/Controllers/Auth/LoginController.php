<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Services\LoginThrottle;
use App\Support\Staff;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use WP_User;

/**
 * Logowanie do Fill Me In na natywnym uwierzytelnianiu WordPressa: `wp_authenticate()` weryfikuje
 * hasło (z filtrami `authenticate`, także limitem prób), a ciasteczka sesji WP ustawiamy dopiero
 * po sprawdzeniu dostępu do aplikacji — konto bez dostępu nie dostaje sesji. Hasła weryfikuje
 * WordPress; aplikacja niczego nie przechowuje. Limit prób: LoginThrottle
 * (działa także dla wp-login.php). Standardowe logowanie WordPressa pozostaje włączone.
 */
class LoginController extends Controller
{
	public function create(Request $request): View|RedirectResponse
	{
		if (Staff::current()) {
			return redirect()->to($this->target($request->query('redirect_to')));
		}

		return view('fillmein.auth.login', [
			'redirectTo' => $this->target($request->query('redirect_to')),
			'reason' => $request->query('reason'),
		]);
	}

	public function store(LoginRequest $request, LoginThrottle $throttle): RedirectResponse
	{
		$login = trim($request->string('login'));

		if ($throttle->locked($login)) {
			throw ValidationException::withMessages(['login' => $throttle->message($login)]);
		}

		$user = wp_authenticate($login, (string) $request->input('password'));

		if (! $user instanceof WP_User) {
			$code = $user->get_error_code();

			throw ValidationException::withMessages(['login' => match ($code) {
				'fmi_throttled', 'fmi_account_inactive' => $user->get_error_message(),
				default => 'Nieprawidłowy login, adres e-mail lub hasło.',
			}]);
		}

		if (! $user->has_cap(Staff::ACCESS_CAPABILITY)) {
			throw ValidationException::withMessages(['login' => AccountStatus::isDeactivated($user->ID)
				? 'Konto zostało dezaktywowane. Skontaktuj się z administratorem.'
				: 'To konto nie ma dostępu do Fill Me In.']);
		}

		// Odpowiednik końcówki wp_signon(): ciasteczka WP + akcja wp_login (data logowania, zerowanie limitu).
		wp_set_auth_cookie($user->ID, $request->boolean('remember'), is_ssl());
		wp_set_current_user($user->ID);
		do_action('wp_login', $user->user_login, $user);

		$request->session()->regenerate();

		return redirect()->to($this->target($request->input('redirect_to')));
	}

	public function destroy(Request $request): RedirectResponse
	{
		if (is_user_logged_in()) {
			wp_logout();
		}

		$request->session()->invalidate();
		$request->session()->regenerateToken();

		return redirect()->route('fmi.login')->with('status', 'Wylogowano.');
	}

	/**
	 * Przekierowanie po zalogowaniu tylko do adresów aplikacji (ochrona przed open redirect).
	 */
	private function target(mixed $redirectTo): string
	{
		$fallback = route('fmi.dashboard');

		if (! is_string($redirectTo) || $redirectTo === '' || str_starts_with($redirectTo, '//') || str_contains($redirectTo, '\\')) {
			return $fallback;
		}

		$path = (string) parse_url($redirectTo, PHP_URL_PATH);
		$host = parse_url($redirectTo, PHP_URL_HOST);

		if ($host !== null && $host !== parse_url(home_url(), PHP_URL_HOST)) {
			return $fallback;
		}

		$appPath = (string) parse_url(route('fmi.dashboard'), PHP_URL_PATH);

		if (! ($path === $appPath || str_starts_with($path, rtrim($appPath, '/') . '/')) || str_starts_with($path, rtrim($appPath, '/') . '/login')) {
			return $fallback;
		}

		return $path . (($query = parse_url($redirectTo, PHP_URL_QUERY)) ? '?' . $query : '');
	}
}

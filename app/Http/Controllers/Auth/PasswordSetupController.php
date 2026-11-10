<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\SetPasswordRequest;
use App\Services\ActivityLogger;
use App\Services\InvitationService;
use App\Support\Actor;
use App\Support\Staff;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use WP_Error;
use WP_User;

/**
 * Ustawienie hasła z linku w zaproszeniu — natywny klucz resetu hasła WordPressa.
 *
 * Po wejściu z linku klucz jest weryfikowany i przenoszony do sesji, a przeglądarka trafia
 * na adres bez klucza (jak w wp-login.php), żeby klucz nie został w historii i nagłówkach.
 */
class PasswordSetupController extends Controller
{
	private const SESSION_KEY = 'fmi_password_reset';

	public function __construct(private InvitationService $invitations)
	{
	}

	public function create(Request $request): View|RedirectResponse
	{
		if ($request->filled('key') && $request->filled('login')) {
			$request->session()->put(self::SESSION_KEY, [
				'login' => (string) $request->query('login'),
				'key' => (string) $request->query('key'),
			]);

			return redirect()->route('fmi.password.set');
		}

		$user = $this->verifiedUser($request);

		if (! $user) {
			return view('fillmein.auth.password-invalid');
		}

		return view('fillmein.auth.password-set', [
			'user' => $user,
			'minLength' => (int) config('fillmein.auth.password_min_length', 12),
		]);
	}

	public function store(SetPasswordRequest $request, ActivityLogger $activity): RedirectResponse
	{
		$user = $this->verifiedUser($request);

		if (! $user) {
			return redirect()->route('fmi.password.set');
		}

		reset_password($user, (string) $request->input('password'));
		update_user_meta($user->ID, AccountStatus::META_PASSWORD_SET_AT, current_time('mysql', true));
		$request->session()->forget(self::SESSION_KEY);

		$activity->log('user.password_set', Actor::user($user), null, $user);

		return redirect()->route('fmi.login')->with('status', 'Hasło zostało ustawione. Możesz się zalogować.');
	}

	private function verifiedUser(Request $request): ?WP_User
	{
		$reset = (array) $request->session()->get(self::SESSION_KEY, []);

		if (empty($reset['login']) || empty($reset['key'])) {
			return null;
		}

		$candidate = get_user_by('login', $reset['login']);

		if (! $candidate instanceof WP_User || ! Staff::isAppUser($candidate)) {
			return null;
		}

		$expiration = fn () => $this->invitations->expirationFor($candidate);
		add_filter('password_reset_expiration', $expiration);
		$user = check_password_reset_key($reset['key'], $reset['login']);
		remove_filter('password_reset_expiration', $expiration);

		return $user instanceof WP_Error ? null : $user;
	}
}
